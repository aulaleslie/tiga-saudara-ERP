<?php

namespace Modules\Consignment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentBillingConfirmation;
use Modules\Consignment\Entities\ConsignmentBillingConfirmationLine;
use Modules\Consignment\Entities\ConsignmentReceiptAllocation;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentBuyoutCapacityService;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\Consignment\Services\ConsignmentCustodyProjection;
use Modules\Consignment\Services\ConsignmentHistoricalReconstructionService;
use Modules\Consignment\Services\ConsignmentTransferProvenanceService;
use Modules\Consignment\Tests\Support\BuildsRemoteProvenanceFixtures;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 7.2 AXIOO acceptance fixture: a synthetic equivalent of TOP IT receipt TPI-CRN-2026-08-00001 with the observed shape
 * (five pre-provenance V3 transfers, three PERDANA sales, six CANDRA bills covering 26 HYPE 1 / 15 HYPE 2).
 *   HYPE 1: 50 = 43 local net sold + 3 remote sold + 4 local unsold
 *   HYPE 2: 25 = 22 sold + 1 local unsold + 2 remote unsold
 */
class ConsignmentAxiooHistoricalFixtureTest extends TestCase
{
    use RefreshDatabase;
    use BuildsRemoteProvenanceFixtures;

    private Product $hype2;
    private ConsignmentReceivingDetail $r1;
    private ConsignmentReceivingDetail $r2;
    /** @var array<string, ProductSerialNumber[]> */
    private array $serialsOf = [];
    /** @var array<string, ConsignmentSoldSource[]> */
    private array $remoteSources = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteFixtures();
        foreach (['consignments.provenance.reconcile', 'consignments.buyouts.create', 'consignments.buyouts.acquire_unsold', 'consignments.buyouts.acquire_remote'] as $p) {
            Permission::findOrCreate($p, 'web');
            $this->actor->givePermissionTo($p);
        }
        foreach ([$this->businessA, $this->businessB] as $s) {
            $this->actor->settings()->syncWithoutDetaching([$s->id => ['role_id' => Role::findOrCreate('AX', 'web')->id]]);
        }
        $this->hype2 = Product::create(['setting_id' => $this->businessA->id, 'product_name' => 'AXIOO HYPE 2', 'product_code' => 'HYPE2-' . uniqid(),
            'product_quantity' => 0, 'product_cost' => 1000, 'product_price' => 1500, 'serial_number_required' => true, 'stock_managed' => true]);

