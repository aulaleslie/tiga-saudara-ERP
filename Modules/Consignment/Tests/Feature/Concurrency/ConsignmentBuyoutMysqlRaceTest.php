<?php

namespace Modules\Consignment\Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Consignment\Tests\Support\BuildsFinalizedBuyout;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\PaymentMethod;
use Tests\TestCase;

/**
 * Real concurrent OS processes against the isolated MySQL 8.0.44 database (REPEATABLE-READ, InnoDB row locks), each
 * calling the production service/controller with no harness retries. Invariants are asserted on committed data, so
 * every test holds for either winner order. Data is committed (workers must see it) and truncated afterwards.
 *
 * @group mysql
 */
class ConsignmentBuyoutMysqlRaceTest extends TestCase
{
    use BuildsBuyoutFixtures;
    use BuildsFinalizedBuyout;
    use \Modules\Consignment\Tests\Support\RunsBuyoutRaceWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mysql_test') {
            $this->markTestSkipped('Requires the isolated MySQL test database (phpunit.mysql.xml / scripts/run-mysql-sequence-tests.sh).');
        }
        $this->truncateAll();
        $this->setUpFinalizationFixtures();
    }

    protected function tearDown(): void
    {
        if (config('database.default') === 'mysql_test') {
            $this->truncateAll();
        }
        parent::tearDown();
    }

    private function draftFor(\Modules\Consignment\Entities\ConsignmentReceivingDetail $r, string $qty, $supplier = null): ConsignmentBuyout
    {
        $b = $this->life->createDraft($this->setting->id, ($supplier ?? $this->supplier)->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id, 'destination_location_id' => $this->dest1->id,
            'base_quantity' => $qty, 'buckets' => ['NON_TAXED' => $qty]]], $this->user, $this->meta());

        return $this->life->submit($b->fresh(), $this->user);
    }

    /** @test 1.4: concurrent first-time creation of the same coordination row */
    public function concurrent_coordination_row_creation_yields_one_row_and_no_errors(): void
    {
        $jobs = array_fill(0, 6, ['coord', $this->product->id]);
        $results = $this->race($jobs);

        $this->assertSame(['ok'], array_unique($results), implode('|', $results));
        $this->assertSame(1, DB::table('consignment_inventory_coordinations')
            ->where('setting_id', $this->setting->id)->where('product_id', $this->product->id)->count());
    }

    /** @test 3.6: two buyouts compete for the same receipt capacity */
    public function buyout_versus_buyout_approval_cannot_oversubscribe_one_receipt(): void
    {
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $a = $this->draftFor($r, '10');
        $b = $this->draftFor($r, '10');

        $results = $this->race([['approve', $a->id], ['approve', $b->id]]);

        $this->assertSame(1, $this->ok($results), 'exactly one approval wins: ' . implode(' | ', $results));
        $this->assertSame(1, ConsignmentBuyout::where('status', 'APPROVED')->count());
        $this->assertSame(1, ConsignmentBuyout::where('status', 'WAITING_APPROVAL')->count(), 'the loser is untouched');
        $reserved = (float) DB::table('consignment_buyout_reservations')->where('status', 'ACTIVE')->where('resource_type', 'RECEIPT')->sum('base_quantity');
        $this->assertEquals(10.0, $reserved, 'reservations never exceed receipt capacity: ' . implode(' | ', $results));
    }

    /** @test 6.3: duplicate concurrent finalization posts one complete set */
    public function competing_finalizations_create_one_purchase_and_one_posting_set(): void
    {
        [$b] = $this->mixed(null, 150.0); // raises the carrying value so exactly one ledger event is expected
        $results = $this->race([['finalize', $b->id], ['finalize', $b->id], ['finalize', $b->id]]);

        $this->assertSame(3, $this->ok($results), 'idempotent replay returns success for the losers: ' . implode(' | ', $results));
        $this->assertCount(1, array_unique($results), 'all callers see the same Purchase: ' . implode(' | ', $results));
        $counts = $this->postingCounts();
        $this->assertSame(1, $counts['purchases']);
        $this->assertSame($b->lines()->count(), $counts['lineage'], 'one lineage row per buyout line, posted once');
        $this->assertSame(1, $counts['finalized_audit']);
        $this->assertSame(2, $counts['movements'], 'one unsold line = one source debit + one destination credit, posted once');
        $this->assertSame($counts['movements'], $counts['txn'], 'one transaction row per movement');
        $this->assertSame(1, (int) DB::table('consignment_buyout_cost_events')->count());
        $this->assertSame('FINALIZED', $b->fresh()->status);
        $events = DB::table('product_price_cost_events')->where('product_id', $this->product->id)->orderBy('id')->get(['old_average', 'new_average'])->toJson();
        $this->assertSame(2, DB::table('product_price_cost_events')->where('product_id', $this->product->id)->count(), 'fixture insert plus exactly one carrying-value change: ' . $events);
    }

    /** @test 8.5: payment versus reversal; at most one commits */
    public function payment_versus_reversal_never_both_commit(): void
    {
        $histogram = [];
        for ($round = 0; $round < 6; $round++) {
            $this->truncateAll();
            $this->setUpFinalizationFixtures();
            [$b] = $this->mixed();
            $purchase = $this->fin->finalize($b, $this->user);
            $coa = \Modules\Setting\Entities\ChartOfAccount::create(['setting_id' => $this->setting->id, 'account_number' => 'COA-' . uniqid(), 'name' => 'Cash', 'category' => 'Kas & Bank']);
            $method = PaymentMethod::create(['name' => 'Bank Transfer', 'coa_id' => $coa->id, 'is_active' => true]);
            $results = $this->race([['reverse', $b->id], ['pay', $purchase->id, (string) $method->id]], $round % 2 ? [150, 0] : [0, 150]);

            $reversed = $b->fresh()->status === 'REVERSED';
            $paid = DB::table('purchase_payments')->where('purchase_id', $purchase->id)->count();
            $this->assertFalse($reversed && $paid > 0, 'both a payment and a reversal committed: ' . implode(' | ', $results));
            $this->assertSame(1, $reversed ? 1 : $paid, 'exactly one incompatible operation committed: ' . implode(' | ', $results));
            $this->assertSame(1, $this->ok($results), implode(' | ', $results));
            $histogram[$reversed ? 'reversal-won' : 'payment-won'] = ($histogram[$reversed ? 'reversal-won' : 'payment-won'] ?? 0) + 1;
            if ($reversed) {
                $this->assertSame('CANCELLED', $purchase->fresh()->status);
            }
        }
        fwrite(STDERR, "\n[payment-vs-reversal outcomes] " . json_encode($histogram) . "\n");
    }

    /** @test 8.5: duplicate reversal */
    public function competing_reversals_reverse_once(): void
    {
        [$b] = $this->mixed();
        $this->fin->finalize($b, $this->user);
        $before = DB::table('consignment_buyout_reversals')->count();
        $results = $this->race([['reverse', $b->id], ['reverse', $b->id]]);

        $this->assertGreaterThanOrEqual(1, $this->ok($results), implode(' | ', $results));
        $this->assertSame($before + 1, DB::table('consignment_buyout_reversals')->count(), 'one reversal record: ' . implode(' | ', $results));
        $this->assertSame('REVERSED', $b->fresh()->status);
    }

    /** @test 3.6: real purchase-return dispatch approval versus buyout approval over the same goods */
    public function purchase_return_dispatch_versus_buyout_approval_cannot_both_take_the_goods(): void
    {
        $outcomes = [];
        for ($round = 0; $round < 6; $round++) {
            if ($round > 0) {
                $this->truncateAll();
                $this->setUpFinalizationFixtures();
            }
            $outcomes[] = $this->purchaseReturnRound($round);
        }
        fwrite(STDERR, "\n[purchase-return-vs-approval outcomes] " . json_encode(array_count_values($outcomes)) . "\n");
    }

    private function purchaseReturnRound(int $round): string
    {
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $b = $this->draftFor($r, '10');

        $return = \Modules\PurchasesReturn\Entities\PurchaseReturn::create(['date' => now(), 'reference' => 'PR-RACE', 'setting_id' => $this->setting->id,
            'location_id' => $this->location->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'S', 'payment_method' => 'Cash',
            'paid_amount' => 0, 'total_amount' => 1000, 'due_amount' => 1000, 'status' => 'Pending', 'approval_status' => 'approved',
            'payment_status' => 'Unpaid', 'return_dispatch_status' => 'pending_approval']);
        \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create(['purchase_return_id' => $return->id, 'product_id' => $this->product->id,
            'product_name' => 'W', 'product_code' => 'W-BO', 'quantity' => 10, 'location_id' => $this->location->id, 'price' => 100,
            'unit_price' => 100, 'sub_total' => 1000, 'product_discount_amount' => 0, 'product_discount_type' => 'fixed', 'product_tax_amount' => 0]);

        $results = $this->race([['approve', $b->id], ['http', route('purchase-returns.dispatch-approve', $return->id, false)]], $round % 2 ? [200, 0] : [0, 200]);

        $stock = (float) ProductStock::where('product_id', $this->product->id)->where('location_id', $this->location->id)->value('quantity_non_tax');
        $approved = $b->fresh()->status === 'APPROVED';
        $dispatched = $stock < 10;
        $this->assertFalse($approved && $dispatched, "reserved goods were dispatched (stock {$stock}): " . implode(' | ', $results));
        $this->assertTrue($approved || $dispatched, 'one of the two operations must have won: ' . implode(' | ', $results));
        $this->assertGreaterThanOrEqual(0.0, $stock);
        if ($approved) {
            $this->assertEquals(10.0, $stock, 'the refused dispatch changed nothing');
        } else {
            $this->assertEquals(0, DB::table('consignment_buyout_reservations')->where('status', 'ACTIVE')->count(), 'the refused approval reserved nothing');
        }

        return $approved ? 'approval-won' : 'dispatch-won';
    }

    /** @test 3.6/8.5: real receiving cancellation (removes goods) versus buyout approval (reserves the same goods) */
    public function receiving_cancellation_versus_buyout_approval_cannot_both_succeed(): void
    {
        $outcomes = [];
        for ($round = 0; $round < 6; $round++) {
            if ($round > 0) {
                $this->truncateAll();
                $this->setUpFinalizationFixtures();
            }
            $outcomes[] = $this->receivingCancellationRound($round);
        }
        fwrite(STDERR, "\n[receiving-cancel-vs-approval outcomes] " . json_encode(array_count_values($outcomes)) . "\n");
    }

    private function receivingCancellationRound(int $round): string
    {
        // 2 units already on hand; an ordinary purchase receiving adds 10 => 12 physically present; the receipt covers 10.
        $r = $this->receipt($this->supplier, '10');
        $this->stock('2');
        \Spatie\Permission\Models\Permission::findOrCreate('purchases.receive', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('purchases.receive.approval', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('purchases.receive.cancel', 'web');
        $this->user->givePermissionTo(['purchases.receive', 'purchases.receive.approval', 'purchases.receive.cancel']);
        $purchase = \Modules\Purchase\Entities\Purchase::create(['date' => now(), 'due_date' => now()->addDays(30), 'reference' => 'PO-RACE', 'supplier_id' => $this->supplier->id,
            'supplier_name' => 'S', 'status' => \Modules\Purchase\Entities\Purchase::STATUS_APPROVED, 'payment_status' => 'Unpaid', 'payment_method' => 'Cash',
            'total_amount' => 1000, 'paid_amount' => 0, 'due_amount' => 1000, 'setting_id' => $this->setting->id]);
        $detail = \Modules\Purchase\Entities\PurchaseDetail::create(['purchase_id' => $purchase->id, 'product_id' => $this->product->id, 'product_name' => 'W',
            'product_code' => 'W-BO', 'quantity' => 10, 'price' => 100, 'unit_price' => 100, 'sub_total' => 1000, 'product_discount_amount' => 0, 'product_tax_amount' => 0]);
        $note = \Modules\Purchase\Entities\ReceivedNote::create(['po_id' => $purchase->id, 'date' => now(), 'location_id' => $this->location->id,
            'status' => \Modules\Purchase\Entities\ReceivedNote::STATUS_PENDING]);
        \Modules\Purchase\Entities\ReceivedNoteDetail::create(['received_note_id' => $note->id, 'quantity_received' => 10, 'po_detail_id' => $detail->id]);
        // Ordinary receiving refuses consignment locations, so the only way a cancellation can reach reserved stock is a
        // location reclassified AFTER receiving: receive while standard, then flag it consignment (defence in depth).
        DB::table('locations')->where('id', $this->location->id)->update(['is_consignment' => false]);
        $this->actingAs($this->user)->withSession(['setting_id' => $this->setting->id])->post(route('receivings.approve', $note->id))->assertSessionHasNoErrors();
        DB::table('locations')->where('id', $this->location->id)->update(['is_consignment' => true]);
        $this->assertEquals(12.0, (float) ProductStock::where('product_id', $this->product->id)->value('quantity_non_tax'), 'fixture: receiving posted');

        $b = $this->draftFor($r, '10');
        $cancel = route('purchases.receivings.cancel', $purchase->id, false);
        $results = $this->race([['approve', $b->id], ['http', $cancel, json_encode(['reason' => 'race cancel'])]], $round % 2 ? [200, 0] : [0, 200]);

        $stock = (float) ProductStock::where('product_id', $this->product->id)->where('location_id', $this->location->id)->value('quantity_non_tax');
        $approved = $b->fresh()->status === 'APPROVED';
        $cancelled = $stock < 12;
        $this->assertFalse($approved && $stock < 10, "reserved goods were removed (stock {$stock}): " . implode(' | ', $results));
        $this->assertTrue($approved || $cancelled, 'one of the two operations must have won: ' . implode(' | ', $results));
        if ($approved) {
            $this->assertEquals(12.0, $stock, 'the refused cancellation changed nothing');
        } else {
            $this->assertEquals(0, DB::table('consignment_buyout_reservations')->where('status', 'ACTIVE')->count(), 'the refused approval reserved nothing');
        }

        return $approved ? 'approval-won' : 'cancellation-won';
    }

    /** @test negative control for the sale-dispatch race: without a buyout the dispatch worker really takes the goods */
    public function sale_dispatch_alone_takes_the_goods_in_a_worker(): void
    {
        $this->saleDispatchRound(0, false);
    }

    /** @test 3.6: real sale dispatch approval (goods leave the consignment location) versus buyout approval */
    public function sale_dispatch_approval_versus_buyout_approval_cannot_both_take_the_goods(): void
    {
        $outcomes = [];
        for ($round = 0; $round < 6; $round++) {
            if ($round > 0) {
                $this->truncateAll();
                $this->setUpFinalizationFixtures();
            }
            $outcomes[] = $this->saleDispatchRound($round);
        }
        fwrite(STDERR, "\n[sale-dispatch-vs-approval outcomes] " . json_encode(array_count_values($outcomes)) . "\n");
    }

    private function saleDispatchRound(int $round, bool $withBuyout = true): string
    {
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        DB::table('products')->where('id', $this->product->id)->update(['stock_managed' => 1]);   // dispatch only moves stock of managed products
        foreach (['sales.dispatch', 'salesDispatches.approval'] as $perm) {
            \Spatie\Permission\Models\Permission::findOrCreate($perm, 'web');
            $this->user->givePermissionTo($perm);
        }
        $term = \Modules\Purchase\Entities\PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);
        $customer = \Modules\People\Entities\Customer::factory()->create(['setting_id' => $this->setting->id, 'payment_term_id' => $term->id]);
        $sale = \Modules\Sale\Entities\Sale::create(['date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(), 'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name, 'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0,
            'shipping_amount' => 0, 'total_amount' => 15000, 'paid_amount' => 0, 'due_amount' => 15000, 'status' => 'Approved', 'payment_status' => 'Unpaid',
            'payment_method' => 'cash', 'payment_term_id' => $term->id, 'setting_id' => $this->setting->id, 'is_tax_included' => false, 'reference' => 'SO-RACE-' . $round]);
        \Modules\Sale\Entities\SaleDetails::create(['sale_id' => $sale->id, 'product_id' => $this->product->id, 'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code, 'quantity' => 10, 'price' => 1500, 'unit_price' => 1500, 'sub_total' => 15000,
            'product_discount_amount' => 0, 'product_discount_type' => 'fixed', 'product_tax_amount' => 0, 'tax_id' => null]);
        $key = $this->product->id . '--0';
        $this->actingAs($this->user)->withSession(['setting_id' => $this->setting->id])->post(route('sales.storeDispatch', $sale), [
            'dispatch_date' => now()->toDateString(), 'dispatchedQuantities' => [$key => 10], 'selectedLocations' => [$key => $this->location->id],
        ])->assertRedirect();
        $dispatch = \Modules\Sale\Entities\Dispatch::where('sale_id', $sale->id)->latest('id')->firstOrFail();
        if (! $withBuyout) {
            // Negative control: with no buyout the same real dispatch approval, in a worker, succeeds and takes the goods.
            $results = $this->race([['http', route('dispatches.approve', $dispatch->id, false)]]);
            $this->assertSame(1, $this->ok($results), 'dispatch alone must succeed: ' . implode(' | ', $results));
            $this->assertEquals(0.0, (float) ProductStock::where('product_id', $this->product->id)->value('quantity_non_tax'));

            return 'control';
        }
        $b = $this->draftFor($r, '10');

        $results = $this->race([['approve', $b->id], ['http', route('dispatches.approve', $dispatch->id, false)]], $round % 2 ? [200, 0] : [0, 200]);

        $stock = (float) ProductStock::where('product_id', $this->product->id)->where('location_id', $this->location->id)->value('quantity_non_tax');
        $approved = $b->fresh()->status === 'APPROVED';
        $dispatched = $stock < 10;
        $this->assertFalse($approved && $dispatched, "reserved goods were dispatched (stock {$stock}): " . implode(' | ', $results));
        $this->assertTrue($approved || $dispatched, 'one of the two operations must have won: ' . implode(' | ', $results));
        $this->assertGreaterThanOrEqual(0.0, $stock);
        if ($approved) {
            $this->assertEquals(10.0, $stock, 'the refused dispatch changed nothing');
        } else {
            $this->assertEquals(0, DB::table('consignment_buyout_reservations')->where('status', 'ACTIVE')->count(), 'the refused approval reserved nothing');
        }

        return $approved ? 'approval-won' : 'dispatch-won';
    }

    /** @test negative control: with no buyout the consignment receiving reversal worker really reverses */
    public function consignment_receiving_reversal_alone_reverses_in_a_worker(): void
    {
        $this->assertSame('control', $this->receivingReversalRound(0, false));
    }

    /** @test 8.5: consignment receiving reversal (physically removes the receipt) versus buyout approval over that receipt */
    public function consignment_receiving_reversal_versus_buyout_approval_cannot_both_succeed(): void
    {
        $outcomes = [];
        for ($round = 0; $round < 6; $round++) {
            if ($round > 0) {
                $this->truncateAll();
                $this->setUpFinalizationFixtures();
            }
            $outcomes[] = $this->receivingReversalRound($round, true);
        }
        fwrite(STDERR, "\n[receiving-reversal-vs-approval outcomes] " . json_encode(array_count_values($outcomes)) . "\n");
    }

    private function receivingReversalRound(int $round, bool $withBuyout): string
    {
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        \Spatie\Permission\Models\Permission::findOrCreate('consignments.reverse', 'web');
        $this->user->givePermissionTo('consignments.reverse');
        $receivingId = (int) $r->consignment_receiving_id;
        $reverse = route('consignments.receivings.reverse', $receivingId, false);
        $params = json_encode(['reversal_reason' => 'race reversal']);

        if (! $withBuyout) {
            $results = $this->race([['http', $reverse, $params]]);
            $this->assertSame(1, $this->ok($results), implode(' | ', $results));
            $this->assertSame('REVERSED', strtoupper((string) DB::table('consignment_receivings')->where('id', $receivingId)->value('status')), 'control: ' . implode(' | ', $results));

            return 'control';
        }

        $b = $this->draftFor($r, '10');
        $results = $this->race([['approve', $b->id], ['http', $reverse, $params]], $round % 2 ? [200, 0] : [0, 200]);

        $approved = $b->fresh()->status === 'APPROVED';
        $reversed = strtoupper((string) DB::table('consignment_receivings')->where('id', $receivingId)->value('status')) === 'REVERSED';
        $this->assertFalse($approved && $reversed, 'a buyout is APPROVED against a REVERSED receiving: ' . implode(' | ', $results));
        $this->assertTrue($approved || $reversed, 'one of the two must have won: ' . implode(' | ', $results));
        if ($approved) {
            $this->assertEquals(10.0, (float) ProductStock::where('product_id', $this->product->id)->value('quantity_non_tax'), 'the refused reversal changed no stock');
        } else {
            $this->assertEquals(0, DB::table('consignment_buyout_reservations')->where('status', 'ACTIVE')->count(), 'the refused approval reserved nothing');
        }

        return $approved ? 'approval-won' : 'reversal-won';
    }

    /** @test negative control: the legacy-submit worker really puts the confirmation into WAITING_APPROVAL */
    public function legacy_confirmation_submit_alone_submits_in_a_worker(): void
    {
        [$conf] = $this->legacyFixture();
        $results = $this->race([['legacy', $conf->id]]);
        $this->assertSame(1, $this->ok($results), implode(' | ', $results));
        $this->assertSame('WAITING_APPROVAL', strtoupper($conf->fresh()->status));
    }

    /** @test 3.6: legacy billing allocation (submit) versus buyout approval over the same sold units */
    public function legacy_allocation_versus_buyout_approval_cannot_both_claim_the_sold_units(): void
    {
        $outcomes = [];
        for ($round = 0; $round < 6; $round++) {
            if ($round > 0) {
                $this->truncateAll();
                $this->setUpFinalizationFixtures();
            }
            [$conf, $b, $src] = $this->legacyFixture();
            $results = $this->race([['approve', $b->id], ['legacy', $conf->id]], $round % 2 ? [250, 0] : [0, 250]);

            $approved = $b->fresh()->status === 'APPROVED';
            $waiting = strtoupper($conf->fresh()->status) === 'WAITING_APPROVAL';
            $this->assertFalse($approved && $waiting, 'the same 6 sold units are claimed twice: ' . implode(' | ', $results));
            $this->assertTrue($approved || $waiting, 'one of the two must have won: ' . implode(' | ', $results));
            $cap = (new \Modules\Consignment\Services\ConsignmentBuyoutCapacityService())->soldSourceCapacity($src->fresh());
            $this->assertGreaterThanOrEqual(0.0, (float) $cap['available'], 'sold source over-consumed: ' . json_encode($cap));
            if ($approved) {
                $this->assertSame('DRAFT', strtoupper($conf->fresh()->status), 'the refused legacy submit stayed a draft');
            } else {
                $this->assertSame(0, DB::table('consignment_buyout_reservations')->where('status', 'ACTIVE')->count(), 'the refused approval reserved nothing');
            }
            $outcomes[] = $approved ? 'approval-won' : 'legacy-won';
        }
        fwrite(STDERR, "\n[legacy-vs-approval outcomes] " . json_encode(array_count_values($outcomes)) . "\n");
    }

    /** @return array{0: \Modules\Consignment\Entities\ConsignmentBillingConfirmation, 1: ConsignmentBuyout, 2: \Modules\Consignment\Entities\ConsignmentSoldSource} */
    private function legacyFixture(): array
    {
        $r = $this->receipt($this->supplier, '10');
        $this->price($r, 100);
        $this->stock('4');
        $src = $this->soldSource('6');
        $this->capacity()->setAttribution($src->id, [$r->id => '6'], null, 'attribute');
        $legacy = app(\Modules\Consignment\Services\ConsignmentBillingConfirmationLifecycleService::class);
        $conf = $legacy->createDraft($this->setting->id, $this->supplier->id, date('Y-m-d'), [[
            'consignment_sold_source_id' => $src->id, 'product_id' => $this->product->id, 'location_id' => $this->location->id,
            'allocated_base_quantity' => '6',
            'receipt_allocations' => [['consignment_receiving_detail_id' => $r->id, 'allocated_base_quantity' => '6']],
        ]], 'n', null);
        $b = $this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'SOLD_UNBILLED', 'consignment_receiving_detail_id' => $r->id, 'consignment_sold_source_id' => $src->id,
            'destination_location_id' => $this->dest1->id, 'base_quantity' => '6']], $this->user, $this->meta());
        $b = $this->life->submit($b->fresh(), $this->user);

        return [$conf, $b, $src];
    }

    /**
     * @test attribution editing versus buyout approval over the same sold source. The edit's dependency check must
     * see a reservation committed while it waited for the coordination lock (REPEATABLE READ snapshot hazard).
     */
    public function attribution_edit_versus_buyout_approval_cannot_both_commit(): void
    {
        $outcomes = [];
        foreach ([[0, 40], [0, 120], [40, 0], [120, 0], [0, 80], [80, 0]] as $round => $delays) {
            if ($round > 0) {
                $this->truncateAll();
                $this->setUpFinalizationFixtures();
            }
            $r = $this->receipt($this->supplier, '10');
            $r2 = $this->receipt($this->supplier, '10');
            $this->price($r, 100);
            $this->price($r2, 100);
            $this->stock('14');
            $src = $this->soldSource('6');
            $this->capacity()->setAttribution($src->id, [$r->id => '6'], null, 'attribute');
            $b = $this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [[
                'line_type' => 'SOLD_UNBILLED', 'consignment_receiving_detail_id' => $r->id, 'consignment_sold_source_id' => $src->id,
                'destination_location_id' => $this->dest1->id, 'base_quantity' => '6']], $this->user, $this->meta());
            $b = $this->life->submit($b->fresh(), $this->user);

            $results = $this->race([
                ['approve', $b->id],
                ['attribute', $src->id, json_encode([$r->id => '3', $r2->id => '3'])],
            ], $delays);

            $approved = $b->fresh()->status === 'APPROVED';
            $attr = DB::table('consignment_sold_receipt_attributions')->where('consignment_sold_source_id', $src->id)
                ->pluck('attributed_base_quantity', 'consignment_receiving_detail_id')->map(fn ($q) => (float) $q)->all();
            $edited = ($attr[$r->id] ?? 0.0) !== 6.0;
            $this->assertFalse($approved && $edited, 'approval reserved against an attribution that was changed underneath it: '
                . json_encode($attr) . ' ' . implode(' | ', $results));
            $this->assertTrue($approved || $edited, 'one of the two must have won: ' . implode(' | ', $results));
            $outcomes[] = $approved ? 'approval-won' : 'edit-won';
        }
        fwrite(STDERR, "\n[attribution-vs-approval outcomes] " . json_encode(array_count_values($outcomes)) . "\n");
    }
}
