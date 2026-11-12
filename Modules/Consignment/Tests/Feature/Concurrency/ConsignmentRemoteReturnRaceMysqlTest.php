<?php

namespace Modules\Consignment\Tests\Feature\Concurrency;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentObligationBinding;
use Modules\Consignment\Entities\ConsignmentProvenanceEvent as Event;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentBuyoutCapacityService;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService;
use Modules\Consignment\Tests\Support\RunsBuyoutRaceWorkers;
use Modules\People\Entities\Supplier;
use Modules\SalesReturn\Entities\SaleReturnCustody;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\SalesReturn\BuildsStandardFlow;
use Tests\TestCase;

/**
 * 6.5 / 5.6: REMOTE customer return (received through the real sale-returns.receive route in the selling business)
 * versus finalization of the origin business's buyout of that remote sold share. Setting 1 = original receipt business
 * (TOP IT-like), setting 2 = selling/holding business (PERDANA-like). Real OS processes, isolated MySQL, both orders.
 *
 * @group mysql
 */
class ConsignmentRemoteReturnRaceMysqlTest extends TestCase
{
    use BuildsStandardFlow;
    use RunsBuyoutRaceWorkers;

    protected User $user;
    protected $setting;
    private Location $originRack;
    private Location $remoteRack;
    private Location $remoteOwned;
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
        $this->capacity = new ConsignmentBuyoutCapacityService();
        $this->user = User::factory()->create();
        foreach (['consignments.buyouts.create', 'consignments.buyouts.submit', 'consignments.buyouts.approve', 'consignments.buyouts.cancel',
            'consignments.buyouts.acquire_unsold', 'consignments.allocations.approve', 'consignments.buyouts.finalize', 'consignments.buyouts.reverse',
            'consignments.billing.convert', 'consignments.buyouts.acquire_remote', 'consignments.provenance.reconcile'] as $p) {
            Permission::findOrCreate($p, 'web');
            $this->user->givePermissionTo($p);
        }
        foreach ([1, 2] as $s) {
            $this->user->settings()->attach($s, ['role_id' => Role::findOrCreate('R', 'web')->id]);
        }
        $this->actingAs($this->user);
        Location::query()->whereKey(1)->update(['is_consignment' => true]);
        $this->originRack = Location::find(1);
        $this->remoteRack = Location::create(['setting_id' => 2, 'name' => 'Konsinyasi PERDANA', 'is_consignment' => true, 'is_active' => true]);
        $this->remoteOwned = Location::create(['setting_id' => 2, 'name' => 'Gudang PERDANA', 'is_consignment' => false, 'is_active' => true]);
        $this->supplier = Supplier::create(['setting_id' => 1, 'supplier_name' => 'CANDRA', 'supplier_email' => 's@e.com',
            'supplier_phone' => '0811', 'city' => 'J', 'country' => 'ID', 'address' => 'A']);
    }

    /** 6 units of a TOP IT receipt moved to PERDANA and sold there; TOP IT buyout of the sold share APPROVED. */
    private function remoteSoldBuyout(): array
    {
        $receival = ConsignmentReceival::create(['setting_id' => 1, 'supplier_id' => $this->supplier->id, 'reference' => 'TPI-' . uniqid(),
            'receival_number' => 'TPI-' . uniqid(), 'status' => 'APPROVED', 'date' => now()]);
        $flow = $this->makeDispatchedSale(6, false, 2);
        $receiving = ConsignmentReceiving::create(['setting_id' => 1, 'location_id' => 1, 'consignment_receival_id' => $receival->id,
            'receiving_number' => 'TPI-CRN-' . uniqid(), 'status' => 'APPROVED', 'date' => now()]);
        $line = ConsignmentReceivalLine::create(['consignment_receival_id' => $receival->id, 'product_id' => $flow['product']->id, 'product_name' => 'P',
            'product_code' => 'P', 'quantity' => 10, 'unit_cost' => 100, 'unit_dpp' => 100, 'subtotal_cost' => 1, 'subtotal_dpp' => 1, 'total_cost' => 1, 'total_dpp' => 1]);
        $receipt = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $line->id,
            'product_id' => $flow['product']->id, 'quantity_received' => 10, 'received_base_quantity' => 10, 'unit_cost' => 100, 'unit_dpp' => 100]);
        // Provenance: 6 units dispatched and received at PERDANA's rack before the sale (transfer posting itself is raced elsewhere).
        foreach ([['SELLABLE', $this->originRack, 'TRANSIT', $this->remoteRack, 'd'], ['TRANSIT', $this->remoteRack, 'SELLABLE', $this->remoteRack, 'r']] as [$fs, $fl, $ts, $tl, $k]) {
            Event::create(['consignment_receiving_detail_id' => $receipt->id, 'original_setting_id' => 1, 'supplier_id' => $this->supplier->id,
                'product_id' => $flow['product']->id, 'kind' => 'FIXTURE', 'source_type' => 'FIXTURE', 'source_id' => $receipt->id,
                'source_event_key' => "race-fixture:{$receipt->id}:{$k}", 'from_state' => $fs, 'from_location_id' => $fl->id, 'from_setting_id' => $fl->setting_id,
                'to_state' => $ts, 'to_location_id' => $tl->id, 'to_setting_id' => $tl->setting_id, 'base_quantity' => 6, 'effective_at' => now()->subDay()]);
        }
        DB::table('dispatch_details')->where('id', $flow['dispatchDetail']->id)->update(['location_id' => $this->remoteRack->id]);
        DB::table('dispatches')->where('id', $flow['dispatchDetail']->dispatch_id)->update(['approved_at' => now()]);

        (new ConsignmentSoldSourceDiscoveryService())->discoverForSetting(1);
        $source = ConsignmentSoldSource::where('dispatch_detail_id', $flow['dispatchDetail']->id)->firstOrFail();
        $this->capacity->setAttribution($source->id, [$receipt->id => '6'], $this->user->id, 'attribute remote');
        $this->assertSame(1, ConsignmentObligationBinding::where('consignment_sold_source_id', $source->id)->count(), 'control: remote binding exists');

        $svc = new ConsignmentBuyoutLifecycleService();
        $b = $svc->createDraft(1, $this->supplier->id, now()->toDateString(), [[
            'line_type' => 'SOLD_UNBILLED', 'consignment_receiving_detail_id' => $receipt->id, 'consignment_sold_source_id' => $source->id,
            'destination_location_id' => $this->remoteOwned->id, 'base_quantity' => '6']], $this->user,
            ['invoice_metadata' => ['invoice_date' => now()->toDateString(), 'supplier_invoice_number' => 'INV-RR' . uniqid()]]);
        $b = $svc->approve($svc->submit($b, $this->user), $this->user);

        $flow['dispatchDetail'] = $flow['dispatchDetail']->fresh();

        return ['flow' => $flow, 'source' => $source->fresh(), 'receipt' => $receipt, 'buyout' => $b];
    }

    /** A received-able return of $qty units at the selling business's rack. */
    private function remoteReturn(array $flow, int $qty)
    {
        $r = $this->makeReturnFor($flow, $qty, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving', 'setting_id' => 2, 'location_id' => $this->remoteRack->id]);
        DB::table('sale_return_details')->where('sale_return_id', $r->id)->update(['location_id' => $this->remoteRack->id]);

        return $r->fresh();
    }

    private function counts(): array
    {
        return ['purchases' => DB::table('purchases')->count(), 'movements' => DB::table('consignment_buyout_movements')->count(),
            'ownership' => DB::table('consignment_buyout_ownership_events')->count(), 'cost' => DB::table('consignment_buyout_cost_events')->count(),
            'payments' => DB::table('purchase_payments')->count()];
    }

    /** @test control: each operation succeeds alone in a worker */
    public function remote_return_receive_and_finalization_each_succeed_alone(): void
    {
        $f = $this->remoteSoldBuyout();
        $return = $this->remoteReturn($f['flow'], 2);
        $this->setting = Setting::find(2);
        $results = $this->race([['http', route('sale-returns.receive', $return->id, false)]]);
        $this->assertSame('awaiting settlement', strtolower((string) $return->fresh()->status), implode(' | ', $results));

        $this->build();
        $f = $this->remoteSoldBuyout();
        $this->setting = Setting::find(1);
        $results = $this->race([['finalize', $f['buyout']->id]]);
        $this->assertSame(1, $this->ok($results), implode(' | ', $results));
        $this->assertSame(1, (int) DB::table('purchases')->value('setting_id'), 'the Purchase belongs to the original receipt business');
    }

    /** @test remote return versus finalization over the same remote sold share, both winner orders */
    public function remote_return_versus_finalization_never_bills_returned_units_or_misroutes_ownership(): void
    {
        $outcomes = [];
        for ($round = 0; $round < 6; $round++) {
            $this->build();
            $f = $this->remoteSoldBuyout();
            $b = $f['buyout'];
            $return = $this->remoteReturn($f['flow'], 2);
            $before = $this->counts();
            $stockBefore = DB::table('product_stocks')->orderBy('id')->get(['location_id', 'quantity'])->toArray();
            $this->setting = Setting::find(2);   // the return is received by the selling business; finalize authorizes the actor itself

            $results = $this->race([['finalize', $b->id], ['http', route('sale-returns.receive', $return->id, false)]], $round % 2 ? [300, 0] : [0, 300]);

            $received = strtolower((string) $return->fresh()->status) === 'awaiting settlement';
            $finalized = $b->fresh()->status === ConsignmentBuyout::STATUS_FINALIZED;
            $this->assertTrue($received || $finalized, 'at least one operation must win: ' . implode(' | ', $results));
            $cap = $this->capacity->soldSourceCapacity($f['source']->fresh());
            $this->assertSame(0, DB::table('purchase_payments')->count(), 'no payment and no inter-business settlement is created');

            if ($finalized) {
                $this->assertTrue($received, 'a finalized acquisition must not block the remote customer return: ' . implode(' | ', $results));
                // Acquired remote units belong to the SELLING business: the held return is routed to its own standard location.
                $custody = SaleReturnCustody::where('sale_return_id', $return->id)->sole();
                $this->assertSame($this->remoteOwned->id, (int) $custody->location_id, 'ownership routed to the holding business, never the origin');
                $this->assertEquals(0.0, $cap['effective_returned'], 'acquired returns never reduce supplier eligibility');
                $this->assertEqualsWithDelta(6.0, (float) $cap['buyout_finalized'], 0.001);
                $this->assertEqualsWithDelta(0.0, (float) $cap['available'], 0.001);
                $this->assertSame(1, DB::table('purchases')->count(), 'one origin Purchase only');
                $this->assertSame(1, (int) DB::table('purchases')->value('setting_id'));
                $this->assertSame(0, DB::table('consignment_buyout_movements')->count(), 'sold slices move no stock');
                $this->assertSame(0, DB::table('consignment_buyout_cost_events')->count(), 'sold slices change no cost');
                // Idempotent retry.
                $this->setting = Setting::find(1);
                $this->assertSame(1, $this->ok($this->race([['finalize', $b->id]])));
                $this->assertSame(1, DB::table('purchases')->count(), 'finalization retry is a no-op');
            } else {
                // The return won: finalization was refused as over-consumed and rolled back completely.
                $this->assertSame(ConsignmentBuyout::STATUS_APPROVED, $b->fresh()->status);
                $this->assertSame($before, $this->counts(), 'a refused finalization must post nothing: ' . implode(' | ', $results));
                $this->assertEqualsWithDelta(2.0, (float) $cap['effective_returned'], 0.001);
                (new ConsignmentBuyoutLifecycleService())->cancel($b->fresh(), 'release', $this->user);
                $this->assertEqualsWithDelta(4.0, (float) $this->capacity->soldSourceCapacity($f['source']->fresh())['available'], 0.001);
                $this->assertEqualsWithDelta(4.0, $this->capacity->soldReceiptResidual($f['source']->fresh(), $f['receipt']->id), 0.001,
                    'returned remote units stay out of the origin receipt residual');
            }
            // Supplier stock rows other than the return's own custody movement are untouched by finalization.
            $this->assertSame(0, DB::table('consignment_buyout_movements')->count());
            $outcomes[] = $finalized ? 'finalize-won' : 'return-won';
            unset($stockBefore);
        }
        fwrite(STDERR, "\n[remote finalize-vs-return outcomes] " . json_encode(array_count_values($outcomes)) . "\n");
        $this->assertCount(2, array_unique($outcomes), 'both winner orders must be exercised: ' . json_encode($outcomes));
    }
}
