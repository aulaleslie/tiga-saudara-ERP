<?php

namespace Modules\Consignment\Tests\Unit;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Services\ConsignmentBuyoutCapacityService;
use Modules\Consignment\Services\ConsignmentBuyoutFinalizationService;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Consignment\Tests\Support\BuildsFinalizedBuyout;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Location;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Internal finalization (disabled by default): atomic ownership acquisition + Purchase, with rollback, idempotency
 * and revalidation checks. Deterministic SQLite tests, not concurrency proof.
 */
class ConsignmentBuyoutFinalizationTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;
    use BuildsFinalizedBuyout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinalizationFixtures();
    }

    /** @test */
    public function compatible_sold_and_unsold_slices_create_one_product_detail_with_separate_lineage(): void
    {
        [$buyout] = $this->mixed();
        $purchase = $this->fin->finalize($buyout, $this->user);

        $this->assertSame(1, $purchase->purchaseDetails()->count());
        $detail = $purchase->purchaseDetails()->firstOrFail();
        $this->assertEquals(5, $detail->quantity);
        $this->assertEquals(500, $detail->sub_total);
        $this->assertEquals(100, $detail->unit_price);
        $links = \Modules\Consignment\Entities\ConsignmentBuyoutPurchaseLineage::where('purchase_id', $purchase->id)->get();
        $this->assertCount(2, $links);
        $this->assertSame([$detail->id], $links->pluck('purchase_detail_id')->unique()->values()->all());
        $this->assertEquals(5, $links->sum('base_quantity'));
        $this->assertSame(0, \Modules\Purchase\Entities\ReceivedNote::where('po_id', $purchase->id)->count());
        $this->assertSame($purchase->id, $this->fin->finalize($buyout->fresh(), $this->user)->id);
        $this->assertSame(1, $purchase->purchaseDetails()->count());
    }

    /** @test */
    public function finalization_allows_an_omitted_supplier_invoice_number(): void
    {
        [$buyout] = $this->mixed(null, null, ['invoice_date' => '2026-11-10']);
        $purchase = $this->fin->finalize($buyout, $this->user);

        $this->assertNull($purchase->supplier_purchase_number);
        $this->assertNotEmpty($purchase->reference);
        $this->assertSame($purchase->id, $this->fin->finalize($buyout->fresh(), $this->user)->id);
    }

    /** @test */
    public function finalization_allows_a_blank_supplier_invoice_number(): void
    {
        [$buyout] = $this->mixed(null, null, ['invoice_date' => '2026-11-10', 'supplier_invoice_number' => '   ']);
        $purchase = $this->fin->finalize($buyout, $this->user);

        $this->assertNull($purchase->supplier_purchase_number);
        $this->assertNotEmpty($purchase->reference);
    }

    /** @test */
    public function mixed_finalization_bills_five_moves_three_and_replaces_the_average_once(): void
    {
        [$b, $r] = $this->mixed(null, 120.0);
        $before = $this->snapshot();

        $purchase = $this->fin->finalize($b, $this->user);

        $this->assertSame(Purchase::SOURCE_CONSIGNMENT_BUYOUT, $purchase->source_type);
        $this->assertSame(Purchase::STATUS_RECEIVED, $purchase->status);
        $this->assertSame('Unpaid', $purchase->payment_status);
        $this->assertEquals(560.0, (float) $purchase->total_amount);          // sold 2x100 + unsold 3x120
        $this->assertEquals(560.0, (float) $purchase->due_amount);
        $this->assertSame('INV-001', $purchase->supplier_purchase_number);
        $this->assertSame(2, $purchase->purchaseDetails()->count());
        $this->assertSame(0, \DB::table('received_notes')->where('po_id', $purchase->id)->count(), 'no Received Notes: no second receiving');

        // 3 units moved from the consignment location to the destination (row created), business total unchanged.
        $this->assertEquals(2, ProductStock::where('product_id', $this->product->id)->where('location_id', $this->location->id)->value('quantity'));
        $this->assertEquals(3, ProductStock::where('product_id', $this->product->id)->where('location_id', $this->dest1->id)->value('quantity'));
        $this->assertEquals(3, ProductStock::where('product_id', $this->product->id)->where('location_id', $this->dest1->id)->value('quantity_non_tax'));
        $this->assertEquals($before['products'], \DB::table('products')->orderBy('id')->pluck('product_quantity', 'id')->all(), 'global quantity unchanged');
        $this->assertEquals(10, ProductStock::where('product_id', $this->product->id)->sum('quantity'));

        // Paired signed transactions + movement evidence.
        $txns = \DB::table('transactions')->where('type', 'CONSIGNMENT_BUYOUT')->orderBy('id')->get();
        $this->assertEquals([-3.0, 3.0], $txns->pluck('quantity')->map(fn ($q) => (float) $q)->all());
        $this->assertSame(2, \DB::table('consignment_buyout_movements')->count());

        // One carrying-value replacement: (1000 - 300 + 360) / 10 = 106; last purchase price untouched.
        $price = ProductPrice::first();
        $this->assertEquals(106.0, (float) $price->average_purchase_price);
        $this->assertEquals(777.0, (float) $price->last_purchase_price);
        $event = \DB::table('consignment_buyout_cost_events')->first();
        $this->assertSame(1, \DB::table('consignment_buyout_cost_events')->count());
        $this->assertEquals([100.0, 106.0, 3.0, 360.0], [(float) $event->average_before, (float) $event->average_after, (float) $event->acquired_quantity, (float) $event->acquired_cost_total]);

        // Reservations consumed (not also reserved), capacity reconciles: 10 received = 5 sold + 3 acquired + 2 left.
        $fresh = $b->fresh();
        $this->assertSame('FINALIZED', $fresh->status);
        $this->assertSame($purchase->id, (int) $fresh->purchase_id);
        $this->assertSame(0, $fresh->reservations()->where('status', 'ACTIVE')->count());
        $cap = $this->capacity()->receiptCapacity($r->id);
        $this->assertEquals([5.0, 5.0, 2.0, 2.0], [$cap['buyout_finalized'], $cap['financial_available'], $cap['physical_residual'], $cap['unsold_available']]);
        $this->assertSame(['DRAFT_CREATED', 'SUBMITTED', 'APPROVED', 'FINALIZED'], $fresh->auditEvents()->orderBy('id')->pluck('action')->all());
    }

    /** @test */
    public function splitting_a_receipt_across_destinations_never_increases_capacity_and_costs_once(): void
    {
        $r = $this->receipt($this->supplier, '10');
        $this->price($r, 100);
        $this->stock('10');
        ProductPrice::create(['product_id' => $this->product->id, 'setting_id' => $this->setting->id, 'sale_price' => 1,
            'last_purchase_price' => 1, 'average_purchase_price' => 100]);

        $split = fn (string $a, string $c) => [
            ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id, 'destination_location_id' => $this->dest1->id, 'base_quantity' => $a, 'buckets' => ['NON_TAXED' => $a]],
            ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id, 'destination_location_id' => $this->dest2->id, 'base_quantity' => $c, 'buckets' => ['NON_TAXED' => $c]],
        ];

        // 6 + 6 from a receipt of 10 is rejected even though each destination line alone is fine.
        $over = $this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', $split('6', '6'), $this->user, $this->meta());
        try {
            $this->life->submit($over, $this->user);
            $this->fail('split lines exceeded the receipt');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('exceeds', $e->getMessage());
        }

        // 4 + 4 is approved and reserves 8 in total: a further single line of 3 (11 > 10) is rejected.
        $ok = $this->life->approve($this->life->submit($this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', $split('4', '4'), $this->user, $this->meta()), $this->user), $this->user);
        $this->assertSame(2.0, $this->capacity()->receiptCapacity($r->id)['unsold_available']);
        $third = $this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [$split('3', '1')[0]], $this->user, $this->meta());
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->life->submit($third, $this->user);
        } finally {
            // The 8 split units finalize with ONE average replacement across both destinations: (1000-800+800)/10 = 100.
            $this->fin->finalize($ok, $this->user);
            $this->assertSame(1, \DB::table('consignment_buyout_cost_events')->count());
            $this->assertEquals(8.0, (float) \DB::table('consignment_buyout_cost_events')->value('acquired_quantity'));
            $this->assertEquals(100.0, (float) ProductPrice::first()->average_purchase_price);
            $this->assertEquals(4, ProductStock::where('location_id', $this->dest1->id)->sum('quantity'));
            $this->assertEquals(4, ProductStock::where('location_id', $this->dest2->id)->sum('quantity'));
        }
    }

    /** @test */
    public function injected_failures_after_each_write_stage_roll_everything_back_and_a_clean_retry_succeeds(): void
    {
        [$b] = $this->mixed(null, 120.0);
        $before = $this->snapshot();

        foreach (['purchase', 'movement', 'serial', 'cost', 'audit'] as $stage) {
            $svc = new ConsignmentBuyoutFinalizationService();
            $svc->stageHook = function (string $s) use ($stage) {
                if ($s === $stage) {
                    throw new \RuntimeException("injected after {$stage}");
                }
            };
            try {
                $svc->finalize($b->fresh(), $this->user);
                $this->fail("no failure at {$stage}");
            } catch (\RuntimeException $e) {
                $this->assertSame("injected after {$stage}", $e->getMessage());
            }
            $this->assertNothingPosted($b, $before);
            $this->assertSame(0, \DB::table('consignment_buyout_audit_events')->where('action', 'FINALIZED')->count());
        }

        $this->fin->finalize($b->fresh(), $this->user);
        $this->assertSame('FINALIZED', $b->fresh()->status);
    }

    /** @test */
    public function injected_failures_cover_serial_moves_and_history(): void
    {
        \DB::table('products')->where('id', $this->product->id)->update(['serial_number_required' => true]);
        $r = $this->receipt($this->supplier, '2');
        $this->price($r, 100);
        $this->stock('2');
        $this->std($this->otherStd, 2);
        ProductPrice::create(['product_id' => $this->product->id, 'setting_id' => $this->setting->id, 'sale_price' => 1, 'last_purchase_price' => 1, 'average_purchase_price' => 100]);
        $mk = fn (string $sn, $tax = null) => ProductSerialNumber::create(['product_id' => $this->product->id, 'location_id' => $this->location->id,
            'serial_number' => $sn, 'consignment_receiving_detail_id' => $r->id, 'status' => 'ACTIVE', 'tax_id' => $tax]);
        $s1 = $mk('FS-1');
        $s2 = $mk('FS-2');
        $b = $this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id, 'destination_location_id' => $this->dest1->id,
            'base_quantity' => '2', 'serial_ids' => [$s1->id, $s2->id]]], $this->user, $this->meta());
        $b = $this->life->approve($this->life->submit($b, $this->user), $this->user);
        $before = $this->snapshot();

        foreach (['movement', 'serial', 'cost', 'audit'] as $stage) {
            $svc = new ConsignmentBuyoutFinalizationService();
            $svc->stageHook = fn (string $s) => $s === $stage ? throw new \RuntimeException("injected {$stage}") : null;
            try {
                $svc->finalize($b->fresh(), $this->user);
                $this->fail("no failure at {$stage}");
            } catch (\RuntimeException $e) {
                $this->assertSame("injected {$stage}", $e->getMessage());
            }
            $this->assertEquals($before, $this->snapshot());
            $this->assertSame($this->location->id, (int) $s1->fresh()->location_id);
            $this->assertSame(0, \DB::table('serial_number_histories')->where('event_type', 'CONSIGNMENT_BUYOUT_ACQUIRED')->count());
            $this->assertSame(2, \DB::table('consignment_active_serial_claims')->count(), 'claims remain until the posting commits');
        }

        $this->fin->finalize($b->fresh(), $this->user);
        $this->assertSame($this->dest1->id, (int) $s1->fresh()->location_id);
        $this->assertSame($r->id, (int) $s1->fresh()->consignment_receiving_detail_id, 'receipt lineage preserved');
        $this->assertSame(2, \DB::table('serial_number_histories')->where('event_type', 'CONSIGNMENT_BUYOUT_ACQUIRED')->count());
        $this->assertSame(2, \DB::table('consignment_buyout_ownership_events')->count());
        $this->assertEquals(2, ProductStock::where('location_id', $this->dest1->id)->sum('quantity'));
    }

    /** @test */
    public function repeated_finalization_returns_one_purchase_and_one_posting_set(): void
    {
        [$b] = $this->mixed(null, 120.0);
        $first = $this->fin->finalize($b->fresh(), $this->user);
        $counts = $this->postingCounts();
        $snap = $this->snapshot();

        $again = $this->fin->finalize($b->fresh(), $this->user);
        $stale = $this->fin->finalize($b, $this->user);    // a stale in-memory copy still replays

        $this->assertSame($first->id, $again->id);
        $this->assertSame($first->id, $stale->id);
        $this->assertSame($counts, $this->postingCounts());
        $this->assertEquals($snap, $this->snapshot());
        $this->assertSame(1, \DB::table('purchases')->count());
        $this->assertSame(1, $counts['finalized_audit']);
    }

    /** @test */
    public function an_incomplete_committed_posting_set_is_reported_never_reposted(): void
    {
        [$b] = $this->mixed(null, 120.0);
        $this->fin->finalize($b->fresh(), $this->user);
        \DB::table('consignment_buyout_movements')->limit(1)->delete();

        $this->expectException(\RuntimeException::class);
        $this->fin->finalize($b->fresh(), $this->user);
    }

    /** @test */
    public function finalization_is_disabled_unless_explicitly_enabled_and_requires_composed_authority(): void
    {
        [$b] = $this->mixed(null, 120.0);
        $before = $this->snapshot();

        config(['consignment.buyouts.finalization_enabled' => false]);
        try {
            $this->fin->finalize($b->fresh(), $this->user);
            $this->fail('finalization ran while disabled');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('not enabled', $e->getMessage());
        }
        config(['consignment.buyouts.finalization_enabled' => true]);

        foreach (['consignments.buyouts.finalize', 'consignments.billing.convert', 'consignments.buyouts.acquire_unsold'] as $missing) {
            $partial = User::factory()->create();
            $partial->settings()->attach($this->setting->id, ['role_id' => Role::findOrCreate('BR', 'web')->id]);
            foreach (array_diff(['consignments.buyouts.finalize', 'consignments.billing.convert', 'consignments.buyouts.acquire_unsold'], [$missing]) as $p) {
                $partial->givePermissionTo($p);
            }
            try {
                $this->fin->finalize($b->fresh(), $partial);
                $this->fail("finalized without {$missing}");
            } catch (AuthorizationException $e) {
                $this->assertStringContainsString($missing, $e->getMessage());
            }
        }
        $this->assertNothingPosted($b, $before);
    }

    /** @test */
    public function approved_evidence_pricing_and_reservations_are_revalidated_under_locks(): void
    {
        // 1. tampered approved terms (pricing intent is part of the approved hash)
        [$b, $r] = $this->mixed(null, 120.0);
        $before = $this->snapshot();
        \DB::table('consignment_buyouts')->where('id', $b->id)->update(['pricing_intent' => json_encode(['global_discount_type' => 'fixed', 'global_discount_value' => 1])]);
        $this->expectFailure(fn () => $this->fin->finalize($b->fresh(), $this->user), 'approved evidence changed');
        $this->assertNothingPosted($b, $before);
        \DB::table('consignment_buyouts')->where('id', $b->id)->update(['pricing_intent' => json_encode(['rows' => $b->lines()->where('line_type', 'UNSOLD_BUYOUT')->get()->mapWithKeys(fn ($l) => [$l->id => ['unit_price' => 120]])->all()])]);
        $this->assertTrue($this->life->approvedHashIsCurrent($b->fresh()));

        // 2. receipt evidence drifted after approval: accepted terms no longer match
        \DB::table('consignment_receiving_details')->where('id', $r->id)->update(['unit_dpp' => 150, 'unit_cost' => 150]);
        $this->expectFailure(fn () => $this->fin->finalize($b->fresh(), $this->user), 'no longer match');
        $this->assertNothingPosted($b, $before);
        \DB::table('consignment_receiving_details')->where('id', $r->id)->update(['unit_dpp' => 100, 'unit_cost' => 100]);

        // 3. a reservation removed behind the document's back
        $res = $b->reservations()->where('resource_type', 'PHYSICAL_BUCKET')->first();
        \DB::table('consignment_buyout_reservations')->where('id', $res->id)->update(['status' => 'RELEASED']);
        $this->expectFailure(fn () => $this->fin->finalize($b->fresh(), $this->user), 'reservations');
        $this->assertSame(0, \DB::table('purchases')->count());
        \DB::table('consignment_buyout_reservations')->where('id', $res->id)->update(['status' => 'ACTIVE']);

        // 4. the sellable stock disappeared (moved/damaged) after approval
        \DB::table('product_stocks')->where('location_id', $this->location->id)->update(['quantity_non_tax' => 1, 'quantity' => 1]);
        $this->expectFailure(fn () => $this->fin->finalize($b->fresh(), $this->user), '');
        $this->assertSame(0, \DB::table('purchases')->count());
        \DB::table('product_stocks')->where('location_id', $this->location->id)->update(['quantity_non_tax' => 5, 'quantity' => 5]);

        // everything restored: finalization now succeeds
        $this->fin->finalize($b->fresh(), $this->user);
        $this->assertSame('FINALIZED', $b->fresh()->status);
    }

    /** @test */
    public function a_missing_average_blocks_finalization_before_any_write(): void
    {
        [$b] = $this->mixed(null, 120.0);
        $before = $this->snapshot();
        \DB::table('product_prices')->update(['average_purchase_price' => null]);
        $before['price'] = $this->snapshot()['price'];

        $this->expectFailure(fn () => $this->fin->finalize($b->fresh(), $this->user), 'Carrying-value blocker');
        $this->assertNothingPosted($b, $before);
    }

    /** @test */
    public function only_approved_documents_finalize_and_missing_invoice_metadata_is_rejected(): void
    {
        [$b] = $this->mixed(null, 120.0);
        $draft = $this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $this->receipt($this->supplier, '2')->id,
            'destination_location_id' => $this->dest1->id, 'base_quantity' => '1', 'buckets' => ['NON_TAXED' => '1']]], $this->user);
        $this->expectFailure(fn () => $this->fin->finalize($draft, $this->user), 'approved buyout');

        \DB::table('consignment_buyouts')->where('id', $b->id)->update(['invoice_metadata' => null]);
        // invoice metadata is part of the approved hash, so the change is caught first
        $this->expectFailure(fn () => $this->fin->finalize($b->fresh(), $this->user), 'approved evidence changed');
        $this->assertSame(0, \DB::table('purchases')->count());
    }

    /** @test */
    public function fractional_quantities_move_exactly_and_follow_the_design_costing_example(): void
    {
        $r = $this->receipt($this->supplier, '5');
        $this->price($r, 120);
        $this->stock('5');
        ProductPrice::create(['product_id' => $this->product->id, 'setting_id' => $this->setting->id, 'sale_price' => 1,
            'last_purchase_price' => 1, 'average_purchase_price' => 100]);
        $b = $this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id, 'destination_location_id' => $this->dest1->id,
            'base_quantity' => '1.250', 'buckets' => ['NON_TAXED' => '1.250']]], $this->user, $this->meta());
        $b = $this->life->approve($this->life->submit($b, $this->user), $this->user);

        $purchase = $this->fin->finalize($b, $this->user);

        $this->assertEquals(150.0, (float) $purchase->total_amount);                           // 1.250 x 120
        $this->assertSame('3.750', number_format((float) ProductStock::where('location_id', $this->location->id)->value('quantity'), 3, '.', ''));
        $this->assertSame('1.250', number_format((float) ProductStock::where('location_id', $this->dest1->id)->value('quantity_non_tax'), 3, '.', ''));
        $this->assertSame('5.000', number_format((float) ProductStock::where('product_id', $this->product->id)->sum('quantity'), 3, '.', ''));
        $this->assertEquals(105.0, (float) ProductPrice::first()->average_purchase_price);      // (500 - 125 + 150) / 5
        $this->assertEquals([-1.25, 1.25], \DB::table('transactions')->where('type', 'CONSIGNMENT_BUYOUT')->orderBy('id')->pluck('quantity')->map(fn ($q) => (float) $q)->all());
    }

    private function expectFailure(\Closure $fn, string $contains): void
    {
        try {
            $fn();
            $this->fail("expected an exception containing [{$contains}]");
        } catch (\Throwable $e) {
            if ($e instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $e;
            }
            $this->assertStringContainsStringIgnoringCase($contains, $e->getMessage());
        }
    }
}
