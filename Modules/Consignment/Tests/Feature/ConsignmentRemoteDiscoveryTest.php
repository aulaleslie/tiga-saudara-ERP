<?php

namespace Modules\Consignment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Services\TransferV3GoodsService;
use Modules\Adjustment\Services\TransferV3ReceiptExecutor;
use Modules\Consignment\Tests\Support\BuildsRemoteProvenanceFixtures;
use Modules\Consignment\Entities\ConsignmentObligationBinding;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentBuyoutCapacityService;
use Modules\Consignment\Services\ConsignmentRemoteSaleResolver;
use Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

/**
 * Phase 3: manual cross-business discovery through verified receipt custody, immutable envelopes and
 * supplemental obligation bindings. A = origin (TOP IT-like), B = remote seller (PERDANA-like).
 */
class ConsignmentRemoteDiscoveryTest extends TestCase
{
    use RefreshDatabase;
    use BuildsRemoteProvenanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteFixtures();
    }

    /** @test */
    public function origin_discovers_the_remote_sale_with_an_immutable_envelope_and_a_verified_binding(): void
    {
        $this->moveSerial($this->sn[0], $this->ca, $this->cb);
        $dd = $this->sellAt($this->cb, [$this->sn[0]]);

        $this->discover($this->businessA);
        $src = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();
        $this->assertSame((int) $this->businessB->id, (int) $src->setting_id, 'envelope keeps the actual selling business');
        $this->assertSame($this->cb->id, (int) $src->location_id);
        $this->assertSame(strtolower($src->source_hash), strtolower(app(\Modules\Consignment\Services\ConsignmentBillingConfirmationLifecycleService::class)->computeCanonicalLiveHash($src)),
            'v2 canonical hash validates through its own version');

        $b = ConsignmentObligationBinding::sole();
        $this->assertSame((int) $this->businessA->id, (int) $b->original_setting_id);
        $this->assertSame((int) $this->businessB->id, (int) $b->seller_setting_id);
        $this->assertSame([$this->sn[0]->id], $b->serial_identities);
        $this->assertSame([], app(ConsignmentRemoteSaleResolver::class)->bindingBlockers($b));

        $a = $this->perReceipt($dd);
        $this->assertTrue($a['complete']);
        $this->assertSame([$this->receipt->id => 1.0], $a['per_receipt']);

        // Origin and remote rediscovery are idempotent.
        $hash = $src->source_hash;
        $this->discover($this->businessA);
        $this->discover($this->businessB);
        $this->assertSame(1, ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->count());
        $this->assertSame(1, ConsignmentObligationBinding::count());
        $this->assertSame($hash, $src->fresh()->source_hash);
    }

    /** @test */
    public function unrelated_foreign_sales_are_not_discovered_by_the_origin(): void
    {
        $this->moveSerial($this->sn[0], $this->ca, $this->cb);
        $other = $this->sellAt($this->cb, [], $this->bulk->id);   // product never moved there by A's provenance
        $cbOther = Location::create(['setting_id' => $this->businessB->id, 'name' => 'Konsinyasi B2', 'is_active' => true, 'is_consignment' => true]);
        $elsewhere = $this->sellAt($cbOther, []);

        $this->discover($this->businessA);
        $this->assertFalse(ConsignmentSoldSource::whereIn('dispatch_detail_id', [$other->id, $elsewhere->id])->exists());
    }

    /** @test */
    public function sales_dispatched_before_the_goods_arrived_are_not_candidates(): void
    {
        $early = $this->sellAt($this->cb, []);
        \DB::table('dispatches')->where('id', $early->dispatch_id)->update(['approved_at' => now()->subDay()]);
        $this->moveSerial($this->sn[0], $this->ca, $this->cb);

        $this->discover($this->businessA);
        $this->assertFalse(ConsignmentSoldSource::where('dispatch_detail_id', $early->id)->exists());
    }

    /** @test */
    public function transit_only_or_unmoved_serials_sold_remotely_stay_blocked(): void
    {
        $this->moveSerial($this->sn[0], $this->ca, $this->cb, receive: false);   // still in transit
        $this->moveSerial($this->sn[1], $this->ca, $this->cb);                    // makes CB reachable
        $transit = $this->sellAt($this->cb, [$this->sn[0]]);
        $substituted = $this->sellAt($this->cb, [$this->sn[2]]);                  // never left CA

        $this->discover($this->businessA);
        foreach ([$transit, $substituted] as $dd) {
            $a = $this->perReceipt($dd);
            $this->assertFalse($a['complete']);
            $this->assertSame([], $a['per_receipt']);
            $this->assertStringContainsString('no completed receipt custody path', implode(' ', $a['blockers']));
        }
        $this->assertSame(0, ConsignmentObligationBinding::count());
    }

    /** @test */
    public function moved_back_serial_resolves_through_its_full_path_at_dispatch_time(): void
    {
        $this->moveSerial($this->sn[0], $this->ca, $this->cb);
        $this->moveSerial($this->sn[0], $this->cb, $this->ca);
        $dd = $this->sellAt($this->ca, [$this->sn[0]]);

        $this->discover($this->businessA);
        $b = ConsignmentObligationBinding::sole();
        $this->assertSame((int) $this->businessA->id, (int) $b->seller_setting_id);
        $this->assertCount(4, $b->custody_path, 'dispatch+receipt there and back');
        $this->assertTrue($this->perReceipt($dd)['complete']);
    }

    /** @test */
    public function a_reversed_receipt_or_tampered_binding_is_a_blocker(): void
    {
        $this->moveSerial($this->sn[0], $this->ca, $this->cb);
        $this->sellAt($this->cb, [$this->sn[0]]);
        $this->discover($this->businessA);
        $b = ConsignmentObligationBinding::sole();

        \DB::table('consignment_obligation_bindings')->where('id', $b->id)->update(['base_quantity' => 2]);
        $this->assertStringContainsString('hash mismatch', implode(' ', app(ConsignmentRemoteSaleResolver::class)->bindingBlockers($b->fresh())));
        \DB::table('consignment_obligation_bindings')->where('id', $b->id)->update(['base_quantity' => 1]);

        \DB::table('consignment_receivings')->update(['status' => 'REVERSED']);
        $this->assertStringContainsString('not approved', implode(' ', app(ConsignmentRemoteSaleResolver::class)->bindingBlockers($b->fresh())));
    }

    /** @test */
    public function local_sales_need_no_binding(): void
    {
        $dd = $this->sellAt($this->ca, [$this->sn[2]]);
        $this->discover($this->businessA);
        $this->assertSame(0, ConsignmentObligationBinding::count());
        $this->assertSame([$this->receipt->id => 1.0], $this->perReceipt($dd)['per_receipt']);
    }

    /** @test */
    public function non_serialized_remote_sale_needs_explicit_back_office_attribution(): void
    {
        $lot = $this->bulkReceipt(5);
        $this->stock($this->bulk, $this->ca, nonTax: 5);
        $this->moveBulk($lot, 3);
        $dd = $this->sellAt($this->cb, [], $this->bulk->id);
        \DB::table('dispatch_details')->where('id', $dd->id)->update(['dispatched_quantity' => 2]);

        $this->discover($this->businessA);
        $src = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();
        $before = $this->perReceipt($dd);
        $this->assertFalse($before['complete'], 'checkout already completed; attribution pending blocks only dependents');
        $this->assertSame(0, ConsignmentObligationBinding::count(), 'nothing auto-attributed');

        $resolver = app(ConsignmentRemoteSaleResolver::class);
        $this->assertSame($lot->id, $resolver->proposeLotAttribution($src)['receipt_id'], 'sole lot proposed for confirmation');

        $cap = app(ConsignmentBuyoutCapacityService::class);
        try {
            $cap->setAttribution($src->id, [$lot->id => 3], $this->actor->id, 'too much');
            $this->fail('over-attribution accepted');
        } catch (\DomainException $e) {
        }
        $cap->setAttribution($src->id, [$lot->id => 2], $this->actor->id, 'Konfirmasi lot tunggal');

        $b = ConsignmentObligationBinding::sole();
        $this->assertSame('CONFIRMED_LOT', $b->attribution_mode);
        $this->assertSame((int) $this->businessA->id, (int) $b->original_setting_id);
        $after = $this->perReceipt($dd);
        $this->assertTrue($after['complete']);
        $this->assertEquals([$lot->id => 2.0], $after['per_receipt']);

        $prov = app(\Modules\Consignment\Services\ConsignmentTransferProvenanceService::class);
        $p = $prov->project($lot->fresh());
        $this->assertTrue($p['conserved']);
        $this->assertEqualsWithDelta(1.0, (new \Modules\Consignment\Services\ConsignmentCustodyProjection)->quantityAt($p, 'SELLABLE', $this->cb->id), 0.001);
        $this->assertEqualsWithDelta(2.0, (new \Modules\Consignment\Services\ConsignmentCustodyProjection)->quantityAt($p, 'SELLABLE', $this->ca->id), 0.001);
        $this->assertEqualsWithDelta(2.0, $p['sold'], 0.001);

        // Re-attribution supersedes the binding revision; it is never edited in place.
        $cap->setAttribution($src->id, [$lot->id => 2], $this->actor->id, 'same');
        $this->assertSame(1, ConsignmentObligationBinding::count());
    }

    /** @test */
    public function several_remote_lots_give_no_proposal(): void
    {
        $l1 = $this->bulkReceipt(2);
        $l2 = $this->bulkReceipt(2);
        $this->stock($this->bulk, $this->ca, nonTax: 4);
        $this->moveBulk($l1, 1);
        $this->moveBulk($l2, 1);
        $dd = $this->sellAt($this->cb, [], $this->bulk->id);

        $this->discover($this->businessA);
        $p = app(ConsignmentRemoteSaleResolver::class)->proposeLotAttribution(ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail());
        $this->assertNull($p['receipt_id']);
        $this->assertEqualsCanonicalizing([$l1->id, $l2->id], $p['candidates']);
    }

    /** @test */
    public function one_source_split_across_original_businesses_is_consumed_once_per_exact_pair(): void
    {
        $topIt = $this->bulkReceipt(3);                       // business A receipt, moved to B
        $this->stock($this->bulk, $this->ca, nonTax: 3);
        $this->moveBulk($topIt, 2);
        // business B's own local receipt at CB
        $local = $this->bulkReceipt(1);
        \DB::table('consignment_receivings')->where('id', $local->consignment_receiving_id)->update(['setting_id' => $this->businessB->id, 'location_id' => $this->cb->id]);
        \DB::table('consignment_receivals')->where('id', $local->receiving->consignment_receival_id)->update(['setting_id' => $this->businessB->id]);
        $dd = $this->sellAt($this->cb, [], $this->bulk->id);
        \DB::table('dispatch_details')->where('id', $dd->id)->update(['dispatched_quantity' => 3]);
        $this->discover($this->businessA);
        $src = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();

        $cap = app(ConsignmentBuyoutCapacityService::class);
        $cap->setAttribution($src->id, [$topIt->id => 2, $local->id => 1], $this->actor->id, 'split');
        $this->assertSame(1, ConsignmentObligationBinding::count(), 'only the transferred share needs a binding');
        $this->assertEqualsWithDelta(2.0, $cap->soldReceiptResidual($src->fresh(), $topIt->id), 0.001);
        $this->assertEqualsWithDelta(1.0, $cap->soldReceiptResidual($src->fresh(), $local->id), 0.001);

        // A bills its whole share through the legacy path.
        $c = \Modules\Consignment\Entities\ConsignmentBillingConfirmation::create(['setting_id' => $this->businessA->id, 'supplier_id' => $this->supplier->id,
            'confirmation_number' => 'LEG-A', 'status' => 'APPROVED', 'date' => now(), 'source_hash' => 'x']);
        $l = \Modules\Consignment\Entities\ConsignmentBillingConfirmationLine::create(['consignment_billing_confirmation_id' => $c->id, 'consignment_sold_source_id' => $src->id,
            'product_id' => $this->bulk->id, 'location_id' => $this->cb->id, 'allocated_base_quantity' => 2, 'sold_source_snapshot' => []]);
        \Modules\Consignment\Entities\ConsignmentReceiptAllocation::create(['consignment_billing_confirmation_line_id' => $l->id, 'consignment_receiving_detail_id' => $topIt->id,
            'allocated_base_quantity' => 2, 'unit_cost' => 100, 'unit_dpp' => 100, 'tax_rate' => 0, 'tax_amount' => 0]);

        $this->assertEqualsWithDelta(0.0, $cap->soldReceiptResidual($src->fresh(), $topIt->id), 0.001);
        $this->assertEqualsWithDelta(1.0, $cap->soldReceiptResidual($src->fresh(), $local->id), 0.001, "already-billed A never consumes B's share");
        $this->assertNotEmpty($cap->validateSoldPairs([['sold_source_id' => $src->id, 'receiving_detail_id' => $topIt->id, 'quantity' => 1]]));
        $this->assertSame([], $cap->validateSoldPairs([['sold_source_id' => $src->id, 'receiving_detail_id' => $local->id, 'quantity' => 1]]));
    }

    /** @test */
    public function discovery_never_mutates_the_original_sale_dispatch_or_payment(): void
    {
        $this->moveSerial($this->sn[0], $this->ca, $this->cb);
        $dd = $this->sellAt($this->cb, [$this->sn[0]]);
        $snap = fn () => [\DB::table('sales')->get()->toArray(), \DB::table('dispatches')->get()->toArray(), \DB::table('dispatch_details')->get()->toArray(),
            \DB::table('product_stocks')->get()->toArray(), \DB::table('product_serial_numbers')->get()->toArray()];
        $before = $snap();
        app(ConsignmentSoldSourceDiscoveryService::class)->discoverForSetting($this->businessA->id, true);   // preview
        $this->assertSame(0, ConsignmentSoldSource::count(), 'preview writes nothing');
        $this->discover($this->businessA);
        $this->assertEquals($before, $snap());
    }

    /** @test */
    public function an_undiscovered_sale_in_a_multi_lot_pool_blocks_transfer_until_discovered_and_attributed(): void
    {
        $l1 = $this->bulkReceipt(3);
        $l2 = $this->bulkReceipt(3);
        $this->stock($this->bulk, $this->ca, nonTax: 4);        // 2 already sold at the register
        $dd = $this->sellAt($this->ca, [], $this->bulk->id);
        \DB::table('dispatch_details')->where('id', $dd->id)->update(['dispatched_quantity' => 2]);

        session(['setting_id' => $this->businessA->id]);
        $t = app(TransferV3GoodsService::class)->createAndSubmit(Transfer::CONDITION_GOOD,
            [['product_id' => $this->bulk->id, 'quantity' => 3, 'serial_ids' => []]], $this->actor, $this->businessA->id);
        $this->saveCompletePlan($t, [['product_id' => $this->bulk->id, 'source_location_id' => $this->ca->id, 'destination_location_id' => $this->cb->id, 'quantity' => 3]], []);
        $row = app(\Modules\Adjustment\Services\TransferV3AllocationService::class)->currentPlan($t->fresh())->first()->id;
        $prov = app(\Modules\Consignment\Services\ConsignmentTransferProvenanceService::class);
        $prov->confirmSelection($t, [$row => [$l1->id => 3]], $this->actor);

        try {
            $this->dispatchTransfer($t);
            $this->fail('sold units could be reused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('belum diatribusikan', $e->getMessage());
        }
        $this->assertSame(\Modules\Consignment\Entities\ConsignmentProvenanceEvent::count(), 0);

        $this->discover($this->businessA);
        $src = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();
        app(ConsignmentBuyoutCapacityService::class)->setAttribution($src->id, [$l1->id => 2], $this->actor->id, 'kasir menjual dari lot 1');

        // Lot 1 now has only 1 unsold: the earlier selection no longer fits and must be re-confirmed.
        try {
            $this->dispatchTransfer($t);
            $this->fail('stale lot quantity accepted');
        } catch (\RuntimeException $e) {
        }
        $prov->confirmSelection($t, [$row => [$l1->id => 1, $l2->id => 2]], $this->actor);
        $this->dispatchTransfer($t);
        $this->assertSame(Transfer::STATUS_DISPATCHED, $t->fresh()->status);
    }

    /** Review P1 regression: held non-serialized returns are not sellable receipt stock. */
    public function test_held_non_serialized_returns_are_never_offered_as_sellable_lot_stock(): void
    {
        $a = $this->bulkReceipt(5);
        $b = $this->bulkReceipt(2);
        // Physical sellable stock at the rack: A's 3 unsold + B's 2. Held return custody lives outside ProductStock.
        $this->stock($this->bulk, $this->ca, nonTax: 5);
        \DB::table('products')->where('id', $this->bulk->id)->update(['product_quantity' => 100]);

        // 2 of A sold at the rack, attributed to A, then returned and still HELD (awaiting inspection/release).
        $dd = $this->sellAt($this->ca, [], $this->bulk->id);
        \DB::table('dispatch_details')->where('id', $dd->id)->update(['dispatched_quantity' => 2]);
        $this->discover($this->businessA);
        $src = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();
        app(ConsignmentBuyoutCapacityService::class)->setAttribution($src->id, [$a->id => 2], $this->actor->id, 'kasir: lot A');
        $sale = \DB::table('sales')->where('id', $src->sale_id)->first();
        $return = \Modules\SalesReturn\Entities\SaleReturn::create(['date' => now(), 'sale_id' => $sale->id, 'sale_reference' => $sale->reference,
            'customer_id' => $sale->customer_id, 'customer_name' => $sale->customer_name, 'setting_id' => $this->businessA->id, 'location_id' => $this->ca->id,
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0, 'total_amount' => 2,
            'paid_amount' => 0, 'due_amount' => 2, 'status' => 'Awaiting Settlement', 'approval_status' => 'approved', 'payment_status' => 'Unpaid', 'payment_method' => 'Pending']);
        $rd = \Modules\SalesReturn\Entities\SaleReturnDetail::create(['sale_return_id' => $return->id, 'dispatch_detail_id' => $dd->id, 'location_id' => $this->ca->id,
            'product_id' => $this->bulk->id, 'product_name' => 'B', 'product_code' => 'B', 'quantity' => 2, 'unit_price' => 1, 'price' => 1, 'sub_total' => 2,
            'product_discount_amount' => 0, 'product_tax_amount' => 0, 'serial_number_ids' => []]);
        $custodyId = \DB::table('sale_return_custodies')->insertGetId(['sale_return_id' => $return->id, 'sale_return_detail_id' => $rd->id, 'sale_id' => $sale->id,
            'dispatch_detail_id' => $dd->id, 'product_id' => $this->bulk->id, 'owner_setting_id' => $this->businessA->id, 'location_id' => $this->ca->id,
            'policy_version' => 1, 'quantity_received' => 2, 'quantity_released' => 0, 'quantity_dispatched' => 0, 'occurrence_key' => "{$rd->id}:0",
            'created_at' => now(), 'updated_at' => now()]);

        $prov = app(\Modules\Consignment\Services\ConsignmentTransferProvenanceService::class);
        $proj = new \Modules\Consignment\Services\ConsignmentCustodyProjection();
        $pa = $prov->project($a->fresh());
        $this->assertTrue($pa['conserved']);
        $this->assertSame([], $pa['blockers']);
        $this->assertEqualsWithDelta(3.0, $proj->quantityAt($pa, 'SELLABLE', $this->ca->id), 0.001, 'A: only the 3 unsold units are sellable');
        $this->assertEqualsWithDelta(2.0, $proj->quantityAt($pa, 'HELD', $this->ca->id), 0.001, 'A: the 2 returns are held');
        $cands = $prov->lotCandidates($this->bulk->id, $this->ca->id);
        $this->assertEqualsWithDelta(3.0, $cands[$a->id]['available'], 0.001);
        $this->assertEqualsWithDelta(2.0, $cands[$b->id]['available'], 0.001);

        // A transfer of 5 "from A" would take B's goods and record false supplier provenance: refused.
        session(['setting_id' => $this->businessA->id]);
        $t = app(TransferV3GoodsService::class)->createAndSubmit(Transfer::CONDITION_GOOD,
            [['product_id' => $this->bulk->id, 'quantity' => 5, 'serial_ids' => []]], $this->actor, $this->businessA->id);
        $this->saveCompletePlan($t, [['product_id' => $this->bulk->id, 'source_location_id' => $this->ca->id, 'destination_location_id' => $this->cb->id, 'quantity' => 5]], []);
        $row = app(\Modules\Adjustment\Services\TransferV3AllocationService::class)->currentPlan($t->fresh())->first()->id;
        try {
            $prov->confirmSelection($t, [$row => [$a->id => 5]], $this->actor);
            $this->fail('held returns offered as sellable lot stock');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('hanya memiliki 3', $e->getMessage());
        }
        // A's legitimate remainder stays usable, together with B's own stock.
        $prov->confirmSelection($t, [$row => [$a->id => 3, $b->id => 2]], $this->actor);
        $this->dispatchTransfer($t);
        $this->assertSame(Transfer::STATUS_DISPATCHED, $t->fresh()->status);
        $this->assertEqualsWithDelta(0.0, $proj->quantityAt($prov->project($a->fresh()), 'SELLABLE', $this->ca->id), 0.001);
        $this->assertEqualsWithDelta(2.0, $proj->quantityAt($prov->project($a->fresh()), 'HELD', $this->ca->id), 0.001, 'held units never left');
        $this->assertEqualsWithDelta(0.0, $proj->quantityAt($prov->project($b->fresh()), 'SELLABLE', $this->ca->id), 0.001);

        // Released by inspection: the two units become sellable A stock again.
        \DB::table('sale_return_custodies')->where('id', $custodyId)->update(['quantity_released' => 2]);
        $pa = $prov->project($a->fresh());
        $this->assertEqualsWithDelta(2.0, $proj->quantityAt($pa, 'SELLABLE', $this->ca->id), 0.001);
        $this->assertEqualsWithDelta(0.0, $proj->quantityAt($pa, 'HELD', $this->ca->id), 0.001);
        $this->assertTrue($pa['conserved']);
    }

    /** Ambiguous held custody across several attributed receipts is a blocker, never a guess. */
    public function test_ambiguous_held_return_apportionment_blocks_instead_of_guessing(): void
    {
        $a = $this->bulkReceipt(2);
        $b = $this->bulkReceipt(2);
        $this->stock($this->bulk, $this->ca, nonTax: 2);
        $dd = $this->sellAt($this->ca, [], $this->bulk->id);
        \DB::table('dispatch_details')->where('id', $dd->id)->update(['dispatched_quantity' => 2]);
        $this->discover($this->businessA);
        $src = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();
        app(ConsignmentBuyoutCapacityService::class)->setAttribution($src->id, [$a->id => 1, $b->id => 1], $this->actor->id, 'split');
        $sale = \DB::table('sales')->where('id', $src->sale_id)->first();
        $return = \Modules\SalesReturn\Entities\SaleReturn::create(['date' => now(), 'sale_id' => $sale->id, 'sale_reference' => $sale->reference,
            'customer_id' => $sale->customer_id, 'customer_name' => $sale->customer_name, 'setting_id' => $this->businessA->id, 'location_id' => $this->ca->id,
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0, 'total_amount' => 1,
            'paid_amount' => 0, 'due_amount' => 1, 'status' => 'Awaiting Receiving', 'approval_status' => 'approved', 'payment_status' => 'Unpaid', 'payment_method' => 'Pending']);
        $rd = \Modules\SalesReturn\Entities\SaleReturnDetail::create(['sale_return_id' => $return->id, 'dispatch_detail_id' => $dd->id, 'location_id' => $this->ca->id,
            'product_id' => $this->bulk->id, 'product_name' => 'B', 'product_code' => 'B', 'quantity' => 1, 'unit_price' => 1, 'price' => 1, 'sub_total' => 1,
            'product_discount_amount' => 0, 'product_tax_amount' => 0, 'serial_number_ids' => []]);
        \DB::table('sale_return_custodies')->insert(['sale_return_id' => $return->id, 'sale_return_detail_id' => $rd->id, 'sale_id' => $sale->id,
            'dispatch_detail_id' => $dd->id, 'product_id' => $this->bulk->id, 'owner_setting_id' => $this->businessA->id, 'location_id' => $this->ca->id,
            'policy_version' => 1, 'quantity_received' => 1, 'quantity_released' => 0, 'quantity_dispatched' => 0, 'occurrence_key' => "{$rd->id}:0",
            'created_at' => now(), 'updated_at' => now()]);

        $p = app(\Modules\Consignment\Services\ConsignmentTransferProvenanceService::class)->project($a->fresh());
        $this->assertStringContainsString('cannot be apportioned exactly', implode(' ', $p['blockers']));
        $this->assertSame([], array_filter(app(\Modules\Consignment\Services\ConsignmentTransferProvenanceService::class)->lotCandidates($this->bulk->id, $this->ca->id),
            fn ($c) => $c['receipt_id'] === $a->id && $c['blockers'] === []), 'the ambiguous lot cannot be selected');
    }
}
