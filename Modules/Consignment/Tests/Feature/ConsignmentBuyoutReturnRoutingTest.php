<?php

namespace Modules\Consignment\Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Models\User;
use App\Services\Notification\DocumentNotificationService;
use App\Support\SalesReturn\StandardReturnCustodyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutReturnApportionment;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Entities\ConsignmentSoldSourceSerial;
use Modules\Consignment\Services\ConsignmentBuyoutCapacityService;
use Modules\Consignment\Services\ConsignmentBuyoutFinalizationService;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\Consignment\Services\ConsignmentBuyoutReturnOwnershipService;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnCustody;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement as Settlement;
use Modules\Setting\Entities\Location;
use Spatie\Permission\Models\Role;
use Tests\Feature\SalesReturn\BuildsStandardFlow;
use Tests\TestCase;

/**
 * Real standard Sales Return receiving + authorized inspection release for goods sold from consignment and later
 * acquired by a finalized buyout (SOLD_UNBILLED). Deterministic SQLite integration.
 */
class ConsignmentBuyoutReturnRoutingTest extends TestCase
{
    use RefreshDatabase, BuildsStandardFlow;

    private Location $owned;
    private Supplier $supplier;
    private User $actor;
    private ConsignmentBuyoutCapacityService $capacity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBase();
        $this->withoutMiddleware(CheckUserRoleForSetting::class);
        Gate::before(fn ($user, $ability) => true);
        $this->actor = User::factory()->create();
        $this->actor->settings()->attach(1, ['role_id' => Role::findOrCreate('R', 'web')->id]);
        $this->actingAs($this->actor);
        $this->app->instance(DocumentNotificationService::class, \Mockery::mock(DocumentNotificationService::class)->shouldIgnoreMissing());
        config(['consignment.buyouts.finalization_enabled' => true]);
        $this->capacity = new ConsignmentBuyoutCapacityService();