        $this->r1 = $this->receiptFor($this->serialized, 50, 'H1');
        $this->r2 = $this->receiptFor($this->hype2, 25, 'H2');
        $this->history();
    }

    private function receiptFor(Product $p, int $qty, string $tag): ConsignmentReceivingDetail
    {
        $d = $this->makeReceipt($qty);
        \DB::table('consignment_receiving_details')->where('id', $d->id)->update(['product_id' => $p->id]);
        \DB::table('consignment_receival_lines')->where('id', $d->consignment_receival_line_id)->update(['product_id' => $p->id, 'is_serialized' => true]);
        \DB::table('product_stocks')->where('product_id', $p->id)->where('location_id', $this->ca->id)->delete();
        $this->stock($p, $this->ca, nonTax: $qty);
        \DB::table('products')->where('id', $p->id)->update(['product_quantity' => \DB::table('product_stocks')->where('product_id', $p->id)->sum('quantity')]);
        for ($i = 1; $i <= $qty; $i++) {
            $sn = ProductSerialNumber::create(['product_id' => $p->id, 'location_id' => $this->ca->id, 'serial_number' => "{$tag}-{$i}", 'tax_id' => null,
                'consignment_receiving_detail_id' => $d->id]);
            \DB::table('consignment_receiving_detail_serial_numbers')->insert(['consignment_receiving_detail_id' => $d->id, 'product_serial_number_id' => $sn->id,
                'created_at' => now(), 'updated_at' => now()]);
            $this->serialsOf[$tag][] = $sn;
        }

        return $d->fresh();
    }

    private function sell(Product $p, ProductSerialNumber $sn, $location): ConsignmentSoldSource
    {
        $dd = $this->sellAt($location, [$sn], $p->id);
        $sn->update(['status' => 'SOLD']);
        \DB::table('product_stocks')->where('product_id', $p->id)->where('location_id', $location->id)
            ->update(['quantity' => \DB::raw('quantity - 1'), 'quantity_non_tax' => \DB::raw('quantity_non_tax - 1')]);

        return new ConsignmentSoldSource(['dispatch_detail_id' => $dd->id]);
    }

    private function moveSerialOf(Product $p, ProductSerialNumber $sn, $from, $to): void
    {
        $orig = $this->serialized;
        $this->serialized = $p;   // the fixture helper moves $this->serialized
        $this->moveSerial($sn, $from, $to);
        $this->serialized = $orig;
    }

    private function history(): void
    {
        // HYPE 1: 3 to PERDANA (sold there), 43 sold locally.
        foreach (array_slice($this->serialsOf['H1'], 0, 3) as $sn) {
            $this->moveSerialOf($this->serialized, $sn, $this->ca, $this->cb);
        }
        // HYPE 2: 2 to PERDANA, unsold.
        foreach (array_slice($this->serialsOf['H2'], 0, 2) as $sn) {
            $this->moveSerialOf($this->hype2, $sn, $this->ca, $this->cb);
        }
        // Everything above happened before provenance was deployed.
        \DB::table('consignment_provenance_events')->delete();

        foreach (array_slice($this->serialsOf['H1'], 0, 3) as $sn) {
            $this->remoteSources['H1'][] = $this->sell($this->serialized, $sn, $this->cb);
        }
        foreach (array_slice($this->serialsOf['H1'], 3, 43) as $sn) {
            $this->sell($this->serialized, $sn, $this->ca);
        }
        foreach (array_slice($this->serialsOf['H2'], 2, 22) as $sn) {
            $this->sell($this->hype2, $sn, $this->ca);
        }
        $this->discover($this->businessA);

        // Six existing CANDRA bills (TOP IT) consumed 26 HYPE 1 and 15 HYPE 2 of local sales.
        foreach ([[$this->serialized, $this->r1, 26], [$this->hype2, $this->r2, 15]] as [$p, $r, $billed]) {
            $c = ConsignmentBillingConfirmation::create(['setting_id' => $this->businessA->id, 'supplier_id' => $this->supplier->id,
                'confirmation_number' => 'LEG-' . $p->id, 'status' => 'APPROVED', 'date' => now(), 'source_hash' => 'x', 'billed_at' => now()]);
            $local = ConsignmentSoldSource::where('product_id', $p->id)->where('location_id', $this->ca->id)->orderBy('id')->take($billed)->get();
            foreach ($local as $src) {
                $l = ConsignmentBillingConfirmationLine::create(['consignment_billing_confirmation_id' => $c->id, 'consignment_sold_source_id' => $src->id,
                    'product_id' => $p->id, 'location_id' => $this->ca->id, 'allocated_base_quantity' => 1, 'sold_source_snapshot' => []]);
                ConsignmentReceiptAllocation::create(['consignment_billing_confirmation_line_id' => $l->id, 'consignment_receiving_detail_id' => $r->id,
                    'allocated_base_quantity' => 1, 'unit_cost' => 100, 'unit_dpp' => 100, 'tax_rate' => 0, 'tax_amount' => 0]);
            }
        }
    }

    private function reconstruct(Product $p): void
    {
        $svc = app(ConsignmentHistoricalReconstructionService::class);
        $preview = $svc->preview($p->id);
        $this->assertSame([], $preview['blockers']);
        $svc->confirm($p->id, $preview['evidence_hash'], 'Rekonstruksi AXIOO TPI-CRN-2026-08-00001', $this->actor);
    }

    /** @test */
    public function reconciliation_explains_every_unit_and_keeps_billed_consumption_under_top_it(): void
    {
        $cap = app(ConsignmentBuyoutCapacityService::class);
        $prov = app(ConsignmentTransferProvenanceService::class);
        $at = fn ($p, $state, $loc) => (new ConsignmentCustodyProjection)->quantityAt($p, $state, $loc->id);

        // Before reconstruction (the observed live state): TOP IT has no proven path to PERDANA, so the three remote
        // sales have no stored sold source and are invisible to TOP IT's manual discovery.
        $this->assertSame(0, ConsignmentSoldSource::where('location_id', $this->cb->id)->count());

        $this->reconstruct($this->serialized);
        $this->reconstruct($this->hype2);
        $this->discover($this->businessA);   // manual: binds the three PERDANA sales

        $p1 = $prov->project($this->r1->fresh());
        $this->assertTrue($p1['conserved']);
        $this->assertSame([], $p1['blockers']);
        $this->assertEqualsWithDelta(46.0, $p1['sold'], 0.001, '43 local + 3 remote');
        $this->assertEqualsWithDelta(4.0, $at($p1, 'SELLABLE', $this->ca), 0.001);
        $this->assertEqualsWithDelta(0.0, $at($p1, 'SELLABLE', $this->cb), 0.001);

        $p2 = $prov->project($this->r2->fresh());
        $this->assertTrue($p2['conserved']);
        $this->assertEqualsWithDelta(22.0, $p2['sold'], 0.001);
        $this->assertEqualsWithDelta(1.0, $at($p2, 'SELLABLE', $this->ca), 0.001);
        $this->assertEqualsWithDelta(2.0, $at($p2, 'SELLABLE', $this->cb), 0.001);

        // Billed 26 / 15 stay consumed under TOP IT; the three remote sales became billable only after manual discovery.
        $this->assertEqualsWithDelta(24.0, $cap->receiptCapacity($this->r1->id)['financial_available'], 0.001);
        $this->assertEqualsWithDelta(10.0, $cap->receiptCapacity($this->r2->id)['financial_available'], 0.001);
        foreach (ConsignmentSoldSource::where('location_id', $this->cb->id)->get() as $src) {
            $this->assertEqualsWithDelta(1.0, $cap->soldReceiptResidual($src, $this->r1->id), 0.001);
            $this->assertSame((int) $this->businessB->id, (int) $src->setting_id);
        }
        $this->assertSame(0, \DB::table('purchases')->count(), 'no bill regenerated');

        // The four local HYPE 1 units are selectable although earlier custody moved to PERDANA.
        $life = app(ConsignmentBuyoutLifecycleService::class);
        $meta = ['invoice_metadata' => ['invoice_date' => now()->toDateString(), 'supplier_invoice_number' => 'AX']];
        $local = $life->createDraft($this->businessA->id, $this->supplier->id, now()->toDateString(), [
            ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $this->r1->id, 'destination_location_id' => $this->a1->id,
                'base_quantity' => '4', 'serial_ids' => array_map(fn ($s) => $s->id, array_slice($this->serialsOf['H1'], 46, 4))],
        ], $this->actor, $meta);
        $this->assertSame([], $life->validateDocument($local));

        $mixed = $life->createDraft($this->businessA->id, $this->supplier->id, now()->toDateString(), [
            ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $this->r2->id, 'destination_location_id' => $this->a1->id,
                'base_quantity' => '1', 'serial_ids' => [$this->serialsOf['H2'][24]->id]],
            ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $this->r2->id, 'source_location_id' => $this->cb->id, 'destination_location_id' => $this->b1->id,
                'base_quantity' => '2', 'serial_ids' => [$this->serialsOf['H2'][0]->id, $this->serialsOf['H2'][1]->id]],
        ], $this->actor, $meta);
        $this->assertSame([], $life->validateDocument($mixed), 'HYPE 2: one local plus two remote units without duplicate receipt consumption');
    }

    /** @test */
    public function repeated_preview_confirm_and_discovery_change_no_operational_record(): void
    {
        $snap = fn () => array_map(fn ($t) => \DB::table($t)->orderBy('id')->get()->toArray(),
            ['product_stocks', 'product_serial_numbers', 'transfers', 'sales', 'dispatch_details', 'consignment_billing_confirmations', 'consignment_receipt_allocations']);
        $before = $snap();
        foreach ([1, 2] as $_) {
            $this->reconstruct($this->serialized);
            $this->reconstruct($this->hype2);
            $this->discover($this->businessA);
        }
        $this->assertEquals($before, $snap());
        $this->assertSame(3, \Modules\Consignment\Entities\ConsignmentObligationBinding::count());
        $this->assertSame(2, \Modules\Consignment\Entities\ConsignmentHistoricalMapping::count());
    }

    /** @test */
    public function reconciliation_screen_shows_remote_custody_instead_of_missing_local_stock(): void
    {
        $this->reconstruct($this->serialized);
        $this->reconstruct($this->hype2);
        $this->discover($this->businessA);

        $pool = app(ConsignmentBuyoutCapacityService::class)->physicalPool($this->businessA->id, $this->hype2->id, $this->ca->id);
        $this->assertSame([], array_filter($pool['blockers'], fn ($b) => str_contains(is_array($b) ? ($b['reason'] ?? '') : $b, 'lower than the supplier-owned residual')),
            'the two units at PERDANA are explained, not missing locally');
        $this->assertEqualsWithDelta(1.0, $pool['physically_available'], 0.001);

        $this->withoutMiddleware([\App\Http\Middleware\CheckUserRoleForSetting::class]);
        foreach (collect(require base_path('app/Config/Permissions.php'))->flatMap(fn ($g) => array_keys($g)) as $perm) {
            Permission::findOrCreate($perm, 'web');
        }
        $this->actor->givePermissionTo('consignments.allocations.access');
        $html = $this->actingAs($this->actor)->withSession(['setting_id' => $this->businessA->id])
            ->get(route('consignments.reconciliation.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Kustodi penerimaan', $html);
        $this->assertStringContainsString('Tersedia 2 · ' . $this->cb->name, $html);
        $this->assertStringContainsString('Terjual di bisnis lain 3', $html);
    }
}
