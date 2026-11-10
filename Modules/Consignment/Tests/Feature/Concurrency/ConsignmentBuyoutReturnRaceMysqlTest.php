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
}