        Location::query()->whereKey(1)->update(['is_consignment' => true]);
        $this->owned = Location::create(['setting_id' => 1, 'name' => 'Owned standard', 'is_consignment' => false]);
        $this->supplier = Supplier::create(['setting_id' => 1, 'supplier_name' => 'Sup', 'supplier_email' => 's@e.com',
            'supplier_phone' => '0811', 'city' => 'J', 'country' => 'ID', 'address' => 'A']);
    }

    private function http(): self
    {
        return $this->withSession(['setting_id' => 1]);
    }

    /**
     * A sale of $sold units from the consignment location (location 1) of a receipt of 10, fully attributed, with a
     * finalized buyout that acquired $acquired of the sold units (SOLD_UNBILLED, destination = owned standard).
     *
     * @return array{flow: array, source: ConsignmentSoldSource, receipt: ConsignmentReceivingDetail, buyout: ConsignmentBuyout}
     */
    private function soldAndAcquired(int $sold, int $acquired, bool $serial = false, array $acquiredSerialIdx = []): array
    {
        $flow = $this->makeDispatchedSale($sold, $serial);
        $receival = ConsignmentReceival::create(['setting_id' => 1, 'supplier_id' => $this->supplier->id, 'reference' => 'CR-R' . uniqid(),
            'receival_number' => 'CR-R' . uniqid(), 'status' => 'APPROVED', 'date' => now()]);
        $receiving = ConsignmentReceiving::create(['setting_id' => 1, 'location_id' => 1, 'consignment_receival_id' => $receival->id,
            'receiving_number' => 'CR-' . uniqid(), 'status' => 'APPROVED', 'date' => now()]);
        $line = ConsignmentReceivalLine::create(['consignment_receival_id' => $receival->id, 'product_id' => $flow['product']->id, 'product_name' => 'P',
            'product_code' => 'P', 'quantity' => 10, 'unit_cost' => 100, 'unit_dpp' => 100, 'subtotal_cost' => 1, 'subtotal_dpp' => 1, 'total_cost' => 1, 'total_dpp' => 1]);
        $receipt = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $line->id,
            'product_id' => $flow['product']->id, 'quantity_received' => 10, 'received_base_quantity' => 10, 'unit_cost' => 100, 'unit_dpp' => 100]);

        $source = ConsignmentSoldSource::create(['setting_id' => 1, 'dispatch_detail_id' => $flow['dispatchDetail']->id, 'sale_id' => $flow['sale']->id,
            'product_id' => $flow['product']->id, 'location_id' => 1, 'original_base_quantity' => $sold, 'dispatched_at' => now(),
            'source_hash' => 'h' . uniqid(), 'source_snapshot' => []]);
        \DB::table('consignment_sold_sources')->where('id', $source->id)->update(['source_hash' =>
            app(\Modules\Consignment\Services\ConsignmentBillingConfirmationLifecycleService::class)->computeCanonicalLiveHash($source->fresh())]);
        $source = $source->fresh();
        ProductStock::create(['product_id' => $flow['product']->id, 'location_id' => 1, 'quantity' => 10 - $sold, 'quantity_tax' => 0,
            'quantity_non_tax' => 10 - $sold, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0]);

        $lineData = ['line_type' => 'SOLD_UNBILLED', 'consignment_receiving_detail_id' => $receipt->id, 'consignment_sold_source_id' => $source->id,
            'destination_location_id' => $this->owned->id, 'base_quantity' => (string) $acquired];
        if ($serial) {
            foreach ($flow['serials'] as $sn) {
                $sn->update(['consignment_receiving_detail_id' => $receipt->id]);
                ConsignmentSoldSourceSerial::create(['consignment_sold_source_id' => $source->id, 'product_serial_number_id' => $sn->id]);
            }
            $lineData['serial_ids'] = array_map(fn ($i) => $flow['serials'][$i]->id, $acquiredSerialIdx);
        } else {
            $this->capacity->setAttribution($source->id, [$receipt->id => (string) $sold], null, 'attribute');
        }

        $svc = new ConsignmentBuyoutLifecycleService();
        $b = $svc->createDraft(1, $this->supplier->id, '2026-11-10', [$lineData], $this->actor,
            ['invoice_metadata' => ['invoice_date' => '2026-11-10', 'supplier_invoice_number' => 'INV-R' . uniqid()]]);
        $b = $svc->approve($svc->submit($b, $this->actor), $this->actor);
        (new ConsignmentBuyoutFinalizationService())->finalize($b, $this->actor);

        return ['flow' => $flow, 'source' => $source, 'receipt' => $receipt, 'buyout' => $b->fresh()];
    }

    private function newReturn(array $flow, int $qty, array $serialIds = []): SaleReturn
    {
        return $this->makeReturnFor($flow, $qty, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving'], $serialIds);
    }

    private function receive(SaleReturn $r)
    {
        return $this->http()->post(route('sale-returns.receive', $r));
    }

    private function approveCashRefund(SaleReturn $return): void
    {
        $row = $this->settlementFor($return, Settlement::STATUS_APPROVED, 'CASH_REFUND');
        $row->update([]);
    }

    private function stockAt(int $productId, int $locationId): float
    {
        return (float) ProductStock::where('product_id', $productId)->where('location_id', $locationId)->value('quantity');
    }

    private function capacitySnapshot(ConsignmentSoldSource $source, ConsignmentReceivingDetail $receipt): array
    {
        return [$this->capacity->soldSourceCapacity($source->fresh()), $this->capacity->receiptCapacity($receipt->id)];
    }

    /** @test */
    public function a_fully_acquired_return_is_held_then_released_to_the_owned_destination_without_reopening_supplier_capacity(): void
    {
        $f = $this->soldAndAcquired(sold: 6, acquired: 6);
        $capacityBefore = $this->capacitySnapshot($f['source'], $f['receipt']);
        $return = $this->newReturn($f['flow'], 2);

        $this->receive($return)->assertRedirect();

        $custody = SaleReturnCustody::where('sale_return_id', $return->id)->sole();
        $this->assertSame($this->owned->id, (int) $custody->location_id, 'acquired goods are held for the owned destination');
        $this->assertEquals(2, $custody->held_quantity);
        // Held, not sellable: no stock anywhere moved by receiving.
        $this->assertSame(0.0, $this->stockAt($f['flow']['product']->id, $this->owned->id));
        $this->assertSame(4.0, $this->stockAt($f['flow']['product']->id, 1));
        $this->assertSame('ALL', ConsignmentBuyoutReturnApportionment::where('sale_return_detail_id', $custody->sale_return_detail_id)->value('origin'));
        // Supplier capacity is not reopened by the return.
        $this->assertEquals($capacityBefore, $this->capacitySnapshot($f['source'], $f['receipt']));
        $this->assertSame(0.0, $this->capacity->soldSourceCapacity($f['source']->fresh())['effective_returned']);

        // Authorized inspection release posts to the owned standard destination only.
        $this->approveCashRefund($return->fresh());
        app(StandardReturnCustodyService::class)->release($return->fresh(), [['custody_id' => $custody->id, 'quantity' => 2]], 'baik', 'rel-1');
        $this->assertSame(2.0, $this->stockAt($f['flow']['product']->id, $this->owned->id));
        $this->assertSame(4.0, $this->stockAt($f['flow']['product']->id, 1), 'consignment location untouched');
        $this->assertEquals($capacityBefore, $this->capacitySnapshot($f['source'], $f['receipt']));
    }

    /** @test */
    public function ambiguous_mixed_ownership_blocks_receiving_until_apportioned_explicitly(): void
    {
        $f = $this->soldAndAcquired(sold: 6, acquired: 3);
        $return = $this->newReturn($f['flow'], 2);

        $this->receive($return)->assertRedirect();
        $this->assertSame('awaiting receiving', strtolower($return->fresh()->status), 'ownership is never guessed');
        $this->assertSame(0, SaleReturnCustody::count());
        $this->assertSame(0, ConsignmentBuyoutReturnApportionment::count());

        $detail = SaleReturnDetail::where('sale_return_id', $return->id)->sole();
        $svc = new ConsignmentBuyoutReturnOwnershipService();
        foreach ([-1, 3] as $bad) {
            try {
                $svc->declare($detail, $bad);
                $this->fail("declared {$bad}");
            } catch (\DomainException $e) {
                $this->assertTrue(true);
            }
        }
        $svc->declare($detail, 1);                       // 1 of the 2 returned units was acquired
        $this->receive($return)->assertRedirect();

        $this->assertSame('awaiting settlement', strtolower($return->fresh()->status));
        $rows = SaleReturnCustody::where('sale_return_id', $return->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertEquals([1, 1], $rows->pluck('quantity_received')->map(fn ($q) => (float) $q)->all());
        $this->assertEqualsCanonicalizing([$this->owned->id, 1], $rows->pluck('location_id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame(0.0, $this->stockAt($f['flow']['product']->id, $this->owned->id), 'still held');

        // Only the unacquired unit participates in supplier eligibility.
        $this->assertSame(1.0, $this->capacity->soldSourceCapacity($f['source']->fresh())['effective_returned']);

        $this->approveCashRefund($return->fresh());
        foreach ($rows as $row) {
            app(StandardReturnCustodyService::class)->release($return->fresh(), [['custody_id' => $row->id, 'quantity' => 1]], 'baik', 'rel-' . $row->id);
        }
        $this->assertSame(1.0, $this->stockAt($f['flow']['product']->id, $this->owned->id));
        $this->assertSame(5.0, $this->stockAt($f['flow']['product']->id, 1), 'unacquired unit returns to the consignment pool');
    }

    /** @test */
    public function partial_and_repeated_returns_never_exceed_the_acquired_quantity_and_replays_do_not_duplicate(): void
    {
        $f = $this->soldAndAcquired(sold: 6, acquired: 3);
        $svc = new ConsignmentBuyoutReturnOwnershipService();

        $a = $this->newReturn($f['flow'], 3);
        $svc->declare(SaleReturnDetail::where('sale_return_id', $a->id)->sole(), 2);
        $this->receive($a)->assertRedirect();
        $rowsA = SaleReturnCustody::where('sale_return_id', $a->id)->get();
        $this->assertCount(2, $rowsA);
        $this->assertEquals(2.0, (float) $rowsA->firstWhere('location_id', $this->owned->id)->quantity_received);
        $this->assertEquals(1.0, (float) $rowsA->firstWhere('location_id', 1)->quantity_received);

        // Replaying the receive of the same return writes no second custody or apportionment.
        $custodyCount = SaleReturnCustody::count();
        $apportionments = ConsignmentBuyoutReturnApportionment::count();
        $this->receive($a)->assertRedirect();
        $this->assertSame($custodyCount, SaleReturnCustody::count());
        $this->assertSame($apportionments, ConsignmentBuyoutReturnApportionment::count());

        // 3 acquired units: 2 already returned => at most 1 can still be declared acquired.
        $b = $this->newReturn($f['flow'], 2);
        $detailB = SaleReturnDetail::where('sale_return_id', $b->id)->sole();
        try {
            $svc->declare($detailB, 2);
            $this->fail('acquired quantity exceeded');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('exceeds', $e->getMessage());
        }
        $svc->declare($detailB, 1);
        $this->receive($b)->assertRedirect();

        // Acquired units are now exhausted: a further return is unambiguously unacquired.
        $c = $this->newReturn($f['flow'], 1);
        $this->receive($c)->assertRedirect();
        $this->assertSame('awaiting settlement', strtolower($c->fresh()->status));
        $this->assertSame(1, SaleReturnCustody::where('sale_return_id', $c->id)->sole()->location_id);

        $acquiredTotal = (float) ConsignmentBuyoutReturnApportionment::sum('acquired_quantity');
        $this->assertSame(3.0, $acquiredTotal, 'never more than the 3 acquired units');
        $this->assertSame(0.0, $this->stockAt($f['flow']['product']->id, $this->owned->id));
    }

    /** @test */
    public function a_declaration_cannot_understate_the_acquired_quantity_even_across_partial_returns(): void
    {
        $f = $this->soldAndAcquired(sold: 10, acquired: 5);
        $svc = new ConsignmentBuyoutReturnOwnershipService();

        // Return all ten declaring zero acquired: five of them are company-owned, so this is refused at declaration...
        $all = $this->newReturn($f['flow'], 10);
        $detail = SaleReturnDetail::where('sale_return_id', $all->id)->sole();
        try {
            $svc->declare($detail, 0);
            $this->fail('all ten units classified as unacquired');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('unacquired', $e->getMessage());
        }
        // ...and at receiving, even if the declaration row were written around the service.
        ConsignmentBuyoutReturnApportionment::create(['setting_id' => 1, 'sale_return_detail_id' => $detail->id, 'sale_return_id' => $all->id,
            'dispatch_detail_id' => $detail->dispatch_detail_id, 'consignment_sold_source_id' => $f['source']->id, 'status' => 'DECLARED', 'origin' => 'EXPLICIT',
            'returned_quantity' => 10, 'acquired_quantity' => 0, 'unacquired_quantity' => 10]);
        $this->receive($all)->assertRedirect();
        $this->assertSame('awaiting receiving', strtolower($all->fresh()->status));
        $this->assertSame(0, SaleReturnCustody::count());
        $this->assertSame('DECLARED', ConsignmentBuyoutReturnApportionment::where('sale_return_detail_id', $detail->id)->value('status'));
        ConsignmentBuyoutReturnApportionment::where('sale_return_detail_id', $detail->id)->delete();

        // Cumulative: five unacquired units exist. 3 unacquired is fine; a second 3 unacquired would hide acquired units.
        $a = $this->newReturn($f['flow'], 3);
        $svc->declare(SaleReturnDetail::where('sale_return_id', $a->id)->sole(), 0);
        $this->receive($a)->assertRedirect();
        $this->assertSame('awaiting settlement', strtolower($a->fresh()->status));

        $b = $this->newReturn($f['flow'], 3);
        $detailB = SaleReturnDetail::where('sale_return_id', $b->id)->sole();
        try {
            $svc->declare($detailB, 0);
            $this->fail('cumulative understatement accepted');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('unacquired', $e->getMessage());
        }
        $svc->declare($detailB, 1);       // 2 unacquired (room 2) + 1 acquired is the only honest split
        $this->receive($b)->assertRedirect();
        $this->assertSame('awaiting settlement', strtolower($b->fresh()->status));
        $this->assertEquals(1.0, (float) ConsignmentBuyoutReturnApportionment::where('sale_return_detail_id', $detailB->id)->value('acquired_quantity'));
    }

    /** @test */
    public function serialized_returns_are_routed_by_exact_serial_ownership(): void
    {
        $f = $this->soldAndAcquired(sold: 3, acquired: 2, serial: true, acquiredSerialIdx: [0, 1]);
        $ids = array_map(fn ($s) => $s->id, $f['flow']['serials']);
        $return = $this->newReturn($f['flow'], 3, $ids);

        $this->receive($return)->assertRedirect();
        $this->assertSame('awaiting settlement', strtolower($return->fresh()->status));

        $byLocation = SaleReturnCustody::where('sale_return_id', $return->id)->get()->groupBy('location_id');
        $this->assertCount(2, $byLocation[$this->owned->id], 'two acquired serials held for the owned destination');
        $this->assertCount(1, $byLocation[1], 'the unacquired serial keeps its original route');
        $this->assertEquals(
            [$f['flow']['serials'][0]->id, $f['flow']['serials'][1]->id],
            $byLocation[$this->owned->id]->pluck('product_serial_number_id')->sort()->values()->map(fn ($i) => (int) $i)->all()
        );
        foreach ([0, 1] as $i) {
            $this->assertSame($this->owned->id, (int) $f['flow']['serials'][$i]->fresh()->location_id);
            $this->assertTrue((bool) $f['flow']['serials'][$i]->fresh()->is_in_return_process, 'held until inspection');
            $this->assertSame($f['receipt']->id, (int) $f['flow']['serials'][$i]->fresh()->consignment_receiving_detail_id, 'receipt lineage preserved');
        }
        $this->assertSame(1, (int) ConsignmentBuyoutReturnApportionment::value('unacquired_quantity'));
        $this->assertSame(1.0, $this->capacity->soldSourceCapacity($f['source']->fresh())['effective_returned']);
    }

    /** @test */
    public function sales_without_a_finalized_buyout_keep_the_original_route_and_create_no_apportionment(): void
    {
        $flow = $this->makeDispatchedSale(4);
        $return = $this->newReturn($flow, 2);
        $this->receive($return)->assertRedirect();

        $row = SaleReturnCustody::where('sale_return_id', $return->id)->sole();
        $this->assertSame(1, (int) $row->location_id);
        $this->assertSame(0, ConsignmentBuyoutReturnApportionment::count());
    }

    private function reserveAll(ConsignmentReceivingDetail $receipt, int $qty): ConsignmentBuyout
    {
        $svc = new ConsignmentBuyoutLifecycleService();
        $b = $svc->createDraft(1, $this->supplier->id, '2026-11-11', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $receipt->id, 'destination_location_id' => $this->owned->id,
            'base_quantity' => (string) $qty, 'buckets' => ['NON_TAXED' => (string) $qty]]], $this->actor);

        return $svc->approve($svc->submit($b, $this->actor), $this->actor);
    }

    /** @test */
    public function replacement_dispatch_for_an_unacquired_return_cannot_consume_stock_reserved_by_another_buyout(): void
    {
        $f = $this->soldAndAcquired(sold: 6, acquired: 3);       // location 1 holds the 4 unsold units
        $reserve = $this->reserveAll($f['receipt'], 4);          // ...and an approved unsold buyout reserves all 4

        $return = $this->newReturn($f['flow'], 2);
        (new ConsignmentBuyoutReturnOwnershipService())->declare(SaleReturnDetail::where('sale_return_id', $return->id)->sole(), 0);
        $this->receive($return)->assertRedirect();
        $custody = SaleReturnCustody::where('sale_return_id', $return->id)->sole();
        $this->assertSame(1, (int) $custody->location_id, 'unacquired units keep the original route');

        $row = $this->settlementFor($return->fresh(), Settlement::STATUS_APPROVED_AWAITING_DISPATCH, 'REPAIR');
        $stockBefore = $this->stockAt($f['flow']['product']->id, 1);

        // The request already refuses reserved goods as the replacement source.
        $this->http()->post(route('sale-return-settlements.item.dispatch', $row), ['dispatch_mode' => 'REPLACEMENT'])->assertRedirect();
        $this->assertNull($row->fresh()->dispatch_kind);
        $this->assertSame(Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $row->fresh()->status);

        // Release the goods, request the replacement, then a NEW buyout reserves them before approval: approval refuses.
        (new ConsignmentBuyoutLifecycleService())->cancel($reserve, 'release', $this->actor);
        $this->http()->post(route('sale-return-settlements.item.dispatch', $row), ['dispatch_mode' => 'REPLACEMENT'])->assertRedirect();
        $this->assertSame('REPLACEMENT', $row->fresh()->dispatch_kind);
        // (Held returns make a normal approval impossible - the pool is ambiguous until release - so the late claim is
        // recorded directly, exactly as an approval that raced past the request would have left it.)
        $second = ConsignmentBuyout::create(['setting_id' => 1, 'supplier_id' => $this->supplier->id, 'reference' => 'BO-LATE', 'status' => 'APPROVED', 'date' => now()]);
        $lateLine = \Modules\Consignment\Entities\ConsignmentBuyoutLine::create(['consignment_buyout_id' => $second->id, 'line_type' => 'UNSOLD_BUYOUT',
            'consignment_receiving_detail_id' => $f['receipt']->id, 'product_id' => $f['flow']['product']->id, 'source_location_id' => 1,
            'destination_location_id' => $this->owned->id, 'base_quantity' => '4', 'original_snapshot' => []]);
        \Modules\Consignment\Entities\ConsignmentBuyoutReservation::create(['consignment_buyout_id' => $second->id, 'consignment_buyout_line_id' => $lateLine->id,
            'revision' => 1, 'resource_type' => 'PHYSICAL_BUCKET', 'product_id' => $f['flow']['product']->id, 'location_id' => 1, 'tax_bucket' => 'NON_TAXED',
            'base_quantity' => '4', 'status' => 'ACTIVE']);

        $this->http()->post(route('sale-return-settlements.item.dispatch.approve', $row))->assertRedirect();
        $this->assertSame(Settlement::STATUS_DISPATCH_REQUESTED, $row->fresh()->status, 'reserved goods cannot be consumed at approval');
        $this->assertSame($stockBefore, $this->stockAt($f['flow']['product']->id, 1));
        $this->assertEquals(2, $custody->fresh()->held_quantity);
        $this->assertSame(1, $second->fresh()->reservations()->where('status', 'ACTIVE')->count());

        \DB::table('consignment_buyout_reservations')->where('consignment_buyout_id', $second->id)->update(['status' => 'RELEASED']);
        $this->http()->post(route('sale-return-settlements.item.dispatch.approve', $row))->assertRedirect();
        $this->assertSame(Settlement::STATUS_DISPATCHED, $row->fresh()->status);
        $this->assertSame($stockBefore - 2, $this->stockAt($f['flow']['product']->id, 1));
    }

    /** @test */
    public function held_returned_units_are_not_selectable_as_unsold_buyout_stock_until_released(): void
    {
        $f = $this->soldAndAcquired(sold: 6, acquired: 3);
        $return = $this->newReturn($f['flow'], 2);
        (new ConsignmentBuyoutReturnOwnershipService())->declare(SaleReturnDetail::where('sale_return_id', $return->id)->sole(), 0);
        $this->receive($return)->assertRedirect();

        // 4 sellable units are on the shelf but the receipt says 6 are supplier-owned (2 returned units are only HELD).
        $pool = $this->capacity->physicalPool(1, $f['flow']['product']->id, 1);
        $this->assertSame(0.0, $pool['physically_available']);
        $this->assertStringContainsString('ambiguous', implode(' ', $pool['blockers']));
        try {
            $this->reserveAll($f['receipt'], 1);
            $this->fail('held stock was selectable');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('ambiguous', $e->getMessage());
        }

        // After the authorized release the units are sellable again and selectable.
        $this->approveCashRefund($return->fresh());
        $custody = SaleReturnCustody::where('sale_return_id', $return->id)->sole();
        app(StandardReturnCustodyService::class)->release($return->fresh(), [['custody_id' => $custody->id, 'quantity' => 2]], 'baik', 'rel-held');
        $this->assertSame(6.0, $this->capacity->physicalPool(1, $f['flow']['product']->id, 1)['physically_available']);
    }

    /** @test */
    public function releasing_an_acquired_return_takes_the_coordination_lock_first_and_a_failed_release_rolls_back_completely(): void
    {
        $f = $this->soldAndAcquired(sold: 6, acquired: 6);
        $return = $this->newReturn($f['flow'], 2);
        $this->receive($return)->assertRedirect();
        $custody = SaleReturnCustody::where('sale_return_id', $return->id)->sole();
        $this->approveCashRefund($return->fresh());
        $apportionment = ConsignmentBuyoutReturnApportionment::first()->toArray();
        $productId = $f['flow']['product']->id;

        // 1. Lock order: the (business, product) coordination row is read/locked before any stock or custody row.
        $order = [];
        \DB::listen(function ($q) use (&$order) {
            $sql = strtolower($q->sql);
            foreach (['consignment_inventory_coordinations' => 'coord', 'product_stocks' => 'stock', 'sale_return_custodies' => 'custody'] as $table => $tag) {
                if (! str_contains($sql, "\"{$table}\"")) {
                    continue;
                }
                // The unlocked product-id discovery read (select "product_id" ...) is not a lock; only row reads/writes count.
                $isRowAccess = str_starts_with($sql, 'update') || str_starts_with($sql, 'select *') || str_starts_with($sql, 'select "' . $table . '".*');
                if ($isRowAccess) {
                    $order[] = $tag;
                }
            }
        });

        // 2. A failure after the custody/stock writes (when the ledger row is created) must undo everything.
        \Illuminate\Support\Facades\Event::listen('eloquent.creating: ' . \Modules\Product\Entities\Transaction::class,
            fn () => throw new \RuntimeException('injected failure inside release'));
        try {
            app(StandardReturnCustodyService::class)->release($return->fresh(), [['custody_id' => $custody->id, 'quantity' => 2]], 'baik', 'rel-fail');
            $this->fail('release should have failed');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected failure inside release', $e->getMessage());
        }
        \Illuminate\Support\Facades\Event::forget('eloquent.creating: ' . \Modules\Product\Entities\Transaction::class);

        $this->assertEquals(2, $custody->fresh()->held_quantity, 'custody untouched');
        $this->assertSame(0.0, $this->stockAt($productId, $this->owned->id), 'nothing posted at the owned destination');
        $this->assertSame(0, \Modules\SalesReturn\Entities\SaleReturnInspection::count());
        $this->assertEquals($apportionment, ConsignmentBuyoutReturnApportionment::first()->toArray(), 'ownership evidence unchanged');
        $firstCoord = array_search('coord', $order, true);
        $firstStock = array_search('stock', $order, true);
        $firstCustody = array_search('custody', $order, true);
        $this->assertNotFalse($firstCoord);
        $this->assertTrue($firstCoord < $firstStock && $firstCoord < $firstCustody, 'coordination lock precedes stock and custody locks: ' . implode(',', array_slice($order, 0, 6)));

        // 3. The retry succeeds, posts exactly once to the owned destination and does not touch supplier capacity.
        $capBefore = $this->capacitySnapshot($f['source'], $f['receipt']);
        app(StandardReturnCustodyService::class)->release($return->fresh(), [['custody_id' => $custody->id, 'quantity' => 2]], 'baik', 'rel-ok');
        $this->assertSame(2.0, $this->stockAt($productId, $this->owned->id));
        $this->assertSame(4.0, $this->stockAt($productId, 1));
        $this->assertEquals($capBefore, $this->capacitySnapshot($f['source'], $f['receipt']));
        $this->assertDatabaseHas('consignment_inventory_coordinations', ['setting_id' => 1, 'product_id' => $productId]);
    }
}
