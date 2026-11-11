<?php

namespace Modules\Consignment\Tests\Feature\Concurrency;

use App\Models\User;
use App\Support\SalesReturn\StandardReturnCustodyService;
use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentBuyoutCapacityService;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\Consignment\Tests\Support\RunsBuyoutRaceWorkers;
use Modules\People\Entities\Supplier;
use Modules\SalesReturn\Entities\SaleReturnCustody;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement as Settlement;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\SalesReturn\BuildsStandardFlow;
use Tests\TestCase;

/**
 * A pre-billing customer return (physical receive) versus buyout finalization of the same sold units, as real OS processes
 * on the isolated MySQL database. Whichever wins: units cannot be both returned-to-supplier-pool and billed, an acquired
 * return is apportioned to the owned destination without reopening supplier capacity, and a refused finalization posts nothing.
 *
 * @group mysql
 */
class ConsignmentBuyoutReturnRaceMysqlTest extends TestCase
{
    use BuildsStandardFlow;
    use RunsBuyoutRaceWorkers;

    protected User $user;
    protected $setting;
    private Location $owned;
    private Supplier $supplier;
    private ConsignmentBuyoutCapacityService $capacity;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mysql_test') {
            $this->markTestSkipped('Requires the isolated MySQL test database (phpunit.mysql.xml / scripts/run-mysql-sequence-tests.sh).');
        }
        $this->build();
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
        $this->seedBase();
        config(['consignment.buyouts.finalization_enabled' => true]);
        $this->setting = Setting::find(1);
        $this->capacity = new ConsignmentBuyoutCapacityService();
        $this->user = User::factory()->create();
        foreach (['consignments.buyouts.create', 'consignments.buyouts.submit', 'consignments.buyouts.approve', 'consignments.buyouts.cancel',
            'consignments.buyouts.acquire_unsold', 'consignments.allocations.approve', 'consignments.buyouts.finalize', 'consignments.buyouts.reverse',
            'consignments.billing.convert'] as $p) {
            Permission::findOrCreate($p, 'web');
            $this->user->givePermissionTo($p);
        }
        $this->user->settings()->attach(1, ['role_id' => Role::findOrCreate('R', 'web')->id]);
        \Illuminate\Support\Facades\Gate::before(fn ($user, $ability) => true);   // in-process release/cancel only; workers authorize on their own
        $this->actingAs($this->user);
        Location::query()->whereKey(1)->update(['is_consignment' => true]);
        $this->owned = Location::create(['setting_id' => 1, 'name' => 'Owned standard', 'is_consignment' => false]);
        $this->supplier = Supplier::create(['setting_id' => 1, 'supplier_name' => 'Sup', 'supplier_email' => 's@e.com',
            'supplier_phone' => '0811', 'city' => 'J', 'country' => 'ID', 'address' => 'A']);
    }

    /** 6 units sold from the consignment location, all attributed to one receipt, an APPROVED (not finalized) buyout of all 6. */
    private function approvedSoldBuyout(bool $approve = true): array
    {
        $flow = $this->makeDispatchedSale(6);
        $receival = ConsignmentReceival::create(['setting_id' => 1, 'supplier_id' => $this->supplier->id, 'reference' => 'CR-R' . uniqid(),
            'receival_number' => 'CR-R' . uniqid(), 'status' => 'APPROVED', 'date' => now()]);
        $receiving = ConsignmentReceiving::create(['setting_id' => 1, 'location_id' => 1, 'consignment_receival_id' => $receival->id,
            'receiving_number' => 'CR-' . uniqid(), 'status' => 'APPROVED', 'date' => now()]);
        $line = ConsignmentReceivalLine::create(['consignment_receival_id' => $receival->id, 'product_id' => $flow['product']->id, 'product_name' => 'P',
            'product_code' => 'P', 'quantity' => 10, 'unit_cost' => 100, 'unit_dpp' => 100, 'subtotal_cost' => 1, 'subtotal_dpp' => 1, 'total_cost' => 1, 'total_dpp' => 1]);
        $receipt = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $line->id,
            'product_id' => $flow['product']->id, 'quantity_received' => 10, 'received_base_quantity' => 10, 'unit_cost' => 100, 'unit_dpp' => 100]);
        $source = ConsignmentSoldSource::create(['setting_id' => 1, 'dispatch_detail_id' => $flow['dispatchDetail']->id, 'sale_id' => $flow['sale']->id,
            'product_id' => $flow['product']->id, 'location_id' => 1, 'original_base_quantity' => 6, 'dispatched_at' => now(),
            'source_hash' => 'h' . uniqid(), 'source_snapshot' => []]);
        DB::table('consignment_sold_sources')->where('id', $source->id)->update(['source_hash' =>
            app(\Modules\Consignment\Services\ConsignmentBillingConfirmationLifecycleService::class)->computeCanonicalLiveHash($source->fresh())]);
        $source = $source->fresh();
        $this->capacity->setAttribution($source->id, [$receipt->id => '6'], null, 'attribute');

        $svc = new ConsignmentBuyoutLifecycleService();
        $b = $svc->createDraft(1, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'SOLD_UNBILLED', 'consignment_receiving_detail_id' => $receipt->id, 'consignment_sold_source_id' => $source->id,
            'destination_location_id' => $this->owned->id, 'base_quantity' => '6']], $this->user,
            ['invoice_metadata' => ['invoice_date' => '2026-11-10', 'supplier_invoice_number' => 'INV-R' . uniqid()]]);
        $b = $svc->submit($b, $this->user);
        $b = $approve ? $svc->approve($b, $this->user) : $b;

        return ['flow' => $flow, 'source' => $source, 'receipt' => $receipt, 'buyout' => $b];
    }

    private function newReturn(array $flow, int $qty)
    {
        return $this->makeReturnFor($flow, $qty, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving']);
    }

    private function counts(): array
    {
        return ['purchases' => DB::table('purchases')->count(), 'movements' => DB::table('consignment_buyout_movements')->count(),
            'ownership' => DB::table('consignment_buyout_ownership_events')->count(), 'cost' => DB::table('consignment_buyout_cost_events')->count()];
    }

    /** @test negative control: the receive worker really receives the return */
    public function return_receive_alone_receives_in_a_worker(): void
    {
        $f = $this->approvedSoldBuyout();
        $return = $this->newReturn($f['flow'], 2);
        $results = $this->race([['http', route('sale-returns.receive', $return->id, false)]]);

        $this->assertSame('awaiting settlement', strtolower((string) $return->fresh()->status), implode(' | ', $results));
    }

    /** @test finalization versus customer-return receive over the same sold units */
    public function finalization_versus_return_receive_never_bills_returned_units_or_reopens_supplier_capacity(): void
    {
        $outcomes = [];
        for ($round = 0; $round < 6; $round++) {
            if ($round > 0) {
                $this->build();
            }
            $f = $this->approvedSoldBuyout();
            $b = $f['buyout'];
            $return = $this->newReturn($f['flow'], 2);
            $before = $this->counts();

            $results = $this->race([['finalize', $b->id], ['http', route('sale-returns.receive', $return->id, false)]], $round % 2 ? [300, 0] : [0, 300]);

            $received = strtolower((string) $return->fresh()->status) === 'awaiting settlement';
            $finalized = $b->fresh()->status === ConsignmentBuyout::STATUS_FINALIZED;
            $this->assertTrue($received || $finalized, 'at least one operation must win: ' . implode(' | ', $results));
            $cap = $this->capacity->soldSourceCapacity($f['source']->fresh());

            if ($finalized) {
                // The return was received after (or alongside) acquisition: the 2 units are company-owned, held for the owned destination.
                $this->assertTrue($received, 'a finalized acquisition must not block the customer return: ' . implode(' | ', $results));
                $custody = SaleReturnCustody::where('sale_return_id', $return->id)->sole();
                $this->assertSame($this->owned->id, (int) $custody->location_id);
                $this->assertEquals(0.0, $cap['effective_returned'], 'acquired returns never reduce supplier eligibility');
                $this->assertEqualsWithDelta(6.0, (float) $cap['buyout_finalized'], 0.001);
                $this->assertEqualsWithDelta(0.0, (float) $cap['available'], 0.001);

                // Release posts to the owned destination only and does not reopen supplier capacity.
                $row = $this->settlementFor($return->fresh(), Settlement::STATUS_APPROVED, 'CASH_REFUND');
                $row->update([]);
                $snapshot = [$this->capacity->soldSourceCapacity($f['source']->fresh()), $this->capacity->receiptCapacity($f['receipt']->id)];
                app(StandardReturnCustodyService::class)->release($return->fresh(), [['custody_id' => $custody->id, 'quantity' => 2]], 'baik', 'rel-' . $round);
                $this->assertEquals(2.0, (float) DB::table('product_stocks')->where('product_id', $f['flow']['product']->id)->where('location_id', $this->owned->id)->value('quantity'));
                $this->assertEquals($snapshot, [$this->capacity->soldSourceCapacity($f['source']->fresh()), $this->capacity->receiptCapacity($f['receipt']->id)], 'release reopened supplier capacity');
                $this->assertSame(1, DB::table('purchases')->count(), 'one purchase only; no duplicated quantity');
            } else {
                // The return won: finalization was refused as over-consumed and posted nothing.
                $this->assertSame(ConsignmentBuyout::STATUS_APPROVED, $b->fresh()->status);
                $this->assertSame($before, $this->counts(), 'a refused finalization must post nothing: ' . implode(' | ', $results));
                $this->assertEqualsWithDelta(2.0, (float) $cap['effective_returned'], 0.001, 'the returned units are no longer billable as sold');
                // Releasing the reservation shows exactly 4 left to bill, never 6.
                (new ConsignmentBuyoutLifecycleService())->cancel($b->fresh(), 'release', $this->user);
                $this->assertEqualsWithDelta(4.0, (float) $this->capacity->soldSourceCapacity($f['source']->fresh())['available'], 0.001);
                $this->assertEqualsWithDelta(4.0, $this->capacity->soldReceiptResidual($f['source']->fresh(), $f['receipt']->id), 0.001, 'returned units stay out of the receipt residual');
            }
            $outcomes[] = $finalized ? 'finalize-won' : 'return-won';
        }
        fwrite(STDERR, "\n[finalize-vs-return outcomes] " . json_encode(array_count_values($outcomes)) . "\n");
    }

    /** @test approval versus customer-return receive: returned units cannot be reserved as sold, nor billed later */
    public function approval_versus_return_receive_never_leaves_returned_units_billable(): void
    {
        $outcomes = [];
        for ($round = 0; $round < 6; $round++) {
            if ($round > 0) {
                $this->build();
            }
            $f = $this->approvedSoldBuyout(false);
            $b = $f['buyout'];
            $return = $this->newReturn($f['flow'], 2);

            $results = $this->race([['approve', $b->id], ['http', route('sale-returns.receive', $return->id, false)]], $round % 2 ? [300, 0] : [0, 300]);

            $approved = $b->fresh()->status === ConsignmentBuyout::STATUS_APPROVED;
            $received = strtolower((string) $return->fresh()->status) === 'awaiting settlement';
            $this->assertTrue($received, 'a customer return is never blocked by a pending reservation: ' . implode(' | ', $results));
            if ($approved) {
                // Reserved first, returned later: the over-consumed document can no longer be finalized and posts nothing.
                $before = $this->counts();
                try {
                    app(\Modules\Consignment\Services\ConsignmentBuyoutFinalizationService::class)->finalize($b->fresh(), $this->user);
                    $this->fail('a buyout over returned units was finalized');
                } catch (\Throwable $e) {
                    $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
                }
                $this->assertSame($before, $this->counts());
                $this->assertSame(ConsignmentBuyout::STATUS_APPROVED, $b->fresh()->status);
            } else {
                // Returned first: the 6-unit approval was refused and reserved nothing.
                $this->assertSame(0, DB::table('consignment_buyout_reservations')->where('status', 'ACTIVE')->count(), implode(' | ', $results));
                $this->assertEqualsWithDelta(4.0, (float) $this->capacity->soldSourceCapacity($f['source']->fresh())['available'], 0.001);
            }
            $outcomes[] = $approved ? 'approval-won' : 'return-won';
        }
        fwrite(STDERR, "\n[approval-vs-return outcomes] " . json_encode(array_count_values($outcomes)) . "\n");
    }

    /* ------------------------------------------------------------------ declaration races */

    /** Sell 6, FINALIZED buyout of 3 sold units, an approved return of 2 awaiting receiving (ambiguous split). */
    private function ambiguousReturn(): array
    {
        $f = $this->approvedSoldBuyout(approve: false);
        // Re-cut the draft to 3 acquired units before approval so the return of 2 is ambiguous.
        DB::table('consignment_buyout_lines')->where('consignment_buyout_id', $f['buyout']->id)->update(['base_quantity' => 3]);
        $svc = new ConsignmentBuyoutLifecycleService();
        $b = $svc->approve($f['buyout']->fresh(), $this->user);
        (new \Modules\Consignment\Services\ConsignmentBuyoutFinalizationService())->finalize($b->fresh(), $this->user);
        $return = $this->newReturn($f['flow'], 2);
        $detail = \Modules\SalesReturn\Entities\SaleReturnDetail::where('sale_return_id', $return->id)->sole();

        return $f + ['return' => $return, 'detail' => $detail, 'buyout' => $b->fresh()];
    }

    /** @test negative controls: each declaration-race worker really performs its operation alone */
    public function declaration_and_reversal_each_succeed_alone_in_a_worker(): void
    {
        $f = $this->ambiguousReturn();
        $this->assertSame(1, $this->ok($this->race([['declare', $f['detail']->id, '1']])));
        $this->assertSame('DECLARED', DB::table('consignment_buyout_return_apportionments')->value('status'));

        $this->build();
        $f = $this->ambiguousReturn();
        $results = $this->race([['reverse', $f['buyout']->id]]);
        $this->assertSame(1, $this->ok($results), implode(' | ', $results));
        $this->assertSame('REVERSED', $f['buyout']->fresh()->status);
    }

    /** @test a declaration and a buyout reversal over the same acquired sold units never both commit */
    public function declaration_versus_reversal_never_leaves_acquired_ownership_on_a_reversed_buyout(): void
    {
        $outcomes = [];
        foreach ([[0, 0], [0, 0], [0, 0], [0, 0], [0, 150], [150, 0], [0, 60], [60, 0], [0, 20], [20, 0]] as $round => $delays) {
            if ($round > 0) {
                $this->build();
            }
            $f = $this->ambiguousReturn();
            $results = $this->race([['declare', $f['detail']->id, '1'], ['reverse', $f['buyout']->id]], $delays);

            $reversed = $f['buyout']->fresh()->status === 'REVERSED';
            $declared = DB::table('consignment_buyout_return_apportionments')->where('sale_return_detail_id', $f['detail']->id)->exists();
            $this->assertFalse($reversed && $declared, 'acquired ownership declared against a reversed acquisition: ' . implode(' | ', $results));
            $this->assertTrue($reversed || $declared, 'one of the two must win: ' . implode(' | ', $results));
            if ($declared) {
                $this->assertSame('FINALIZED', $f['buyout']->fresh()->status, 'reversal saw the declaration and refused');
                $this->assertSame(0, DB::table('consignment_buyout_reversals')->count());
            }
            $outcomes[] = $declared ? 'declaration-won' : 'reversal-won';
        }
        fwrite(STDERR, "\n[declaration-vs-reversal outcomes] " . json_encode(array_count_values($outcomes)) . "\n");
    }

    /** @test receiving and a re-declaration race: the applied split is never overwritten and always matches custody */
    public function declaration_versus_receiving_never_overwrites_an_applied_split(): void
    {
        $outcomes = [];
        foreach ([[0, 0], [0, 150], [150, 0], [0, 60], [60, 0]] as $round => $delays) {
            if ($round > 0) {
                $this->build();
            }
            $f = $this->ambiguousReturn();
            (new \Modules\Consignment\Services\ConsignmentBuyoutReturnOwnershipService())->declare($f['detail'], 1, $this->user);
            $results = $this->race([
                ['http', route('sale-returns.receive', $f['return']->id, false)],
                ['declare', $f['detail']->id, '0'],
            ], $delays);

            $this->assertSame('awaiting settlement', strtolower((string) $f['return']->fresh()->status), implode(' | ', $results));
            $row = DB::table('consignment_buyout_return_apportionments')->where('sale_return_detail_id', $f['detail']->id)->sole();
            $this->assertSame('APPLIED', $row->status);
            $redeclared = str_starts_with($results[1], 'ok');
            $this->assertSame($redeclared ? 0.0 : 1.0, (float) $row->acquired_quantity, 'applied split = the last committed declaration: ' . implode(' | ', $results));
            $ownedHeld = (float) SaleReturnCustody::where('sale_return_id', $f['return']->id)->where('location_id', $this->owned->id)->sum('quantity_received');
            $this->assertSame((float) $row->acquired_quantity, $ownedHeld, 'custody follows the applied split');
            $outcomes[] = $redeclared ? 'redeclare-then-receive' : 'receive-then-refused';
        }
        fwrite(STDERR, "\n[declaration-vs-receiving outcomes] " . json_encode(array_count_values($outcomes)) . "\n");
    }

    /* ------------------------------------------------------------------ different-return races (unacquired room) */

    /**
     * Sell 6, acquire 3: only 3 supplier-owned units can come back as unacquired. Two DIFFERENT returns of 2 each are
     * declared fully unacquired (each valid alone). Both receivers queue on the same coordination key; the one that
     * waits must re-read the committed competitor, not its REPEATABLE READ snapshot.
     */
    private function twoUnacquiredReturns(): array
    {
        $f = $this->ambiguousReturn();
        $other = $this->newReturn($f['flow'], 2);
        $otherDetail = \Modules\SalesReturn\Entities\SaleReturnDetail::where('sale_return_id', $other->id)->sole();
        $svc = new \Modules\Consignment\Services\ConsignmentBuyoutReturnOwnershipService();
        $svc->declare($f['detail'], 0, $this->user);
        $svc->declare($otherDetail, 0, $this->user);

        return $f + ['other' => $other, 'otherDetail' => $otherDetail];
    }

    private function appliedUnacquired(int $sourceId): float
    {
        return (float) DB::table('consignment_buyout_return_apportionments')->where('consignment_sold_source_id', $sourceId)
            ->where('status', 'APPLIED')->sum('unacquired_quantity');
    }

    /** @test negative control: each different-return receiver succeeds alone */
    public function each_unacquired_return_receives_alone_in_a_worker(): void
    {
        $f = $this->twoUnacquiredReturns();
        $results = $this->race([['http', route('sale-returns.receive', $f['other']->id, false)]]);
        $this->assertSame('awaiting settlement', strtolower((string) $f['other']->fresh()->status), implode(' | ', $results));
        $this->assertSame(2.0, $this->appliedUnacquired($f['source']->id));
    }

    /** @test two different returns received concurrently never exceed the sale's unacquired room */
    public function receiving_versus_receiving_of_different_returns_never_exceeds_unacquired_room(): void
    {
        $outcomes = [];
        foreach ([[0, 0], [0, 0], [0, 0], [0, 150], [150, 0], [0, 60], [60, 0]] as $round => $delays) {
            if ($round > 0) {
                $this->build();
            }
            $f = $this->twoUnacquiredReturns();
            $results = $this->race([
                ['http', route('sale-returns.receive', $f['return']->id, false)],
                ['http', route('sale-returns.receive', $f['other']->id, false)],
            ], $delays);

            $received = collect([$f['return'], $f['other']])
                ->filter(fn ($r) => strtolower((string) $r->fresh()->status) === 'awaiting settlement')->count();
            $this->assertLessThanOrEqual(3.0, $this->appliedUnacquired($f['source']->id), 'supplier-owned room exceeded: ' . implode(' | ', $results));
            $this->assertSame(1, $received, 'exactly one return fits: ' . implode(' | ', $results));
            $outcomes[] = strtolower((string) $f['return']->fresh()->status) === 'awaiting settlement' ? 'first-won' : 'second-won';
        }
        fwrite(STDERR, "\n[receive-vs-receive outcomes] " . json_encode(array_count_values($outcomes)) . "\n");
    }

    /**
     * @test deterministic interleaving: a holder keeps the coordination lock while BOTH receivers start and queue behind
     * it, so each receiver's transaction is already open while the other commits. Any plain read before the lock wait
     * (the old in-transaction key discovery) or a snapshot eligibility read after it lets both commit.
     */
    public function receivers_queued_behind_a_held_coordination_lock_still_see_each_others_commit(): void
    {
        foreach ([[0, 300, 600], [0, 600, 300]] as $round => $delays) {
            if ($round > 0) {
                $this->build();
            }
            $f = $this->twoUnacquiredReturns();
            $results = $this->race([
                ['coord', $f['flow']['product']->id, '2000'],
                ['http', route('sale-returns.receive', $f['return']->id, false)],
                ['http', route('sale-returns.receive', $f['other']->id, false)],
            ], $delays);

            $this->assertSame('ok', $results[0], implode(' | ', $results));
            $this->assertLessThanOrEqual(3.0, $this->appliedUnacquired($f['source']->id), 'supplier-owned room exceeded: ' . implode(' | ', $results));
            $received = collect([$f['return'], $f['other']])
                ->filter(fn ($r) => strtolower((string) $r->fresh()->status) === 'awaiting settlement')->count();
            $this->assertSame(1, $received, 'exactly one return fits: ' . implode(' | ', $results));
            $this->assertStringContainsString('room for unacquired returns: 1', implode(' | ', $results), 'the loser re-read the committed winner');
        }
    }

    /** @test declaring another return unacquired while one is being received never yields a receivable over-split */
    public function declaration_versus_receiving_of_a_different_return_never_exceeds_unacquired_room(): void
    {
        $outcomes = [];
        foreach ([[0, 0], [0, 0], [0, 150], [150, 0], [0, 60], [60, 0]] as $round => $delays) {
            if ($round > 0) {
                $this->build();
            }
            $f = $this->ambiguousReturn();
            (new \Modules\Consignment\Services\ConsignmentBuyoutReturnOwnershipService())->declare($f['detail'], 0, $this->user);
            $other = $this->newReturn($f['flow'], 2);
            $otherDetail = \Modules\SalesReturn\Entities\SaleReturnDetail::where('sale_return_id', $other->id)->sole();
            $results = $this->race([
                ['http', route('sale-returns.receive', $f['return']->id, false)],
                ['declare', $otherDetail->id, '0'],
            ], $delays);
            $this->assertSame('awaiting settlement', strtolower((string) $f['return']->fresh()->status), implode(' | ', $results));

            // Whatever won, the other return can never be received with 2 unacquired units on top.
            $after = $this->race([['http', route('sale-returns.receive', $other->id, false)]]);
            $this->assertNotSame('awaiting settlement', strtolower((string) $other->fresh()->status), 'over-split received: ' . implode(' | ', array_merge($results, $after)));
            $this->assertSame(2.0, $this->appliedUnacquired($f['source']->id));
            $outcomes[] = str_starts_with($results[1], 'ok') ? 'declared-then-receive-refused' : 'declaration-refused';
        }
        fwrite(STDERR, "\n[declare-vs-receive (different returns) outcomes] " . json_encode(array_count_values($outcomes)) . "\n");
    }
}
