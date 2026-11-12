<?php

namespace Modules\Consignment\Tests\Support;

use App\Models\User;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Services\ConsignmentBuyoutCapacityService;
use Modules\Consignment\Services\ConsignmentBuyoutFinalizationService;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Finalization fixtures on top of BuildsBuyoutFixtures (mixed sold/unsold scenario, snapshots, posting counts). */
trait BuildsFinalizedBuyout
{
    private ConsignmentBuyoutLifecycleService $life;
    private ConsignmentBuyoutFinalizationService $fin;
    private User $user;
    private Location $dest1;
    private Location $dest2;
    private Location $otherStd;


    protected function setUpFinalizationFixtures(): void
    {
        $this->setUpBuyoutFixtures();
        config(['consignment.buyouts.finalization_enabled' => true]);
        $this->life = new ConsignmentBuyoutLifecycleService();
        $this->fin = new ConsignmentBuyoutFinalizationService();
        $this->dest1 = Location::create(['setting_id' => $this->setting->id, 'name' => 'Dest 1', 'is_consignment' => false]);
        $this->dest2 = Location::create(['setting_id' => $this->setting->id, 'name' => 'Dest 2', 'is_consignment' => false]);
        $this->otherStd = Location::create(['setting_id' => $this->setting->id, 'name' => 'Other std', 'is_consignment' => false]);

        $this->user = User::factory()->create();
        foreach (['consignments.buyouts.create', 'consignments.buyouts.edit', 'consignments.buyouts.submit', 'consignments.buyouts.approve',
            'consignments.buyouts.cancel', 'consignments.buyouts.acquire_unsold', 'consignments.allocations.approve',
            'consignments.buyouts.finalize', 'consignments.buyouts.reverse', 'consignments.billing.convert'] as $p) {
            Permission::findOrCreate($p, 'web');
            $this->user->givePermissionTo($p);
        }
        $this->user->settings()->attach($this->setting->id, ['role_id' => Role::findOrCreate('BR', 'web')->id]);
    }

    protected function price(ConsignmentReceivingDetail $r, float $dpp): void
    {
        \DB::table('consignment_receiving_details')->where('id', $r->id)->update(['unit_dpp' => $dpp, 'unit_cost' => $dpp]);
    }

    protected function std(Location $l, int $qty): ProductStock
    {
        return ProductStock::create(['product_id' => $this->product->id, 'location_id' => $l->id, 'quantity' => $qty, 'quantity_tax' => 0,
            'quantity_non_tax' => $qty, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0]);
    }

    protected function meta(array $extra = []): array
    {
        return ['invoice_metadata' => ['invoice_date' => '2026-11-10', 'supplier_invoice_number' => 'INV-001'], 'pricing_intent' => $extra ?: null];
    }

    /**
     * Mixed scenario: receipt 10, 5 sold & attributed, buyout bills 2 sold + N unsold. Q=10 (5 at the consignment
     * location + 5 elsewhere), A=100. $unitPrice (optional) raises the unsold invoice price.
     */
    protected function mixed(?array $unsoldLines = null, ?float $unitPrice = null, ?array $invoiceMetadata = null): array
    {
        $r = $this->receipt($this->supplier, '10');
        $this->price($r, 100);
        $src = $this->soldSource('5');
        $this->capacity()->setAttribution($src->id, [$r->id => '5'], null, 'attribute');
        $this->stock('5');
        $this->std($this->otherStd, 5);
        ProductPrice::create(['product_id' => $this->product->id, 'setting_id' => $this->setting->id, 'sale_price' => 1,
            'last_purchase_price' => 777, 'average_purchase_price' => 100]);

        $unsoldLines ??= [['destination_location_id' => $this->dest1->id, 'base_quantity' => '3', 'buckets' => ['NON_TAXED' => '3']]];
        $lines = [['line_type' => 'SOLD_UNBILLED', 'consignment_receiving_detail_id' => $r->id, 'consignment_sold_source_id' => $src->id,
            'destination_location_id' => $this->dest1->id, 'base_quantity' => '2']];
        foreach ($unsoldLines as $u) {
            $lines[] = ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id] + $u;
        }
        $meta = $this->meta();
        if ($invoiceMetadata !== null) {
            $meta['invoice_metadata'] = $invoiceMetadata;
        }
        $b = $this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', $lines, $this->user, $meta);
        if ($unitPrice !== null) {
            $rows = [];
            foreach ($b->lines()->where('line_type', 'UNSOLD_BUYOUT')->get() as $l) {
                $rows[$l->id] = ['unit_price' => $unitPrice];
            }
            $b->update(['pricing_intent' => ['rows' => $rows]]);
        }
        $b = $this->life->approve($this->life->submit($b->fresh(), $this->user), $this->user);

        return [$b, $r, $src];
    }

    protected function capacity(): ConsignmentBuyoutCapacityService
    {
        return new ConsignmentBuyoutCapacityService();
    }

    protected function snapshot(): array
    {
        return [
            'stocks' => \DB::table('product_stocks')->orderBy('id')->get(['location_id', 'quantity', 'quantity_tax', 'quantity_non_tax'])->toArray(),
            'products' => \DB::table('products')->orderBy('id')->pluck('product_quantity', 'id')->all(),
            'price' => \DB::table('product_prices')->orderBy('id')->get(['average_purchase_price', 'last_purchase_price'])->toArray(),
            'serials' => \DB::table('product_serial_numbers')->orderBy('id')->pluck('location_id', 'id')->all(),
        ];
    }

    protected function postingCounts(): array
    {
        return [
            'purchases' => \DB::table('purchases')->count(),
            'details' => \DB::table('purchase_details')->count(),
            'lineage' => \DB::table('consignment_buyout_purchase_lineages')->count(),
            'movements' => \DB::table('consignment_buyout_movements')->count(),
            'cost' => \DB::table('consignment_buyout_cost_events')->count(),
            'ownership' => \DB::table('consignment_buyout_ownership_events')->count(),
            'txn' => \DB::table('transactions')->where('type', 'CONSIGNMENT_BUYOUT')->count(),
            'history' => \DB::table('serial_number_histories')->where('event_type', 'CONSIGNMENT_BUYOUT_ACQUIRED')->count(),
            'finalized_audit' => \DB::table('consignment_buyout_audit_events')->where('action', 'FINALIZED')->count(),
        ];
    }

    protected function assertNothingPosted(ConsignmentBuyout $b, array $snapshot): void
    {
        $this->assertSame(array_fill_keys(array_keys($this->postingCounts()), 0), $this->postingCounts());
        $this->assertEquals($snapshot, $this->snapshot());
        $fresh = $b->fresh();
        $this->assertSame('APPROVED', $fresh->status);
        $this->assertNull($fresh->purchase_id);
        $this->assertSame(0, $fresh->reservations()->where('status', 'CONSUMED')->count());
        $this->assertGreaterThan(0, $fresh->reservations()->where('status', 'ACTIVE')->count());
    }

}
