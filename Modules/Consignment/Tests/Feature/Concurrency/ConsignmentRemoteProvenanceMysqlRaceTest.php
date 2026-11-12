<?php

namespace Modules\Consignment\Tests\Feature\Concurrency;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Services\TransferV3AllocationService;
use Modules\Adjustment\Services\TransferV3GoodsService;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentObligationBinding;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentBillingConfirmationLifecycleService;
use Modules\Consignment\Services\ConsignmentBuyoutFinalizationService;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\Consignment\Tests\Support\BuildsRemoteProvenanceFixtures;
use Modules\Consignment\Tests\Support\RunsBuyoutRaceWorkers;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\PaymentMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * track-transferred-consignment-provenance 6.5: real OS-process races on the isolated MySQL database for cross-business
 * provenance. Each scenario runs in both winner orders; workers call the real production services.
 *
 * @group mysql
 */
class ConsignmentRemoteProvenanceMysqlRaceTest extends TestCase
{
    use BuildsRemoteProvenanceFixtures;
    use RunsBuyoutRaceWorkers;

    protected User $user;
    protected $setting;

    private const PERMS = ['consignments.buyouts.create', 'consignments.buyouts.edit', 'consignments.buyouts.submit', 'consignments.buyouts.approve',
        'consignments.buyouts.cancel', 'consignments.buyouts.acquire_unsold', 'consignments.allocations.approve', 'consignments.buyouts.finalize',
        'consignments.buyouts.reverse', 'consignments.billing.convert', 'consignments.buyouts.acquire_remote', 'consignments.allocations.create'];

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mysql_test') {
            $this->markTestSkipped('Requires the isolated MySQL test database (phpunit.mysql.xml / scripts/run-mysql-sequence-tests.sh).');
        }
    }

    protected function tearDown(): void
    {
        if (config('database.default') === 'mysql_test') {
            $this->truncateAll();
        }
        parent::tearDown();
    }

    private function build(): void
    {
        $this->truncateAll();
        $this->sn = [];
        $this->serials = [];
        $this->n = 0;
        $this->setUpRemoteFixtures();
        config(['consignment.buyouts.finalization_enabled' => true]);
        $this->grant($this->actor, self::PERMISSIONS);
        foreach (self::PERMS as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
            $this->actor->givePermissionTo($p);
        }
        foreach ([$this->businessA, $this->businessB] as $s) {
            $this->actor->settings()->syncWithoutDetaching([$s->id => ['role_id' => Role::findOrCreate('RC', 'web')->id]]);
            ProductPrice::create(['product_id' => $this->serialized->id, 'setting_id' => $s->id, 'sale_price' => 1, 'last_purchase_price' => 1, 'average_purchase_price' => 100]);
        }
        $this->user = $this->actor;
        $this->setting = $this->businessA;
        $this->moveSerial($this->sn[1], $this->ca, $this->cb);
    }

    private function remoteBuyout(): ConsignmentBuyout
    {
        $life = app(ConsignmentBuyoutLifecycleService::class);

        return $life->submit($life->createDraft($this->businessA->id, $this->supplier->id, now()->toDateString(), [
            ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $this->receipt->id, 'source_location_id' => $this->cb->id,
                'destination_location_id' => $this->b1->id, 'base_quantity' => '1', 'serial_ids' => [$this->sn[1]->id]],
        ], $this->actor, ['invoice_metadata' => ['invoice_date' => now()->toDateString(), 'supplier_invoice_number' => 'RACE-' . uniqid()]]), $this->actor);
    }

    private function orders(int $round): array
    {
        return $round % 2 ? [150, 0] : [0, 150];
    }

    /** @test remote serial transfer versus remote buyout approval over the same serial */
    public function remote_transfer_versus_remote_buyout_never_both_take_the_serial(): void
    {
        $hist = [];
        for ($round = 0; $round < 4; $round++) {
            $this->build();
            $buyout = $this->remoteBuyout();
            session(['setting_id' => $this->businessB->id]);
            $t = app(TransferV3GoodsService::class)->createAndSubmit(Transfer::CONDITION_GOOD,
                [['product_id' => $this->serialized->id, 'quantity' => 1, 'serial_ids' => [$this->sn[1]->id]]], $this->actor, $this->businessB->id);
            $t->refresh();
            app(TransferV3AllocationService::class)->saveProgress($t, $this->actor, $this->businessB->id, (int) $t->current_request_revision_id,
                (int) $t->approval_configuration_revision, ["{$this->serialized->id}:{$this->cb->id}" => $this->ca->id], []);

            $this->setting = $this->businessB;
            $results = $this->race([['transfer', $t->id], ['approve', $buyout->id]], $this->orders($round));

            $moved = $t->fresh()->status === Transfer::STATUS_DISPATCHED;
            $approved = $buyout->fresh()->status === ConsignmentBuyout::STATUS_APPROVED;
            $this->assertFalse($moved && $approved, 'both took the serial: ' . implode(' | ', $results));
            $this->assertSame(1, $this->ok($results), implode(' | ', $results));
            $hist[$moved ? 'transfer' : 'buyout'] = ($hist[$moved ? 'transfer' : 'buyout'] ?? 0) + 1;
        }
        fwrite(STDERR, "\n[remote transfer-vs-buyout] " . json_encode($hist) . "\n");
    }

    /** @test origin and remote manual discovery at once create one envelope and one binding */
    public function concurrent_origin_and_remote_discovery_is_idempotent(): void
    {
        for ($round = 0; $round < 4; $round++) {
            $this->build();
            $dd = $this->sellAt($this->cb, [$this->sn[1]]);
            $results = $this->race([['discover', $this->businessA->id], ['discover', $this->businessB->id]], $this->orders($round));

            $this->assertSame(2, $this->ok($results), implode(' | ', $results));
            $this->assertSame(1, ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->count(), implode(' | ', $results));
            $this->assertSame(1, ConsignmentObligationBinding::count(), 'control: the remote binding was actually posted');
        }
    }

    /** @test legacy billing of a remote share versus a buyout taking the same remote sold share */
    public function legacy_and_buyout_never_both_consume_one_remote_share(): void
    {
        $hist = [];
        for ($round = 0; $round < 4; $round++) {
            $this->build();
            $dd = $this->sellAt($this->cb, [$this->sn[1]]);
            app(\Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService::class)->discoverForSetting($this->businessA->id);
            $src = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();
            $line = ['consignment_sold_source_id' => $src->id, 'allocated_base_quantity' => 1,
                'receipt_allocations' => [['consignment_receiving_detail_id' => $this->receipt->id, 'allocated_base_quantity' => 1]],
                'serialized_allocations' => [['product_serial_number_id' => $this->sn[1]->id, 'consignment_receiving_detail_id' => $this->receipt->id]]];
            $c = app(ConsignmentBillingConfirmationLifecycleService::class)->createDraft($this->businessA->id, $this->supplier->id, now()->toDateString(), [$line], null, $this->actor->id);
            $life = app(ConsignmentBuyoutLifecycleService::class);
            $b = $life->submit($life->createDraft($this->businessA->id, $this->supplier->id, now()->toDateString(), [
                ['line_type' => 'SOLD_UNBILLED', 'consignment_receiving_detail_id' => $this->receipt->id, 'consignment_sold_source_id' => $src->id,
                    'destination_location_id' => $this->b1->id, 'base_quantity' => '1', 'serial_ids' => [$this->sn[1]->id]],
            ], $this->actor, ['invoice_metadata' => ['invoice_date' => now()->toDateString(), 'supplier_invoice_number' => 'S-' . uniqid()]]), $this->actor);

            $results = $this->race([['legacy', $c->id], ['approve', $b->id]], $this->orders($round));
            $legacy = $c->fresh()->status === 'WAITING_APPROVAL';
            $buyout = $b->fresh()->status === ConsignmentBuyout::STATUS_APPROVED;
            $this->assertFalse($legacy && $buyout, 'remote share consumed twice: ' . implode(' | ', $results));
            $this->assertSame(1, $this->ok($results), implode(' | ', $results));
            $hist[$legacy ? 'legacy' : 'buyout'] = ($hist[$legacy ? 'legacy' : 'buyout'] ?? 0) + 1;
        }
        fwrite(STDERR, "\n[legacy-vs-buyout remote share] " . json_encode($hist) . "\n");
    }

    /** @test payment versus reversal of a remote acquisition: at most one commits, both owners restored when reversed */
    public function payment_versus_remote_reversal_never_both_commit(): void
    {
        $hist = [];
        for ($round = 0; $round < 4; $round++) {
            $this->build();
            $b = app(ConsignmentBuyoutLifecycleService::class)->approve($this->remoteBuyout(), $this->actor);
            $purchase = app(ConsignmentBuyoutFinalizationService::class)->finalize($b->fresh(), $this->actor);
            $coa = \Modules\Setting\Entities\ChartOfAccount::create(['setting_id' => $this->businessA->id, 'account_number' => 'COA-' . uniqid(), 'name' => 'Cash', 'category' => 'Kas & Bank']);
            $method = PaymentMethod::create(['name' => 'Bank Transfer', 'coa_id' => $coa->id, 'is_active' => true]);
            $this->setting = $this->businessA;

            $results = $this->race([['reverse', $b->id], ['pay', $purchase->id, (string) $method->id]], $this->orders($round));
            $reversed = $b->fresh()->status === 'REVERSED';
            $paid = DB::table('purchase_payments')->where('purchase_id', $purchase->id)->count();
            $this->assertFalse($reversed && $paid > 0, implode(' | ', $results));
            $this->assertSame(1, $this->ok($results), implode(' | ', $results));
            if ($reversed) {
                $this->assertSame($this->cb->id, (int) $this->sn[1]->fresh()->location_id, 'remote unit back in remote supplier custody');
                $this->assertEquals(100.0, (float) ProductPrice::where('setting_id', $this->businessB->id)->value('average_purchase_price'));
            }
            $hist[$reversed ? 'reversal' : 'payment'] = ($hist[$reversed ? 'reversal' : 'payment'] ?? 0) + 1;
        }
        fwrite(STDERR, "\n[payment-vs-remote-reversal] " . json_encode($hist) . "\n");
    }

    /* ------------------------------------------------------------------ remote owner cost change versus reversal */

    private function ledgerB(): array
    {
        return DB::table('product_price_cost_events')->where('product_id', $this->bulk->id)->where('setting_id', $this->businessB->id)->orderBy('id')
            ->get(['id', 'old_average', 'new_average'])->map(fn ($r) => ['id' => (int) $r->id, 'old' => $r->old_average === null ? null : (float) $r->old_average, 'new' => (float) $r->new_average])->all();
    }

    private function assertChain(array $ledger, string $why): void
    {
        for ($i = 1; $i < count($ledger); $i++) {
            $this->assertEqualsWithDelta($ledger[$i - 1]['new'], $ledger[$i]['old'], 0.0051, "lost update between ledger rows: {$why}");
        }
        $this->assertEqualsWithDelta(end($ledger)['new'], (float) ProductPrice::where('product_id', $this->bulk->id)->where('setting_id', $this->businessB->id)->value('average_purchase_price'),
            0.0051, "live B average != last B ledger row: {$why}");
    }

    /** Remote unsold bulk acquisition: 3 units of A's receipt held at B, bought into B's standard location, finalized. */
    private function finalizedRemoteBulkBuyout(): ConsignmentBuyout
    {
        foreach ([$this->businessA, $this->businessB] as $s) {
            ProductPrice::updateOrCreate(['product_id' => $this->bulk->id, 'setting_id' => $s->id], ['sale_price' => 1, 'last_purchase_price' => 1, 'average_purchase_price' => 100]);
        }
        $lot = $this->bulkReceipt(3);
        DB::table('product_stocks')->where('product_id', $this->bulk->id)->where('location_id', $this->ca->id)->delete();
        $this->stock($this->bulk, $this->ca, nonTax: 3);
        DB::table('products')->where('id', $this->bulk->id)->update(['product_quantity' => DB::table('product_stocks')->where('product_id', $this->bulk->id)->sum('quantity')]);
        $this->moveBulk($lot, 3);
        $life = app(ConsignmentBuyoutLifecycleService::class);
        $b = $life->createDraft($this->businessA->id, $this->supplier->id, now()->toDateString(), [
            ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $lot->id, 'source_location_id' => $this->cb->id,
                'destination_location_id' => $this->b1->id, 'base_quantity' => '3', 'buckets' => ['TAXED' => '3']],
        ], $this->actor, ['invoice_metadata' => ['invoice_date' => now()->toDateString(), 'supplier_invoice_number' => 'RB-' . uniqid()],
            'pricing_intent' => ['rows' => []]]);
        // Priced above the holding average so the acquisition really replaces B's carrying value (and reversal restores it).
        $b->update(['pricing_intent' => ['rows' => [$b->lines()->value('id') => ['unit_price' => 150]]]]);
        $b = $life->approve($life->submit($b->fresh(), $this->actor), $this->actor);
        app(ConsignmentBuyoutFinalizationService::class)->finalize($b->fresh(), $this->actor);
        $ev = DB::table('consignment_buyout_cost_events')->where('consignment_buyout_id', $b->id)->first();
        $this->assertNotEquals((float) $ev->average_before, (float) $ev->average_after, 'fixture: acquisition changed the B average');

        return $b->fresh();
    }

    /** Pending ordinary receiving of 10 bulk units into B's standard location (a real cost writer in the holding business). */
    private function pendingReceivingInB(): \Modules\Purchase\Entities\ReceivedNote
    {
        foreach (['purchases.receive', 'purchases.receive.approval'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
            $this->actor->givePermissionTo($p);
        }
        $vendor = \Modules\People\Entities\Supplier::create(['setting_id' => $this->businessB->id, 'supplier_name' => 'Vendor B', 'supplier_email' => 'vb@e.com',
            'supplier_phone' => '0822', 'city' => 'J', 'country' => 'ID', 'address' => 'B']);
        $purchase = \Modules\Purchase\Entities\Purchase::create(['date' => now(), 'due_date' => now()->addDays(30), 'reference' => 'PO-B-' . uniqid(), 'supplier_id' => $vendor->id,
            'supplier_name' => 'Vendor B', 'status' => \Modules\Purchase\Entities\Purchase::STATUS_APPROVED, 'payment_status' => 'Unpaid', 'payment_method' => 'Cash',
            'total_amount' => 5000, 'paid_amount' => 0, 'due_amount' => 5000, 'setting_id' => $this->businessB->id]);
        $detail = \Modules\Purchase\Entities\PurchaseDetail::create(['purchase_id' => $purchase->id, 'product_id' => $this->bulk->id, 'product_name' => 'Bulk', 'product_code' => $this->bulk->product_code,
            'quantity' => 10, 'price' => 500, 'unit_price' => 500, 'sub_total' => 5000, 'product_discount_amount' => 0, 'product_tax_amount' => 0]);
        $note = \Modules\Purchase\Entities\ReceivedNote::create(['po_id' => $purchase->id, 'date' => now(), 'location_id' => $this->b1->id, 'status' => \Modules\Purchase\Entities\ReceivedNote::STATUS_PENDING]);
        \Modules\Purchase\Entities\ReceivedNoteDetail::create(['received_note_id' => $note->id, 'quantity_received' => 10, 'po_detail_id' => $detail->id]);

        return $note;
    }

    /** @test control: the holding-business receiving worker really writes a B cost-ledger row, and reversal alone succeeds */
    public function remote_owner_cost_writer_and_reversal_each_succeed_alone(): void
    {
        $this->build();
        $b = $this->finalizedRemoteBulkBuyout();
        $note = $this->pendingReceivingInB();
        $before = $this->ledgerB();
        $this->setting = $this->businessB;
        $results = $this->race([['http', route('receivings.approve', $note->id, false)]]);
        $this->assertSame(1, $this->ok($results), implode(' | ', $results));
        $this->assertCount(count($before) + 1, $this->ledgerB(), 'the receiving worker appends one B ledger row: ' . implode(' | ', $results));
        fwrite(STDERR, "\n[ordinary B receiving, origin-ledger rows written by Purchase legacy sync] "
            . DB::table('product_price_cost_events')->where('setting_id', $this->businessA->id)->where('product_id', $this->bulk->id)->whereNotNull('old_average')->count() . "\n");
        $this->assertChain($this->ledgerB(), 'control');

        $this->build();
        $b = $this->finalizedRemoteBulkBuyout();
        $this->setting = $this->businessA;
        $results = $this->race([['reverse', $b->id]]);
        $this->assertSame(1, $this->ok($results), implode(' | ', $results));
        $this->assertSame('REVERSED', strtoupper((string) $b->fresh()->status));
    }

    /** @test remote inventory-owner cost change versus reversal of the remote acquisition, both winner orders */
    public function remote_owner_cost_change_versus_reversal_never_restores_over_an_intervening_cost_event(): void
    {
        $outcomes = [];
        for ($round = 0; $round < 6; $round++) {
            $this->build();
            $b = $this->finalizedRemoteBulkBuyout();
            $event = DB::table('consignment_buyout_cost_events')->where('consignment_buyout_id', $b->id)->sole();
            $this->assertSame((int) $this->businessB->id, (int) $event->setting_id, 'the acquisition cost event belongs to the holding business');
            $note = $this->pendingReceivingInB();
            $baseline = count($this->ledgerB());
            $aLedger = DB::table('product_price_cost_events')->where('setting_id', $this->businessA->id)->count();
            $aAvg = (float) ProductPrice::where('product_id', $this->bulk->id)->where('setting_id', $this->businessA->id)->value('average_purchase_price');
            $this->setting = $this->businessB;   // the receiving runs in the holding business; reversal authorizes its actor itself

            $results = $this->race([['reverse', $b->id], ['http', route('receivings.approve', $note->id, false)]], $round % 2 ? [250, 0] : [0, 250]);

            $ledger = $this->ledgerB();
            $new = array_slice($ledger, $baseline);
            $reversed = strtoupper((string) $b->fresh()->status) === 'REVERSED';
            $received = strtoupper((string) DB::table('received_notes')->where('id', $note->id)->value('status')) === 'APPROVED';
            $this->assertTrue($received, 'the ordinary receiving always completes: ' . implode(' | ', $results));
            $this->assertChain($ledger, implode(' | ', $results));
            $this->assertSame(0, DB::table('purchase_payments')->count());
            // This change never writes origin cost: no consignment cost event, acquisition or reversal, lands in the origin.
            // (The ordinary Purchase receiving's legacy product-level average sync can touch the product-owner business's
            // ledger on its own - pre-existing, outside this change, measured in the control test.)
            $this->assertSame(0, DB::table('consignment_buyout_cost_events')->where('setting_id', $this->businessA->id)->count(),
                'no consignment cost effect in the origin business: ' . implode(' | ', $results));

            if ($reversed) {
                // Reversal ran first: B's average restored, then the receiving started from the restored value.
                $this->assertCount(2, $new, json_encode($new));
                $this->assertEqualsWithDelta((float) $event->average_before, $new[0]['new'], 0.0051);
                $this->assertEqualsWithDelta(3.0, (float) DB::table('product_stocks')->where('product_id', $this->bulk->id)->where('location_id', $this->cb->id)->value('quantity'), 0.001,
                    'remote units are supplier-owned consignment custody again');
                $this->assertSame('CANCELLED', strtoupper((string) DB::table('purchases')->where('id', $b->purchase_id)->value('status')));
                $this->assertSame(1, $this->ok($this->race([['reverse', $b->id]])), 'repeat reversal is a no-op');
                $this->assertSame(1, DB::table('consignment_buyout_cost_events')->where('consignment_buyout_id', $b->id)->where('kind', 'REVERSAL')->count());
            } else {
                // Receiving ran first: the reversal was refused by the intervening B ledger row and changed nothing.
                $this->assertCount(1, $new, 'a refused reversal writes no cost event: ' . json_encode($new));
                $this->assertSame('FINALIZED', strtoupper((string) $b->fresh()->status));
                $this->assertSame(0, DB::table('consignment_buyout_cost_events')->where('kind', 'REVERSAL')->count());
                $this->assertEqualsWithDelta(0.0, (float) DB::table('product_stocks')->where('product_id', $this->bulk->id)->where('location_id', $this->cb->id)->value('quantity'), 0.001,
                    'remote units stay owned by the holding business');
                $this->assertStringContainsString('intervening', json_encode(app(\Modules\Consignment\Services\ConsignmentBuyoutReversalService::class)->preview($b->fresh())['blockers']));
            }
            $outcomes[] = $reversed ? 'reversal-won' : 'cost-change-won';
        }
        fwrite(STDERR, "\n[remote cost-change-vs-reversal outcomes] " . json_encode(array_count_values($outcomes)) . "\n");
        $this->assertCount(2, array_unique($outcomes), 'both winner orders must be exercised: ' . json_encode($outcomes));
    }
}
