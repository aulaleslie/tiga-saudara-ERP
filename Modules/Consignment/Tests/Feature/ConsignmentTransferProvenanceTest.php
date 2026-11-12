<?php

namespace Modules\Consignment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferApprovalAllocation;
use Modules\Adjustment\Services\TransferV3CancellationExecutor;
use Modules\Adjustment\Services\TransferV3ReceiptExecutor;
use Modules\Adjustment\Services\TransferV3GoodsService;
use Modules\Adjustment\Tests\Support\BuildsV3TransferFixtures;
use Modules\Consignment\Entities\ConsignmentProvenanceEvent as Event;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentTransferLotSelection;
use Modules\Consignment\Services\ConsignmentTransferProvenanceService;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use RuntimeException;
use Tests\TestCase;

/**
 * track-transferred-consignment-provenance phase 2: consignment-only receipt-lot selection and
 * custody posting on the real V3 dispatch, receipt and cancellation executors.
 */
class ConsignmentTransferProvenanceTest extends TestCase
{
    use RefreshDatabase;
    use BuildsV3TransferFixtures;

    private Location $ca;   // consignment rack, business A (origin, TOP IT-like)
    private Location $cb;   // consignment rack, business B (PERDANA-like)
    private Supplier $s1;
    private Supplier $s2;
    private ConsignmentTransferProvenanceService $prov;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildV3Fixtures();
        $this->ca = Location::create(['setting_id' => $this->businessA->id, 'name' => 'Konsinyasi A', 'is_active' => true, 'is_consignment' => true]);
        $this->cb = Location::create(['setting_id' => $this->businessB->id, 'name' => 'Konsinyasi B', 'is_active' => true, 'is_consignment' => true]);
        $this->s1 = $this->supplier('CANDRA');
        $this->s2 = $this->supplier('LAIN');
        $this->prov = app(ConsignmentTransferProvenanceService::class);
    }

    private function supplier(string $name): Supplier
    {
        return Supplier::create(['setting_id' => $this->businessA->id, 'supplier_name' => $name, 'supplier_email' => strtolower($name) . '@x.test',
            'supplier_phone' => '0812', 'city' => 'J', 'country' => 'ID', 'address' => 'A']);
    }

    private function receipt(Supplier $s, $product, int $qty): ConsignmentReceivingDetail
    {
        $ref = 'CRN-' . (++$this->n);
        $receival = ConsignmentReceival::create(['setting_id' => $this->businessA->id, 'supplier_id' => $s->id, 'reference' => $ref,
            'receival_number' => $ref, 'status' => 'APPROVED', 'date' => now()]);
        $receiving = ConsignmentReceiving::create(['setting_id' => $this->businessA->id, 'location_id' => $this->ca->id,
            'consignment_receival_id' => $receival->id, 'receiving_number' => $ref, 'status' => ConsignmentReceiving::STATUS_APPROVED, 'date' => now()]);
        $line = ConsignmentReceivalLine::create(['consignment_receival_id' => $receival->id, 'product_id' => $product->id,
            'product_name' => 'P', 'product_code' => 'P', 'quantity' => $qty, 'unit_cost' => 100, 'unit_dpp' => 100,
            'subtotal_cost' => 100 * $qty, 'subtotal_dpp' => 100 * $qty, 'total_cost' => 100 * $qty, 'total_dpp' => 100 * $qty]);

        return ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $line->id,
            'product_id' => $product->id, 'quantity_received' => $qty, 'received_base_quantity' => $qty, 'unit_cost' => 100, 'unit_dpp' => 100]);
    }

    /** Bulk transfer of $qty from the A consignment rack to the B consignment rack, planned and pending. */
    private function bulkTransfer(int $qty): Transfer
    {
        $t = app(TransferV3GoodsService::class)->createAndSubmit(Transfer::CONDITION_GOOD,
            [['product_id' => $this->bulk->id, 'quantity' => $qty, 'serial_ids' => []]], $this->actor, $this->businessA->id);
        $this->saveCompletePlan($t, [['product_id' => $this->bulk->id, 'source_location_id' => $this->ca->id, 'destination_location_id' => $this->cb->id, 'quantity' => $qty]], []);

        return $t->fresh();
    }

    private function rowId(Transfer $t): int
    {
        return (int) app(\Modules\Adjustment\Services\TransferV3AllocationService::class)->currentPlan($t->fresh())->first()->id;
    }

    private function sellableAt(ConsignmentReceivingDetail $d, Location $l): float
    {
        return (new \Modules\Consignment\Services\ConsignmentCustodyProjection)->quantityAt($this->prov->project($d->fresh()), Event::STATE_SELLABLE, $l->id);
    }

    /** @test */
    public function sole_lot_is_proposed_and_must_be_confirmed_before_dispatch(): void
    {
        $r = $this->receipt($this->s1, $this->bulk, 5);
        $this->stock($this->bulk, $this->ca, nonTax: 5);
        $t = $this->bulkTransfer(3);

        $p = $this->prov->proposeSoleLots($t);
        $this->assertSame(1, $p['proposed']);
        $this->assertSame('PROPOSED', ConsignmentTransferLotSelection::where('transfer_id', $t->id)->value('status'));

        try {
            $this->dispatchTransfer($t);
            $this->fail('unconfirmed selection must block dispatch');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Lot penerimaan konsinyasi', $e->getMessage());
        }
        $this->assertSame(Transfer::STATUS_PENDING, $t->fresh()->status);
        $this->assertSame(5, (int) ProductStock::where('product_id', $this->bulk->id)->where('location_id', $this->ca->id)->value('quantity'), 'rolled back');
        $this->assertSame(0, Event::count());

        $this->prov->confirmProposal($t, $this->actor);
        $this->dispatchTransfer($t);
        $this->assertEqualsWithDelta(2.0, $this->sellableAt($r, $this->ca), 0.001);
        $this->assertSame(1, Event::where('kind', 'TRANSFER_DISPATCH')->count());

        app(TransferV3ReceiptExecutor::class)->receive($t->fresh(), $this->actor, $this->businessB->id);
        $this->assertEqualsWithDelta(3.0, $this->sellableAt($r, $this->cb), 0.001);
        $p = $this->prov->project($r->fresh());
        $this->assertTrue($p['conserved']);
        $this->assertSame([], $p['blockers']);
    }

    /** @test */
    public function multiple_lots_need_explicit_quantities_and_are_validated(): void
    {
        $r1 = $this->receipt($this->s1, $this->bulk, 4);
        $r2 = $this->receipt($this->s2, $this->bulk, 6);
        $this->stock($this->bulk, $this->ca, nonTax: 10);
        $t = $this->bulkTransfer(7);

        $this->assertSame(0, $this->prov->proposeSoleLots($t)['proposed'], 'several lots: no guess');
        $row = $this->rowId($t);

        foreach ([[$r1->id => 5, $r2->id => 2], [$r1->id => 1, $r2->id => 2], [999999 => 7]] as $bad) {
            try {
                $this->prov->confirmSelection($t, [$row => $bad], $this->actor);
                $this->fail('invalid selection accepted');
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $this->prov->confirmSelection($t, [$row => [$r1->id => 4, $r2->id => 3]], $this->actor);
        $this->dispatchTransfer($t);
        $this->assertEqualsWithDelta(0.0, $this->sellableAt($r1, $this->ca), 0.001);
        $this->assertEqualsWithDelta(3.0, $this->sellableAt($r2, $this->ca), 0.001);
        $this->assertSame([$this->s1->id, $this->s2->id], Event::orderBy('supplier_id')->pluck('supplier_id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame([(int) $this->businessA->id], Event::pluck('original_setting_id')->unique()->map(fn ($i) => (int) $i)->values()->all(), 'origin business is preserved');
    }

    /** @test */
    public function cancellation_returns_transit_custody_to_source_exactly_once(): void
    {
        $r = $this->receipt($this->s1, $this->bulk, 5);
        $this->stock($this->bulk, $this->ca, nonTax: 5);
        $t = $this->bulkTransfer(2);
        $this->prov->confirmSelection($t, [$this->rowId($t) => [$r->id => 2]], $this->actor);
        $this->dispatchTransfer($t);
        app(TransferV3CancellationExecutor::class)->cancel($t->fresh(), $this->actor, $this->businessA->id, 'Salah kirim');

        $this->assertEqualsWithDelta(5.0, $this->sellableAt($r, $this->ca), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->sellableAt($r, $this->cb), 0.001);
        $this->assertSame(1, Event::where('kind', 'TRANSFER_CANCEL')->count());
    }

    /** @test */
    public function serialized_consignment_transfer_follows_the_exact_receipt_chain(): void
    {
        $r = $this->receipt($this->s1, $this->serialized, 2);
        $this->stock($this->serialized, $this->ca, nonTax: 2);
        $sn = [];
        foreach (['CS-1', 'CS-2'] as $no) {
            $sn[] = ProductSerialNumber::create(['product_id' => $this->serialized->id, 'location_id' => $this->ca->id, 'serial_number' => $no,
                'tax_id' => null, 'consignment_receiving_detail_id' => $r->id]);
        }
        $t = app(TransferV3GoodsService::class)->createAndSubmit(Transfer::CONDITION_GOOD,
            [['product_id' => $this->serialized->id, 'quantity' => 1, 'serial_ids' => [$sn[0]->id]]], $this->actor, $this->businessA->id);
        $this->saveCompletePlan($t, [], ["{$this->serialized->id}:{$this->ca->id}" => $this->cb->id]);
        $this->dispatchTransfer($t);
        app(TransferV3ReceiptExecutor::class)->receive($t->fresh(), $this->actor, $this->businessB->id);

        $this->assertSame(['state' => 'SELLABLE', 'location_id' => $this->cb->id], $this->prov->serialCustody($sn[0]->fresh()));
        $this->assertSame(['state' => 'SELLABLE', 'location_id' => $this->ca->id], $this->prov->serialCustody($sn[1]->fresh()));
        $this->assertEqualsWithDelta(1.0, $this->sellableAt($r, $this->cb), 0.001);
        $this->assertSame(0, ConsignmentTransferLotSelection::count(), 'serials need no lot prompt');
    }

    /** @test */
    public function consignment_serial_without_receipt_lineage_cannot_be_dispatched(): void
    {
        $this->stock($this->serialized, $this->ca, nonTax: 1);
        $orphan = ProductSerialNumber::create(['product_id' => $this->serialized->id, 'location_id' => $this->ca->id, 'serial_number' => 'ORPHAN', 'tax_id' => null]);
        $t = app(TransferV3GoodsService::class)->createAndSubmit(Transfer::CONDITION_GOOD,
            [['product_id' => $this->serialized->id, 'quantity' => 1, 'serial_ids' => [$orphan->id]]], $this->actor, $this->businessA->id);
        $this->saveCompletePlan($t, [], ["{$this->serialized->id}:{$this->ca->id}" => $this->cb->id]);

        $this->expectException(RuntimeException::class);
        $this->dispatchTransfer($t);
    }

    /** @test */
    public function ordinary_transfers_gain_no_lot_requirement_or_provenance(): void
    {
        $t = $this->submittedTransfer();
        $this->saveCompletePlan($t);
        $this->dispatchTransfer($t);
        app(TransferV3ReceiptExecutor::class)->receive($t->fresh(), $this->actor, $this->businessA->id);

        $this->assertSame(Transfer::STATUS_COMPLETED, $t->fresh()->status);
        $this->assertSame(0, Event::count());
        $this->assertSame(0, ConsignmentTransferLotSelection::count());
    }

    /** @test */
    public function consignment_to_standard_transfer_remains_rejected(): void
    {
        $this->receipt($this->s1, $this->bulk, 5);
        $this->stock($this->bulk, $this->ca, nonTax: 5);
        $t = app(TransferV3GoodsService::class)->createAndSubmit(Transfer::CONDITION_GOOD,
            [['product_id' => $this->bulk->id, 'quantity' => 1, 'serial_ids' => []]], $this->actor, $this->businessA->id);

        $this->expectException(\Throwable::class);
        $this->saveCompletePlan($t, [['product_id' => $this->bulk->id, 'source_location_id' => $this->ca->id, 'destination_location_id' => $this->a2->id, 'quantity' => 1]], []);
        $this->dispatchTransfer($t);
    }

    /** @test */
    public function replayed_dispatch_creates_no_duplicate_provenance(): void
    {
        $r = $this->receipt($this->s1, $this->bulk, 5);
        $this->stock($this->bulk, $this->ca, nonTax: 3, tax: 2);
        $t = $this->bulkTransfer(5);
        $this->prov->confirmSelection($t, [$this->rowId($t) => [$r->id => 5]], $this->actor);
        $this->dispatchTransfer($t, 'op-1');
        $this->dispatchTransfer($t, 'op-1');

        $this->assertSame(1, Event::count());
        $this->assertSame('MIXED', Event::value('tax_bucket'), 'both buckets recorded as applied by the poster');
    }

    /** @test */
    public function a_later_line_shortfall_rolls_back_every_earlier_line(): void
    {
        $r = $this->receipt($this->s1, $this->bulk, 3);
        $this->stock($this->bulk, $this->ca, nonTax: 3);
        $c2 = Location::create(['setting_id' => $this->businessA->id, 'name' => 'Konsinyasi A2', 'is_active' => true, 'is_consignment' => true]);
        $this->stock($this->bulk, $c2, nonTax: 2);   // physical stock without any receipt lineage
        $t = app(TransferV3GoodsService::class)->createAndSubmit(Transfer::CONDITION_GOOD,
            [['product_id' => $this->bulk->id, 'quantity' => 5, 'serial_ids' => []]], $this->actor, $this->businessA->id);
        $this->saveCompletePlan($t, [
            ['product_id' => $this->bulk->id, 'source_location_id' => $this->ca->id, 'destination_location_id' => $this->cb->id, 'quantity' => 3],
            ['product_id' => $this->bulk->id, 'source_location_id' => $c2->id, 'destination_location_id' => $this->cb->id, 'quantity' => 2],
        ], []);
        $rows = app(\Modules\Adjustment\Services\TransferV3AllocationService::class)->currentPlan($t->fresh())->keyBy('source_location_id');

        try {
            $this->prov->confirmSelection($t, [$rows[$this->ca->id]->id => [$r->id => 3], $rows[$c2->id]->id => [$r->id => 2]], $this->actor);
            $this->fail('lot not at the second source must be rejected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tidak berada di lokasi sumber', $e->getMessage());
        }
        // Even a forged confirmed row (bypassing preparation) is rejected at dispatch, rolling back the first line too.
        foreach ([[$this->ca, 3], [$c2, 2]] as [$src, $q]) {
            ConsignmentTransferLotSelection::create(['transfer_id' => $t->id, 'consignment_receiving_detail_id' => $r->id,
                'original_setting_id' => $this->businessA->id, 'supplier_id' => $this->s1->id, 'product_id' => $this->bulk->id,
                'source_location_id' => $src->id, 'source_setting_id' => $this->businessA->id, 'destination_location_id' => $this->cb->id,
                'destination_setting_id' => $this->businessB->id, 'base_quantity' => $q, 'selection_revision' => 1, 'status' => 'CONFIRMED']);
        }
        try {
            $this->dispatchTransfer($t);
            $this->fail('dispatch with an unbacked line must fail');
        } catch (RuntimeException $e) {
        }
        $this->assertSame(0, Event::count());
        $this->assertSame(3, (int) $this->stockAt($this->bulk, $this->ca)->quantity);
        $this->assertSame(Transfer::STATUS_PENDING, $t->fresh()->status);
    }

    /** @test */
    public function multi_hop_return_to_origin_never_doubles_availability(): void
    {
        $r = $this->receipt($this->s1, $this->bulk, 5);
        $this->stock($this->bulk, $this->ca, nonTax: 5);
        $out = $this->bulkTransfer(4);
        $this->prov->confirmSelection($out, [$this->rowId($out) => [$r->id => 4]], $this->actor);
        $this->dispatchTransfer($out);
        $this->assertEqualsWithDelta(1.0, $this->sellableAt($r, $this->ca), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->sellableAt($r, $this->cb), 0.001, 'in transit is neither source nor destination');
        app(TransferV3ReceiptExecutor::class)->receive($out->fresh(), $this->actor, $this->businessB->id);

        session(['setting_id' => $this->businessB->id]);
        $back = app(TransferV3GoodsService::class)->createAndSubmit(Transfer::CONDITION_GOOD,
            [['product_id' => $this->bulk->id, 'quantity' => 1, 'serial_ids' => []]], $this->actor, $this->businessB->id);
        $back->refresh();
        app(\Modules\Adjustment\Services\TransferV3AllocationService::class)->saveProgress($back, $this->actor, $this->businessB->id,
            (int) $back->current_request_revision_id, (int) $back->approval_configuration_revision, [],
            [['product_id' => $this->bulk->id, 'source_location_id' => $this->cb->id, 'destination_location_id' => $this->ca->id, 'quantity' => 1]]);
        $this->assertSame(1, $this->prov->proposeSoleLots($back->fresh())['proposed'], 'the moved lot is the sole lot at B');
        $this->prov->confirmProposal($back->fresh(), $this->actor);
        $back->refresh();
        app(\Modules\Adjustment\Services\TransferV3DispatchExecutor::class)->approveAndDispatch($back, $this->actor, $this->businessB->id,
            (int) $back->current_request_revision_id, (int) $back->approval_configuration_revision);
        app(TransferV3ReceiptExecutor::class)->receive($back->fresh(), $this->actor, $this->businessA->id);

        $this->assertEqualsWithDelta(2.0, $this->sellableAt($r, $this->ca), 0.001);
        $this->assertEqualsWithDelta(3.0, $this->sellableAt($r, $this->cb), 0.001);
        $this->assertTrue($this->prov->project($r->fresh())['conserved']);
    }

    private function approver(): \App\Models\User
    {
        $this->withoutMiddleware([\App\Http\Middleware\CheckUserRoleForSetting::class]);
        $u = \App\Models\User::factory()->create();
        $u->settings()->attach($this->businessA->id, ['role_id' => \Spatie\Permission\Models\Role::findOrCreate('TR', 'web')->id]);
        $this->grant($u, ['stockTransfers.access', 'stockTransfers.show', 'stockTransfers.approval']);

        return $u;
    }

    /** @test */
    public function approval_workspace_shows_the_lot_panel_only_for_consignment_rows_and_writes_nothing(): void
    {
        $r = $this->receipt($this->s1, $this->bulk, 5);
        $this->stock($this->bulk, $this->ca, nonTax: 5);
        $t = $this->bulkTransfer(3);
        $u = $this->approver();

        $html = $this->actingAs($u)->withSession(['setting_id' => $this->businessA->id])->get(route('transfers.v3.approval', $t))->assertOk()->getContent();
        $this->assertStringContainsString('Lot Penerimaan Konsinyasi', $html);
        $this->assertStringContainsString('Diusulkan (satu lot)', $html);
        $this->assertSame(0, ConsignmentTransferLotSelection::count(), 'GET writes nothing');

        $review = $this->actingAs($u)->withSession(['setting_id' => $this->businessA->id])->get(route('transfers.v3.approval', ['transfer' => $t->id, 'review' => 1]))->getContent();
        $this->assertStringContainsString('lot penerimaan konsinyasi belum dikonfirmasi', $review);

        $this->actingAs($u)->withSession(['setting_id' => $this->businessA->id])
            ->post(route('consignments.transfer-lots.confirm', $t), ['lots' => [$this->rowId($t) => [$r->id => 3]]])
            ->assertRedirect(route('transfers.v3.approval', $t->id));
        $this->assertSame(1, ConsignmentTransferLotSelection::where('status', 'CONFIRMED')->count());

        $ordinary = $this->submittedTransfer();
        $this->saveCompletePlan($ordinary);
        $html = $this->actingAs($u)->withSession(['setting_id' => $this->businessA->id])->get(route('transfers.v3.approval', $ordinary))->assertOk()->getContent();
        $this->assertStringNotContainsString('Lot Penerimaan Konsinyasi', $html);
    }

    /** @test */
    public function lot_confirmation_requires_transfer_approval_authority(): void
    {
        $this->receipt($this->s1, $this->bulk, 5);
        $this->stock($this->bulk, $this->ca, nonTax: 5);
        $t = $this->bulkTransfer(3);
        $this->withoutMiddleware([\App\Http\Middleware\CheckUserRoleForSetting::class]);
        $u = \App\Models\User::factory()->create();
        $this->grant($u, ['stockTransfers.access', 'stockTransfers.show']);

        $this->actingAs($u)->withSession(['setting_id' => $this->businessA->id])
            ->post(route('consignments.transfer-lots.confirm', $t), ['lots' => [$this->rowId($t) => [1 => 3]]])->assertForbidden();
        $this->assertSame(0, ConsignmentTransferLotSelection::count());
    }

    /** @test */
    public function pre_provenance_v3_dispatch_settles_and_is_exposed_by_the_evidence_adapter(): void
    {
        $r = $this->receipt($this->s1, $this->serialized, 1);
        $this->stock($this->serialized, $this->ca, nonTax: 1);
        $sn = ProductSerialNumber::create(['product_id' => $this->serialized->id, 'location_id' => $this->ca->id, 'serial_number' => 'OLD-1',
            'tax_id' => null, 'consignment_receiving_detail_id' => $r->id]);
        $t = app(TransferV3GoodsService::class)->createAndSubmit(Transfer::CONDITION_GOOD,
            [['product_id' => $this->serialized->id, 'quantity' => 1, 'serial_ids' => [$sn->id]]], $this->actor, $this->businessA->id);
        $this->saveCompletePlan($t, [], ["{$this->serialized->id}:{$this->ca->id}" => $this->cb->id]);
        $this->dispatchTransfer($t);
        \DB::table('consignment_provenance_events')->delete();   // simulate a dispatch made before this change was deployed

        app(TransferV3ReceiptExecutor::class)->receive($t->fresh(), $this->actor, $this->businessB->id);
        $this->assertSame(Transfer::STATUS_COMPLETED, $t->fresh()->status, 'warehouse settlement is never stranded');
        $this->assertSame(0, Event::count(), 'nothing guessed');

        $legs = app(\Modules\Consignment\Services\ConsignmentTransferEvidenceAdapter::class)->consignmentLegs($t->fresh());
        $this->assertCount(1, $legs);
        $this->assertSame('RECEIPT', $legs[0]['outcome']);
        $this->assertFalse($legs[0]['has_provenance']);
        $this->assertSame([['serial_id' => $sn->id, 'receiving_detail_id' => $r->id]], $legs[0]['serials']);
        $this->assertSame((int) $this->businessB->id, $legs[0]['destination_setting_id']);
    }

    /** @test */
    public function legacy_workflow_dispatch_from_a_consignment_source_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('versi 3');
        ConsignmentTransferProvenanceService::assertLegacyDispatchAllowed($this->ca->id);
    }

    /** @test */
    public function cross_business_consignment_route_keeps_existing_tax_policy_and_creates_no_financial_records(): void
    {
        $r = $this->receipt($this->s1, $this->bulk, 5);
        $this->stock($this->bulk, $this->ca, nonTax: 5);
        $t = $this->bulkTransfer(2);   // A (non-PKP) -> B (PKP)
        $this->prov->confirmSelection($t, [$this->rowId($t) => [$r->id => 2]], $this->actor);
        $before = $this->financialCounts();
        $this->dispatchTransfer($t);
        app(TransferV3ReceiptExecutor::class)->receive($t->fresh(), $this->actor, $this->businessB->id);

        $a = \Modules\Adjustment\Entities\TransferMovementAllocation::where('transfer_id', $t->id)->where('kind', 'DISPATCH')->first();
        $expected = app(\Modules\Adjustment\Services\TransferRoutePolicyResolver::class)
            ->resolveClassification(false, (bool) $this->businessA->is_pkp, (bool) $this->businessB->is_pkp)['classification'];
        $this->assertTrue($a->cross_business);
        $this->assertSame($expected, $a->destination_classification, 'route/tax policy is the ordinary one');
        $this->assertSame($before, $this->financialCounts(), 'no supplier bill, purchase, payment or stock-debt from provenance');
    }

    /** @test */
    public function standard_to_consignment_transfer_remains_rejected(): void
    {
        $t = app(TransferV3GoodsService::class)->createAndSubmit(Transfer::CONDITION_GOOD,
            [['product_id' => $this->bulk->id, 'quantity' => 1, 'serial_ids' => []]], $this->actor, $this->businessA->id);
        try {
            $this->saveCompletePlan($t, [['product_id' => $this->bulk->id, 'source_location_id' => $this->a1->id, 'destination_location_id' => $this->ca->id, 'quantity' => 1]], []);
            $this->dispatchTransfer($t);
            $this->fail('classification crossing accepted');
        } catch (\Throwable $e) {
        }
        $this->assertSame(0, Event::count());
        $this->assertSame(6, (int) $this->stockAt($this->bulk, $this->a1)->quantity_non_tax);
    }

    private function financialCounts(): array
    {
        $out = [];
        foreach (['purchases', 'purchase_payments', 'consignment_billing_confirmations', 'consignment_buyouts', 'consignment_sold_sources'] as $table) {
            $out[$table] = \DB::table($table)->count();
        }
        foreach (\Illuminate\Support\Facades\Schema::getConnection()->getDoctrineSchemaManager()->listTableNames() as $table) {
            if (str_contains($table, 'debt')) {
                $out[$table] = \DB::table($table)->count();
            }
        }

        return $out;
    }
}
