<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Entities\SalesOrderSerialTracking;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Tax;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Spatie\Permission\Models\Permission;

class POSReturnAtomicLifecycleTest extends PosTransactionFeatureTestCase
{
    protected $setting;

    protected $location;

    protected $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->createSetting('POS Return Atomic Lifecycle Test');
        [, $this->location] = $this->createTerminalWithLocation($this->setting);

        Permission::findOrCreate('pos.access', 'web');

        $this->actor = $this->createUserForSetting($this->setting, 'POS Return Atomic Actor', [
            'pos.access',
        ]);
    }

    /** @test */
    public function it_rolls_back_approve_failures(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $saleReturn] = $this->createPendingApprovalReturn();
        $service = new class extends PosReturnLifecycleService {
            protected function syncApprovedSaleReturns(\Modules\Pos\Entities\PosReturn $posReturn, ?int $actorId, \Illuminate\Support\Carbon $approvedAt): void
            {
                parent::syncApprovedSaleReturns($posReturn, $actorId, $approvedAt);

                throw new \RuntimeException('approve failed');
            }
        };

        try {
            $service->approve($posReturn->id);
            $this->fail('Expected approve failure was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('approve failed', $exception->getMessage());
        }

        $this->assertSame(PosReturn::STATUS_PENDING_APPROVAL, $posReturn->fresh()->status);
        $this->assertSame(PosReturn::APPROVAL_STATUS_PENDING, $posReturn->fresh()->approval_status);
        $this->assertSame('PENDING', $saleReturn->fresh()->approval_status);
        $this->assertSame('PENDING APPROVAL', $saleReturn->fresh()->status);
    }

    /** @test */
    public function it_rolls_back_reject_failures(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $saleReturn] = $this->createPendingApprovalReturn();
        $service = new class extends PosReturnLifecycleService {
            protected function syncRejectedSaleReturns(\Modules\Pos\Entities\PosReturn $posReturn, ?int $actorId, \Illuminate\Support\Carbon $rejectedAt, ?string $reason = null): void
            {
                parent::syncRejectedSaleReturns($posReturn, $actorId, $rejectedAt, $reason);

                throw new \RuntimeException('reject failed');
            }
        };

        try {
            $service->reject($posReturn->id, 'Mismatch');
            $this->fail('Expected reject failure was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('reject failed', $exception->getMessage());
        }

        $this->assertSame(PosReturn::STATUS_PENDING_APPROVAL, $posReturn->fresh()->status);
        $this->assertSame(PosReturn::APPROVAL_STATUS_PENDING, $posReturn->fresh()->approval_status);
        $this->assertSame('PENDING', $saleReturn->fresh()->approval_status);
        $this->assertSame('PENDING APPROVAL', $saleReturn->fresh()->status);
        $this->assertNull($saleReturn->fresh()->rejected_at);
    }

    /** @test */
    public function it_rolls_back_receive_failures(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $saleReturn, $detail, $dispatchDetail] = $this->createAwaitingReceiveReturn();
        $initialProductQuantity = (int) $detail->product->product_quantity;
        $initialLocationQuantity = (int) ProductStock::query()
            ->where('product_id', $detail->product_id)
            ->where('location_id', $this->location->id)
            ->value('quantity');

        $service = new class extends PosReturnLifecycleService {
            protected function applyReceivedDispatchQuantityAdjustments(\Modules\Pos\Entities\PosReturn $posReturn): void
            {
                parent::applyReceivedDispatchQuantityAdjustments($posReturn);

                throw new \RuntimeException('receive failed');
            }
        };

        try {
            $service->receive($posReturn->id);
            $this->fail('Expected receive failure was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('receive failed', $exception->getMessage());
        }

        $this->assertSame(PosReturn::STATUS_APPROVED, $posReturn->fresh()->status);
        $this->assertNull($posReturn->fresh()->received_at);
        $this->assertSame('AWAITING RECEIVING', $saleReturn->fresh()->status);
        $this->assertNull($saleReturn->fresh()->received_at);
        $this->assertSame(2, (int) $dispatchDetail->fresh()->dispatched_quantity);
        $this->assertSame($initialProductQuantity, (int) $detail->product->fresh()->product_quantity);
        $currentLocationQuantity = (int) ProductStock::query()
            ->where('product_id', $detail->product_id)
            ->where('location_id', $this->location->id)
            ->value('quantity');
        $this->assertSame($initialLocationQuantity, $currentLocationQuantity);
    }

    /** @test */
    public function it_rolls_back_cash_settlement_failures(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $saleReturn] = $this->createAwaitingSettlementReturn();
        $service = new class extends PosReturnLifecycleService {
            protected function settleLinkedCashReturn(\Modules\SalesReturn\Entities\SaleReturn $saleReturn, float $settlementAmount, \Illuminate\Support\Carbon $settledAt, ?int $actorId): void
            {
                parent::settleLinkedCashReturn($saleReturn, $settlementAmount, $settledAt, $actorId);

                throw new \RuntimeException('settlement failed');
            }
        };

        try {
            $service->settlePaymentReturn($posReturn->id);
            $this->fail('Expected settlement failure was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('settlement failed', $exception->getMessage());
        }

        $this->assertSame(PosReturn::STATUS_AWAITING_SETTLEMENT, $posReturn->fresh()->status);
        $this->assertNull($posReturn->fresh()->settled_at);
        $this->assertSame('AWAITING SETTLEMENT', $saleReturn->fresh()->status);
        $this->assertSame(0.0, (float) $saleReturn->fresh()->paid_amount);
        $this->assertSame(600.0, (float) $saleReturn->fresh()->due_amount);
        $this->assertDatabaseCount('sale_return_payments', 0);
    }

    /** @test */
    public function it_rolls_back_replacement_dispatch_failures(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $saleReturn, $detail] = $this->createAwaitingDispatchReturn();
        $initialProductQuantity = (int) $detail->product->product_quantity;

        $service = new class extends PosReturnLifecycleService {
            protected function dispatchReplacementForSaleReturn(\Modules\SalesReturn\Entities\SaleReturn $saleReturn, ?int $actorId, \Illuminate\Support\Carbon $settledAt): void
            {
                parent::dispatchReplacementForSaleReturn($saleReturn, $actorId, $settledAt);

                throw new \RuntimeException('dispatch failed');
            }
        };

        try {
            $service->dispatchReplacement($posReturn->id);
            $this->fail('Expected dispatch failure was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('dispatch failed', $exception->getMessage());
        }

        $this->assertSame(PosReturn::STATUS_AWAITING_DISPATCH, $posReturn->fresh()->status);
        $this->assertNull($posReturn->fresh()->settled_at);
        $this->assertSame('AWAITING DISPATCH', $saleReturn->fresh()->status);
        $this->assertSame($initialProductQuantity, (int) $detail->product->fresh()->product_quantity);
        $this->assertDatabaseCount('dispatches', 0);
    }

    /** @test */
    public function it_rolls_back_archive_and_cancel_failures(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$archiveReturn, $archiveSaleReturn] = $this->createApprovedCashReturn();
        $archiveService = new class extends PosReturnLifecycleService {
            protected function applyAuditedReversalToLinkedSaleReturn(\Modules\SalesReturn\Entities\SaleReturn $saleReturn, string $targetStatus, ?int $actorId, \Illuminate\Support\Carbon $timestamp, ?string $reason = null): void
            {
                parent::applyAuditedReversalToLinkedSaleReturn($saleReturn, $targetStatus, $actorId, $timestamp, $reason);

                throw new \RuntimeException('archive failed');
            }
        };

        try {
            $archiveService->archive($archiveReturn->id, 'Audit archive');
            $this->fail('Expected archive failure was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('archive failed', $exception->getMessage());
        }

        $this->assertSame(PosReturn::STATUS_APPROVED, $archiveReturn->fresh()->status);
        $this->assertFalse((bool) $archiveReturn->fresh()->is_reversed);
        $this->assertNull($archiveSaleReturn->fresh()->archived_at);

        [$cancelReturn, $cancelSaleReturn] = $this->createApprovedCashReturn();
        $cancelService = new class extends PosReturnLifecycleService {
            protected function applyAuditedReversalToLinkedSaleReturn(\Modules\SalesReturn\Entities\SaleReturn $saleReturn, string $targetStatus, ?int $actorId, \Illuminate\Support\Carbon $timestamp, ?string $reason = null): void
            {
                parent::applyAuditedReversalToLinkedSaleReturn($saleReturn, $targetStatus, $actorId, $timestamp, $reason);

                throw new \RuntimeException('cancel failed');
            }
        };

        try {
            $cancelService->cancel($cancelReturn->id, 'Audit cancel');
            $this->fail('Expected cancel failure was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('cancel failed', $exception->getMessage());
        }

        $this->assertSame(PosReturn::STATUS_APPROVED, $cancelReturn->fresh()->status);
        $this->assertFalse((bool) $cancelReturn->fresh()->is_reversed);
        $this->assertSame('AWAITING RECEIVING', $cancelSaleReturn->fresh()->status);
        $this->assertNull($cancelSaleReturn->fresh()->rejected_at);
    }

    /** @test */
    public function it_executes_final_approval_from_preview_for_mixed_line_resolutions(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $cashSaleReturn, $replacementSaleReturn, $cashDispatchDetail, $replacementProduct, , , $replacementDispatchDetail] = $this->createPendingApprovalMixedResolutionReturn();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);
        $this->assertSame(PosReturn::APPROVAL_STATUS_APPROVED, $posReturn->fresh()->approval_status);
        $this->assertNotNull($posReturn->fresh()->approved_at);
        $this->assertNotNull($posReturn->fresh()->received_at);
        $this->assertNotNull($posReturn->fresh()->settled_at);

        $this->assertSame('COMPLETED', $cashSaleReturn->fresh()->status);
        $this->assertSame('COMPLETED', $replacementSaleReturn->fresh()->status);
        $payment = SaleReturnPayment::query()->where('sale_return_id', $cashSaleReturn->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame($cashSaleReturn->id, $payment->saleReturn?->id);
        $this->assertSame('COMPLETED', $payment->saleReturn?->status);
        $this->assertSame(600.0, (float) $payment->amount);
        $this->assertSame('SRPAY/' . $cashSaleReturn->reference, $payment->reference);
        $this->assertSame('CASH', $payment->payment_method);
        $this->assertSame('PENYELESAIAN FINAL APPROVAL RETUR POS', $payment->note);
        $this->assertSame(1, (int) $cashDispatchDetail->fresh()->dispatched_quantity);
        $this->assertSame(2, (int) $replacementDispatchDetail->fresh()->dispatched_quantity);
        $this->assertDatabaseHas('dispatches', [
            'sale_id' => $replacementSaleReturn->sale_id,
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        $this->assertSame(10, (int) $replacementProduct->fresh()->product_quantity);
    }

    /** @test */
    public function it_classifies_stock_affecting_replacement_dispatch_details_as_inventory_managed(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, , $replacementSaleReturn] = $this->createPendingApprovalMixedResolutionReturn();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $generated = DispatchDetail::query()
            ->whereNotNull('replacement_of_dispatch_detail_id')
            ->get();

        $this->assertNotEmpty($generated, 'The replacement flow must generate dispatch details.');
        foreach ($generated as $row) {
            $this->assertNotNull(
                $row->getRawOriginal('is_inventory_managed'),
                'Classification must be persisted explicitly, never left null.'
            );
            $this->assertSame(
                1,
                (int) $row->getRawOriginal('is_inventory_managed'),
                'A stock-affecting replacement must persist an explicit true classification.'
            );
        }
    }

    /** @test */
    public function it_classifies_stockless_replacement_dispatch_details_as_non_inventory(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, , $replacementSaleReturn] = $this->createPendingApprovalMixedResolutionReturn();

        // A stockless replacement acknowledges the swap without moving inventory.
        SaleReturnDetail::query()
            ->where('sale_return_id', $replacementSaleReturn->id)
            ->update(['stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_STOCKLESS]);

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $generated = DispatchDetail::query()
            ->whereNotNull('replacement_of_dispatch_detail_id')
            ->get();

        $this->assertNotEmpty($generated);
        foreach ($generated as $row) {
            // (bool) null is also false, so assert the stored value explicitly: leaving
            // null would let discovery classify this as HISTORICAL_COMPATIBILITY.
            $this->assertNotNull(
                $row->getRawOriginal('is_inventory_managed'),
                'A stockless replacement must persist an explicit false, never null.'
            );
            $this->assertSame(0, (int) $row->getRawOriginal('is_inventory_managed'));
        }

        // Prove the downstream consequence: discovery must classify these as explicitly
        // non-inventory blockers, never as billable historical-compatibility evidence.
        $row = $generated->first();
        $location = \Modules\Setting\Entities\Location::findOrFail($row->location_id);
        $location->forceFill(['is_consignment' => true, 'is_active' => true])->saveQuietly();

        app(\Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService::class)
            ->discoverForSetting((int) $location->setting_id);

        $source = \Modules\Consignment\Entities\ConsignmentSoldSource::where('dispatch_detail_id', $row->id)->first();
        $this->assertNotNull($source);
        $this->assertTrue((bool) $source->has_reconstruction_blocker);
        $this->assertEquals('EXPLICIT_NON_INVENTORY', $source->source_snapshot['inventory_classification']);
    }

    /** @test */
    public function it_reduces_source_sale_detail_quantity_and_prorated_amounts_for_cash_return_lines(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, , , , , $cashSaleDetail] = $this->createPendingApprovalMixedResolutionReturn();

        PosReturnLine::query()
            ->where('pos_return_id', $posReturn->id)
            ->where('resolution', PosReturnLine::RESOLUTION_CASH_RETURN)
            ->update(['expected_cash_amount' => 240]);

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $cashSaleDetail = $cashSaleDetail->fresh();

        $this->assertSame(4, (int) $cashSaleDetail->quantity);
        $this->assertSame(960.0, (float) $cashSaleDetail->sub_total);
        $this->assertSame(120.0, (float) $cashSaleDetail->product_discount_amount);
        $this->assertSame(40.0, (float) $cashSaleDetail->product_tax_amount);
    }

    /** @test */
    public function it_uses_expected_cash_amount_as_sale_detail_monetary_source_of_truth_when_it_differs_from_proration(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, , , , , $cashSaleDetail] = $this->createPendingApprovalMixedResolutionReturn();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $cashSaleDetail = $cashSaleDetail->fresh();

        $this->assertSame(4, (int) $cashSaleDetail->quantity);
        $this->assertSame(600.0, (float) $cashSaleDetail->sub_total);
        $this->assertSame(120.0, (float) $cashSaleDetail->product_discount_amount);
        $this->assertSame(40.0, (float) $cashSaleDetail->product_tax_amount);
    }

    /** @test */
    public function it_recalculates_source_sale_totals_after_cash_return_corrections(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $cashSaleReturn] = $this->createPendingApprovalMixedResolutionReturn();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $cashSale = Sale::query()->findOrFail($cashSaleReturn->sale_id);

        $this->assertSame(560.0, (float) $cashSale->total_amount);
        $this->assertSame(560.0, (float) $cashSale->paid_amount);
        $this->assertSame(0.0, (float) $cashSale->due_amount);
        $this->assertSame('PAID', (string) $cashSale->payment_status);
    }

    /** @test */
    public function it_archives_full_cash_return_source_sales_with_returned_status_and_audit_note(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $saleReturn, $sale, $saleDetail, $dispatchDetail] = $this->createPendingApprovalFullCashReturn();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $sale = Sale::withArchived()->findOrFail($sale->id);

        $this->assertSame(Sale::STATUS_RETURNED, (string) $sale->status);
        $this->assertNotNull($sale->archived_at);
        $this->assertSame($this->actor->id, (int) $sale->archived_by);
        $this->assertStringContainsString(strtoupper((string) $posReturn->reference), strtoupper((string) $sale->note));
        $this->assertStringContainsString(strtoupper((string) $saleReturn->reference), strtoupper((string) $sale->note));
        $this->assertSame(0, (int) $saleDetail->fresh()->quantity);
        $this->assertSame(0, (int) $dispatchDetail->fresh()->dispatched_quantity);
    }

    /** @test */
    public function it_restores_returned_serial_stock_and_lineage_during_final_approval_receiving(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $saleReturn, $sale, $product, $serial] = $this->createPendingApprovalSerialCashReturn();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $product->refresh();
        $serial->refresh();
        $stock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();

        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);
        $this->assertSame('COMPLETED', $saleReturn->fresh()->status);
        $this->assertSame(1, (int) $product->product_quantity);
        $this->assertSame(1, (int) $stock->quantity);
        $this->assertSame(1, (int) $stock->quantity_non_tax);
        $this->assertNull($serial->dispatch_detail_id);
        $this->assertSame($this->location->id, (int) $serial->location_id);
        $this->assertSame(ProductSerialNumber::STATUS_ACTIVE, (string) $serial->status);

        $tracking = SalesOrderSerialTracking::query()
            ->where('sale_id', $sale->id)
            ->where('product_serial_number_id', $serial->id)
            ->first();

        $this->assertNotNull($tracking);
        $this->assertNotNull($tracking->return_date);

        $transaction = Transaction::query()
            ->where('product_id', $product->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($transaction);
        $this->assertSame('SALE_RETURN_GOOD_NON_TAX', (string) $transaction->type);
        $this->assertSame(1, (int) $transaction->quantity);
        $this->assertSame($this->location->id, (int) $transaction->location_id);
        $this->assertStringContainsString((string) $saleReturn->reference, (string) $transaction->reason);

        $this->assertTrue(
            SerialNumberHistory::query()
                ->where('product_serial_number_id', $serial->id)
                ->where('event_type', SerialNumberHistory::EVENT_SALE_RETURNED)
                ->exists()
        );
    }

    /** @test */
    public function it_dispatches_replacement_serials_with_lineage_tracking_and_history_during_final_approval(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $sale, $line, $sourceDispatchDetail, $returnedSerial, $replacementSerial, $product] = $this->createPendingApprovalSerialReplacementReturn();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $replacementDispatchDetail = DispatchDetail::query()
            ->where('pos_return_line_id', $line->id)
            ->where('replacement_of_dispatch_detail_id', $sourceDispatchDetail->id)
            ->first();

        $this->assertNotNull($replacementDispatchDetail);
        $this->assertSame($returnedSerial->id, (int) $replacementDispatchDetail->replacement_returned_serial_id);
        $this->assertSame([$replacementSerial->serial_number], json_decode((string) $replacementDispatchDetail->serial_numbers, true));

        $replacementSerial->refresh();
        $product->refresh();

        $this->assertSame(ProductSerialNumber::STATUS_SOLD, (string) $replacementSerial->status);
        $this->assertSame($replacementDispatchDetail->id, (int) $replacementSerial->dispatch_detail_id);
        $this->assertSame(1, (int) $product->product_quantity);

        $tracking = SalesOrderSerialTracking::query()
            ->where('sale_id', $sale->id)
            ->where('product_serial_number_id', $replacementSerial->id)
            ->first();

        $this->assertNotNull($tracking);
        $this->assertNotNull($tracking->dispatch_date);
        $this->assertNull($tracking->return_date);

        $this->assertTrue(
            SerialNumberHistory::query()
                ->where('product_serial_number_id', $replacementSerial->id)
                ->where('event_type', SerialNumberHistory::EVENT_SOLD)
                ->where('reference_type', DispatchDetail::class)
                ->where('reference_id', $replacementDispatchDetail->id)
                ->exists()
        );

        $this->assertTrue(
            Transaction::query()
                ->where('product_id', $product->id)
                ->where('type', 'DISPATCH_RETURN')
                ->exists()
        );
    }

    /**
     * Policy update (align-bundle-return-replacement-rules, decision #1):
     * this fixture's parent and component replacement lines carry no
     * replacement_kind/execution_mode tag at all (legacy fixture predating
     * that concept), so the note-only gate's fallback preserves prior
     * physical behavior for it rather than guessing from the absence of a
     * replacement serial. Both lines therefore continue to dispatch
     * physically exactly as before — the change under this policy is that
     * the COMPONENT line is no longer treated as merely "informational
     * bundle trace" for a standalone parent replacement (that concept now
     * only applies when a detail's product differs from its own line's
     * product); here each line has its own matching product and both are
     * independent replacement targets, so BOTH dispatch.
     *
     * @test
     */
    public function it_dispatches_both_bundle_parent_and_component_as_independent_replacement_targets(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $parentLine, $componentLine, $bundleId, $parentProduct, $componentProduct] = $this->createPendingApprovalBundleReplacementReturn();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);

        $replacementDetails = DispatchDetail::query()
            ->whereIn('pos_return_line_id', [$parentLine->id, $componentLine->id])
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $replacementDetails);
        $this->assertTrue($replacementDetails->every(fn (DispatchDetail $detail) => (int) $detail->bundle_id === $bundleId));
        $this->assertTrue($replacementDetails->contains(fn (DispatchDetail $detail) => (int) $detail->pos_return_line_id === (int) $parentLine->id && (int) $detail->dispatched_quantity === 1));
        $this->assertTrue($replacementDetails->contains(fn (DispatchDetail $detail) => (int) $detail->pos_return_line_id === (int) $componentLine->id && (int) $detail->dispatched_quantity === 2));

        $this->assertTrue(Transaction::query()
            ->where('product_id', $parentProduct->id)
            ->where('type', 'DISPATCH_RETURN')
            ->exists());
        $this->assertTrue(Transaction::query()
            ->where('product_id', $componentProduct->id)
            ->where('type', 'DISPATCH_RETURN')
            ->exists());
    }

    /** @test */
    public function it_receives_bundle_parent_and_managed_components_while_skipping_stockless_rows_during_final_approval(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $parentProduct, $componentProduct, $stocklessProduct] = $this->createPendingApprovalBundleCashReturnWithStocklessComponent();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $parentProduct->refresh();
        $componentProduct->refresh();
        $stocklessProduct->refresh();

        $parentStock = ProductStock::query()
            ->where('product_id', $parentProduct->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();
        $componentStock = ProductStock::query()
            ->where('product_id', $componentProduct->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();
        $stocklessStock = ProductStock::query()
            ->where('product_id', $stocklessProduct->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();

        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);
        $this->assertSame(1, (int) $parentProduct->product_quantity);
        $this->assertSame(1, (int) $parentStock->quantity);
        $this->assertSame(1, (int) $parentStock->quantity_tax);
        $this->assertSame(2, (int) $componentProduct->product_quantity);
        $this->assertSame(2, (int) $componentStock->quantity);
        $this->assertSame(2, (int) $componentStock->quantity_non_tax);
        $this->assertSame(0, (int) $stocklessProduct->product_quantity);
        $this->assertSame(0, (int) $stocklessStock->quantity);

        $transactions = Transaction::query()
            ->whereIn('product_id', [$parentProduct->id, $componentProduct->id, $stocklessProduct->id])
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $transactions);
        $this->assertTrue($transactions->contains(fn (Transaction $transaction) => (int) $transaction->product_id === (int) $parentProduct->id
            && (string) $transaction->type === 'SALE_RETURN_GOOD_TAX'
            && (int) $transaction->quantity === 1));
        $this->assertTrue($transactions->contains(fn (Transaction $transaction) => (int) $transaction->product_id === (int) $componentProduct->id
            && (string) $transaction->type === 'SALE_RETURN_GOOD_NON_TAX'
            && (int) $transaction->quantity === 2));
        $this->assertFalse($transactions->contains(fn (Transaction $transaction) => (int) $transaction->product_id === (int) $stocklessProduct->id));
    }

    /** @test */
    public function it_reduces_bundle_component_sale_money_for_cash_return_while_preserving_stockless_bundle_rows(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $parentProduct, $componentProduct, $stocklessProduct] = $this->createPendingApprovalBundleCashReturnWithStocklessComponent();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $saleReturn = SaleReturn::query()->where('pos_return_id', $posReturn->id)->firstOrFail();
        $sale = Sale::withArchived()->findOrFail($saleReturn->sale_id);
        $parentDetail = SaleDetails::query()->where('sale_id', $sale->id)->where('product_id', $parentProduct->id)->firstOrFail();
        $componentDetail = SaleDetails::query()->where('sale_id', $sale->id)->where('product_id', $componentProduct->id)->firstOrFail();
        $stocklessDetail = SaleDetails::query()->where('sale_id', $sale->id)->where('product_id', $stocklessProduct->id)->firstOrFail();
        $saleReturnPayment = \Modules\SalesReturn\Entities\SaleReturnPayment::query()
            ->where('sale_return_id', $saleReturn->id)
            ->firstOrFail();

        $this->assertSame(0, (int) $parentDetail->quantity);
        $this->assertSame(0.0, (float) $parentDetail->sub_total);
        $this->assertSame(0, (int) $componentDetail->quantity);
        $this->assertSame(0.0, (float) $componentDetail->sub_total);
        $this->assertSame(0, (int) $stocklessDetail->quantity);
        $this->assertSame(0.0, (float) $stocklessDetail->sub_total);
        $this->assertSame(0.0, (float) $sale->total_amount);
        $this->assertSame(0.0, (float) $sale->paid_amount);
        $this->assertSame(500.0, (float) $saleReturnPayment->amount);
        $this->assertSame(Sale::STATUS_RETURNED, (string) $sale->status);
    }

    /** @test */
    public function it_corrects_split_owner_bundle_component_sales_with_zero_quantity_placeholders_during_final_approval(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [
            $posReturn,
            $parentSale,
            $componentSale,
            $componentPlaceholderDetail,
            $componentBundleItem,
            $componentDispatchDetail,
            $componentPayment,
            $componentProduct,
        ] = $this->createPendingApprovalSplitOwnerBundleCashReturnWithZeroQuantityComponentPlaceholder();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $parentSale = Sale::withArchived()->findOrFail($parentSale->id);
        $componentSale = Sale::withArchived()->findOrFail($componentSale->id);
        $componentPlaceholderDetail = SaleDetails::query()->findOrFail($componentPlaceholderDetail->id);
        $componentBundleItem = \Modules\Sale\Entities\SaleBundleItem::query()->findOrFail($componentBundleItem->id);
        $componentDispatchDetail = DispatchDetail::query()->findOrFail($componentDispatchDetail->id);
        $componentSaleReturn = SaleReturn::query()
            ->where('pos_return_id', $posReturn->id)
            ->where('sale_id', $componentSale->id)
            ->firstOrFail();
        $componentRefund = SaleReturnPayment::query()
            ->where('sale_return_id', $componentSaleReturn->id)
            ->firstOrFail();
        $componentStock = ProductStock::query()
            ->where('product_id', $componentProduct->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();

        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);
        $this->assertSame(0, (int) $componentPlaceholderDetail->quantity);
        $this->assertSame(0.0, (float) $componentPlaceholderDetail->sub_total);
        $this->assertSame(0, (int) $componentBundleItem->quantity);
        $this->assertSame(0.0, (float) $componentBundleItem->sub_total);
        $this->assertSame(0, (int) $componentDispatchDetail->dispatched_quantity);
        $this->assertSame(2, (int) $componentStock->quantity);
        $this->assertSame(0.0, (float) $componentSale->total_amount);
        $this->assertSame(0.0, (float) $componentSale->paid_amount);
        $this->assertSame(0.0, (float) $componentSale->due_amount);
        $this->assertSame('PAID', (string) $componentSale->payment_status);
        $this->assertSame(SalePayment::STATUS_INVALIDATED, (string) $componentPayment->fresh()->status);
        $this->assertSame(0.0, (float) SalePayment::query()->where('sale_id', $componentSale->id)->active()->sum('amount'));
        $this->assertSame(120.0, (float) $componentRefund->amount);
        $this->assertSame(Sale::STATUS_RETURNED, (string) $parentSale->status);
        $this->assertSame(Sale::STATUS_RETURNED, (string) $componentSale->status);
    }

    /** @test */
    public function it_handles_mixed_bundle_cash_return_and_replacement_for_the_same_serialized_sku(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [
            $posReturn,
            $cashSale,
            $componentSale,
            $replacementSale,
            $componentBundleItem,
            $replacementSaleDetail,
            $cashReturnedSerial,
            $replacementReturnedSerial,
            $replacementSerial,
        ] = $this->createPendingApprovalMixedBundleCashReturnAndReplacement();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $cashSale = Sale::withArchived()->with('saleDispatches.details.replacementReturnedSerial')->findOrFail($cashSale->id);
        $componentSale = Sale::withArchived()->findOrFail($componentSale->id);
        $replacementSale = Sale::withArchived()->with('saleDispatches.details.replacementReturnedSerial')->findOrFail($replacementSale->id);
        $componentBundleItem = \Modules\Sale\Entities\SaleBundleItem::query()->findOrFail($componentBundleItem->id);
        $replacementSaleDetail = SaleDetails::query()->findOrFail($replacementSaleDetail->id);
        $replacementSerial = $replacementSerial->fresh();

        app(\Modules\Sale\Services\SaleSerialDisplayResolver::class)->annotateDispatchesForSale($cashSale);
        app(\Modules\Sale\Services\SaleSerialDisplayResolver::class)->annotateDispatchesForSale($replacementSale);

        $cashBadges = $cashSale->saleDispatches
            ->flatMap(fn ($dispatch) => $dispatch->details)
            ->flatMap(fn ($detail) => collect($detail->serialNumberBadges ?? []));
        $replacementBadges = $replacementSale->saleDispatches
            ->flatMap(fn ($dispatch) => $dispatch->details)
            ->flatMap(fn ($detail) => collect($detail->serialNumberBadges ?? []));

        $replacementDispatchDetail = DispatchDetail::query()
            ->where('sale_id', $replacementSale->id)
            ->where('pos_return_line_id', '!=', null)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);
        $this->assertSame(0.0, (float) $componentSale->total_amount);
        $this->assertSame(0, (int) $componentBundleItem->quantity);
        $this->assertSame(0.0, (float) $componentBundleItem->sub_total);
        $this->assertSame(1, (int) $replacementSaleDetail->quantity);
        $this->assertSame(300.0, (float) $replacementSaleDetail->sub_total);
        $this->assertSame(ProductSerialNumber::STATUS_ACTIVE, (string) $cashReturnedSerial->fresh()->status);
        $this->assertSame(ProductSerialNumber::STATUS_ACTIVE, (string) $replacementReturnedSerial->fresh()->status);
        $this->assertSame(ProductSerialNumber::STATUS_SOLD, (string) $replacementSerial->status);
        $this->assertSame($replacementReturnedSerial->id, (int) $replacementDispatchDetail->replacement_returned_serial_id);
        $this->assertTrue($cashBadges->contains(fn (array $badge) => $badge['serial_number'] === $cashReturnedSerial->serial_number && $badge['state'] === 'returned'));
        $this->assertTrue($replacementBadges->contains(fn (array $badge) => $badge['serial_number'] === $replacementReturnedSerial->serial_number && $badge['state'] === 'returned'));
        $this->assertTrue($replacementBadges->contains(fn (array $badge) => $badge['serial_number'] === $replacementSerial->serial_number && $badge['state'] === 'replacement'));
    }

    /**
     * Policy update (align-bundle-return-replacement-rules, decision #1):
     * replaceability follows the physical product, so an independent
     * bundle-component product_replacement line must NOT require its parent
     * bundle return line to exist — only a cash_return component still
     * requires its parent (see the sibling cash_return coverage in
     * POSReturnBundleCashReturnCompletenessTest). This test previously
     * asserted the OLD, now-incorrect behavior of blocking every bundle
     * component (cash or replacement alike) without a parent line.
     *
     * @test
     */
    public function it_allows_final_approval_execution_of_an_independent_bundle_component_replacement_without_its_parent_line(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $parentLine, $componentLine] = $this->createPendingApprovalBundleReplacementReturn();
        $parentLine->delete();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);
    }

    /** @test */
    public function it_preserves_bundle_parent_and_component_sale_details_during_replacement_execution(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $parentLine, $componentLine] = $this->createPendingApprovalBundleReplacementReturn();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $parentDetail = SaleDetails::query()->findOrFail($parentLine->sale_detail_id);
        $componentDetail = SaleDetails::query()->findOrFail($componentLine->sale_detail_id);

        $this->assertSame(1, (int) $parentDetail->quantity);
        $this->assertSame(300.0, (float) $parentDetail->sub_total);
        $this->assertSame(2, (int) $componentDetail->quantity);
        $this->assertSame(120.0, (float) $componentDetail->sub_total);
    }

    /** @test */
    public function it_splits_or_invalidates_active_sale_payments_last_payment_first_after_cash_return_corrections(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $cashSaleReturn] = $this->createPendingApprovalMixedResolutionReturn();

        $cashSale = Sale::query()->findOrFail($cashSaleReturn->sale_id);
        $cashSale->update([
            'paid_amount' => 800,
            'due_amount' => 1200,
            'payment_status' => 'PARTIAL',
        ]);

        $firstPayment = SalePayment::query()->create([
            'sale_id' => $cashSale->id,
            'amount' => 300,
            'date' => now()->subDays(2)->toDateString(),
            'reference' => 'PAY-A-' . uniqid(),
            'payment_method' => 'Cash',
            'stage_order' => 1,
        ]);
        $secondPayment = SalePayment::query()->create([
            'sale_id' => $cashSale->id,
            'amount' => 300,
            'date' => now()->subDay()->toDateString(),
            'reference' => 'PAY-B-' . uniqid(),
            'payment_method' => 'Transfer',
            'stage_order' => 2,
            'edc_reference' => 'EDC-B',
        ]);
        $thirdPayment = SalePayment::query()->create([
            'sale_id' => $cashSale->id,
            'amount' => 200,
            'date' => now()->toDateString(),
            'reference' => 'PAY-C-' . uniqid(),
            'payment_method' => 'QRIS',
            'stage_order' => 3,
            'edc_reference' => 'EDC-C',
        ]);

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $cashSale = $cashSale->fresh();
        $payments = SalePayment::query()
            ->where('sale_id', $cashSale->id)
            ->orderBy('stage_order')
            ->orderBy('id')
            ->get();

        $replacementPayment = $payments
            ->where('status', SalePayment::STATUS_ACTIVE)
            ->first(fn (SalePayment $payment) => (int) $payment->id !== (int) $firstPayment->id);

        $this->assertSame(560.0, (float) $cashSale->paid_amount);
        $this->assertSame(0.0, (float) $cashSale->due_amount);
        $this->assertSame('PAID', (string) $cashSale->payment_status);
        $this->assertCount(4, $payments);
        $this->assertSame(SalePayment::STATUS_ACTIVE, (string) $firstPayment->fresh()->status);
        $this->assertSame(SalePayment::STATUS_INVALIDATED, (string) $secondPayment->fresh()->status);
        $this->assertSame(SalePayment::STATUS_INVALIDATED, (string) $thirdPayment->fresh()->status);
        $this->assertNotNull($secondPayment->fresh()->invalidated_at);
        $this->assertSame('POS_RETURN_CASH_CORRECTION', (string) $secondPayment->fresh()->invalidation_source);
        $this->assertSame((int) $cashSaleReturn->id, (int) $secondPayment->fresh()->invalidation_source_id);
        $this->assertSame((string) $secondPayment->fresh()->payment_method, (string) $replacementPayment?->payment_method);
        $this->assertSame(2, (int) $replacementPayment?->stage_order);
        $this->assertSame(260.0, (float) $replacementPayment?->amount);
        $this->assertSame('EDC-B', (string) $replacementPayment?->edc_reference);
        $this->assertNull($replacementPayment?->idempotency_key);
        $this->assertStringContainsString(
            strtoupper('Auto-split from invalidated payment #' . $secondPayment->id),
            strtoupper((string) $replacementPayment?->note)
        );
        $this->assertSame(560.0, (float) SalePayment::query()
            ->where('sale_id', $cashSale->id)
            ->active()
            ->sum('amount'));
    }

    /** @test */
    public function it_rolls_back_final_approval_from_preview_failures(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $cashSaleReturn] = $this->createPendingApprovalMixedResolutionReturn();
        $initialDispatchCount = Dispatch::query()->count();

        $service = new class extends PosReturnLifecycleService {
            protected function finalizeApprovalExecution(\Modules\Pos\Entities\PosReturn $posReturn, ?int $actorId, \Illuminate\Support\Carbon $completedAt): void
            {
                parent::finalizeApprovalExecution($posReturn, $actorId, $completedAt);

                throw new \RuntimeException('final preview approval failed');
            }
        };

        try {
            $service->executeApprovalFromPreview($posReturn->id);
            $this->fail('Expected final preview approval failure was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('final preview approval failed', $exception->getMessage());
        }

        $this->assertSame(PosReturn::STATUS_PENDING_APPROVAL, $posReturn->fresh()->status);
        $this->assertSame(PosReturn::APPROVAL_STATUS_PENDING, $posReturn->fresh()->approval_status);
        $this->assertNull($posReturn->fresh()->approved_at);
        $this->assertSame('PENDING APPROVAL', $cashSaleReturn->fresh()->status);
        $this->assertDatabaseCount('sale_return_payments', 0);
        $this->assertSame($initialDispatchCount, Dispatch::query()->count());
    }

    /**
     * @return array{0: PosReturn, 1: SaleReturn}
     */
    protected function createPendingApprovalReturn(): array
    {
        $sale = $this->createSale('PENDING APPROVAL');
        $posReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
        ]);
        $saleReturn = $this->createSaleReturn($posReturn, $sale, [
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
        ]);

        return [$posReturn, $saleReturn];
    }

    /**
     * @return array{0: PosReturn, 1: SaleReturn, 2: SaleReturnDetail, 3: DispatchDetail}
     */
    protected function createAwaitingReceiveReturn(): array
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-' . uniqid(),
            'sale_price' => 500,
            'stock_qty' => 10,
        ]);
        $sale = $this->createSale('DISPATCHED');
        $dispatch = Dispatch::query()->create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        $dispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 2,
            'location_id' => $this->location->id,
            'tax_id' => null,
        ]);

        $posReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_APPROVED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'total_amount' => 500,
            'approved_by' => $this->actor->id,
            'approved_at' => now(),
        ]);
        $saleReturn = $this->createSaleReturn($posReturn, $sale, [
            'status' => 'AWAITING RECEIVING',
            'approval_status' => 'APPROVED',
            'total_amount' => 500,
            'due_amount' => 500,
            'approved_by' => $this->actor->id,
            'approved_at' => now(),
        ]);
        $line = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => 1,
            'dispatch_detail_id' => $dispatchDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'tax_id' => null,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 500,
            'line_total' => 500,
            'serial_number_ids' => null,
            'bundle_group_key' => null,
            'bundle_parent_sale_detail_id' => null,
            'bundle_quantity' => null,
            'component_quantity_per_bundle' => null,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => null,
            'replacement_quantity' => null,
        ]);
        $detail = SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $line->id,
            'sale_detail_id' => 1,
            'dispatch_detail_id' => $dispatchDetail->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        return [$posReturn, $saleReturn, $detail, $dispatchDetail];
    }

    /**
     * @return array{0: PosReturn, 1: SaleReturn}
     */
    protected function createAwaitingSettlementReturn(): array
    {
        $sale = $this->createSale('COMPLETED');
        $posReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_AWAITING_SETTLEMENT,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'total_amount' => 600,
            'received_by' => $this->actor->id,
            'received_at' => now(),
        ]);
        $saleReturn = $this->createSaleReturn($posReturn, $sale, [
            'return_type' => 'Cash Return',
            'status' => 'AWAITING SETTLEMENT',
            'approval_status' => 'APPROVED',
            'total_amount' => 600,
            'paid_amount' => 0,
            'due_amount' => 600,
            'received_by' => $this->actor->id,
            'received_at' => now(),
        ]);

        return [$posReturn, $saleReturn];
    }

    /**
     * @return array{0: PosReturn, 1: SaleReturn, 2: SaleReturnDetail}
     */
    protected function createAwaitingDispatchReturn(): array
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-' . uniqid(),
            'sale_price' => 1000,
            'stock_qty' => 10,
        ]);
        $sale = $this->createSale('COMPLETED');
        $posReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_AWAITING_DISPATCH,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'total_amount' => 2000,
            'received_by' => $this->actor->id,
            'received_at' => now(),
        ]);
        $saleReturn = $this->createSaleReturn($posReturn, $sale, [
            'return_type' => 'Replacement',
            'status' => 'AWAITING DISPATCH',
            'approval_status' => 'APPROVED',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'received_by' => $this->actor->id,
            'received_at' => now(),
        ]);
        $line = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => 1,
            'dispatch_detail_id' => null,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'tax_id' => null,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'unit_price' => 1000,
            'line_total' => 2000,
            'serial_number_ids' => null,
            'bundle_group_key' => null,
            'bundle_parent_sale_detail_id' => null,
            'bundle_quantity' => null,
            'component_quantity_per_bundle' => null,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $product->id,
            'replacement_quantity' => 2,
        ]);
        $detail = SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $line->id,
            'sale_detail_id' => 1,
            'dispatch_detail_id' => null,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'quantity' => 2,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        return [$posReturn, $saleReturn, $detail];
    }

    /**
     * @return array{0: PosReturn, 1: SaleReturn}
     */
    protected function createApprovedCashReturn(): array
    {
        $sale = $this->createSale('DISPATCHED');
        $posReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_APPROVED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'total_amount' => 600,
            'approved_by' => $this->actor->id,
            'approved_at' => now(),
        ]);
        $saleReturn = $this->createSaleReturn($posReturn, $sale, [
            'status' => 'AWAITING RECEIVING',
            'approval_status' => 'APPROVED',
            'total_amount' => 600,
            'paid_amount' => 0,
            'due_amount' => 600,
            'approved_by' => $this->actor->id,
            'approved_at' => now(),
        ]);

        return [$posReturn, $saleReturn];
    }

    /**
     * @return array{0: PosReturn, 1: SaleReturn, 2: Sale, 3: SaleDetails, 4: DispatchDetail}
     */
    protected function createPendingApprovalFullCashReturn(): array
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'FULL-' . uniqid(),
            'sale_price' => 600,
            'stock_qty' => 10,
        ]);

        $sale = $this->createSale('DISPATCHED');
        $sale->update([
            'total_amount' => 600,
            'paid_amount' => 600,
            'due_amount' => 0,
        ]);

        $dispatch = Dispatch::query()->create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        $dispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'tax_id' => null,
        ]);

        $saleDetail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 600,
            'unit_price' => 600,
            'sub_total' => 600,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $posReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'total_amount' => 600,
        ]);

        $saleReturn = $this->createSaleReturn($posReturn, $sale, [
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'return_type' => 'Cash Return',
            'total_amount' => 600,
            'paid_amount' => 0,
            'due_amount' => 600,
        ]);

        $line = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'tax_id' => null,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 600,
            'line_total' => 600,
            'expected_cash_amount' => 600,
            'serial_number_ids' => null,
            'bundle_group_key' => null,
            'bundle_parent_sale_detail_id' => null,
            'bundle_quantity' => null,
            'component_quantity_per_bundle' => null,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => null,
            'replacement_quantity' => null,
        ]);

        SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $line->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 600,
            'unit_price' => 600,
            'sub_total' => 600,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        return [$posReturn, $saleReturn, $sale, $saleDetail, $dispatchDetail];
    }

    /**
    * @return array{0: PosReturn, 1: SaleReturn, 2: SaleReturn, 3: DispatchDetail, 4: \Modules\Product\Entities\Product, 5: SaleDetails, 6: SaleDetails, 7: DispatchDetail}
     */
    protected function createPendingApprovalMixedResolutionReturn(): array
    {
        $cashProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'CASH-' . uniqid(),
            'sale_price' => 600,
            'stock_qty' => 10,
        ]);
        $replacementProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'REPL-' . uniqid(),
            'sale_price' => 1000,
            'stock_qty' => 10,
        ]);

        $cashSale = $this->createSale('DISPATCHED');
        $replacementSale = $this->createSale('DISPATCHED');
        $cashDispatch = Dispatch::query()->create([
            'sale_id' => $cashSale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        $replacementDispatch = Dispatch::query()->create([
            'sale_id' => $replacementSale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        $cashDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $cashDispatch->id,
            'sale_id' => $cashSale->id,
            'product_id' => $cashProduct->id,
            'dispatched_quantity' => 2,
            'location_id' => $this->location->id,
            'tax_id' => null,
        ]);
        $replacementDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $replacementDispatch->id,
            'sale_id' => $replacementSale->id,
            'product_id' => $replacementProduct->id,
            'dispatched_quantity' => 2,
            'location_id' => $this->location->id,
            'tax_id' => null,
        ]);

        $cashSaleDetail = SaleDetails::query()->create([
            'sale_id' => $cashSale->id,
            'product_id' => $cashProduct->id,
            'product_name' => $cashProduct->product_name,
            'product_code' => $cashProduct->product_code,
            'quantity' => 5,
            'price' => 240,
            'unit_price' => 240,
            'sub_total' => 1200,
            'product_discount_amount' => 150,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 50,
        ]);

        $replacementSaleDetail = SaleDetails::query()->create([
            'sale_id' => $replacementSale->id,
            'product_id' => $replacementProduct->id,
            'product_name' => $replacementProduct->product_name,
            'product_code' => $replacementProduct->product_code,
            'quantity' => 2,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $posReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'total_amount' => 2600,
        ]);

        $cashSaleReturn = $this->createSaleReturn($posReturn, $cashSale, [
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'return_type' => 'Cash Return',
            'total_amount' => 600,
            'paid_amount' => 0,
            'due_amount' => 600,
        ]);
        $replacementSaleReturn = $this->createSaleReturn($posReturn, $replacementSale, [
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'return_type' => 'Replacement',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
        ]);

        $cashLine = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'sale_return_id' => $cashSaleReturn->id,
            'sale_id' => $cashSale->id,
            'sale_detail_id' => $cashSaleDetail->id,
            'dispatch_detail_id' => $cashDispatchDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'tax_id' => null,
            'product_id' => $cashProduct->id,
            'product_name' => $cashProduct->product_name,
            'product_code' => $cashProduct->product_code,
            'quantity' => 1,
            'unit_price' => 600,
            'line_total' => 600,
            'expected_cash_amount' => 600,
            'serial_number_ids' => null,
            'bundle_group_key' => null,
            'bundle_parent_sale_detail_id' => null,
            'bundle_quantity' => null,
            'component_quantity_per_bundle' => null,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => null,
            'replacement_quantity' => null,
        ]);
        $replacementLine = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'sale_return_id' => $replacementSaleReturn->id,
            'sale_id' => $replacementSale->id,
            'sale_detail_id' => $replacementSaleDetail->id,
            'dispatch_detail_id' => $replacementDispatchDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'tax_id' => null,
            'product_id' => $replacementProduct->id,
            'product_name' => $replacementProduct->product_name,
            'product_code' => $replacementProduct->product_code,
            'quantity' => 2,
            'unit_price' => 1000,
            'line_total' => 2000,
            'serial_number_ids' => null,
            'bundle_group_key' => null,
            'bundle_parent_sale_detail_id' => null,
            'bundle_quantity' => null,
            'component_quantity_per_bundle' => null,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $replacementProduct->id,
            'replacement_quantity' => 2,
        ]);

        SaleReturnDetail::query()->create([
            'sale_return_id' => $cashSaleReturn->id,
            'pos_return_line_id' => $cashLine->id,
            'sale_detail_id' => $cashSaleDetail->id,
            'dispatch_detail_id' => $cashDispatchDetail->id,
            'product_id' => $cashProduct->id,
            'product_name' => $cashProduct->product_name,
            'product_code' => $cashProduct->product_code,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 600,
            'unit_price' => 600,
            'sub_total' => 600,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);
        SaleReturnDetail::query()->create([
            'sale_return_id' => $replacementSaleReturn->id,
            'pos_return_line_id' => $replacementLine->id,
            'sale_detail_id' => $replacementSaleDetail->id,
            'dispatch_detail_id' => $replacementDispatchDetail->id,
            'product_id' => $replacementProduct->id,
            'product_name' => $replacementProduct->product_name,
            'product_code' => $replacementProduct->product_code,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'quantity' => 2,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        return [$posReturn, $cashSaleReturn, $replacementSaleReturn, $cashDispatchDetail, $replacementProduct, $cashSaleDetail, $replacementSaleDetail, $replacementDispatchDetail];
    }

    /**
     * @return array{0: PosReturn, 1: SaleReturn, 2: Sale, 3: Product, 4: ProductSerialNumber}
     */
    protected function createPendingApprovalSerialCashReturn(): array
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'SER-' . uniqid(),
            'product_name' => 'Serialized Return ' . uniqid(),
            'sale_price' => 1500,
            'stock_qty' => 0,
            'serial_number_required' => true,
        ]);

        $sale = $this->createSale('DISPATCHED');
        $sale->update([
            'total_amount' => 1500,
            'paid_amount' => 1500,
            'due_amount' => 0,
        ]);

        $saleDetail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $dispatch = Dispatch::query()->create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        $dispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'serial_numbers' => json_encode(['SERIAL-' . uniqid()]),
        ]);

        $serial = $this->createSerialNumber($product, $this->location, 'SN-RET-' . uniqid());
        $serial->update([
            'dispatch_detail_id' => $dispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        SalesOrderSerialTracking::query()->create([
            'sale_id' => $sale->id,
            'product_serial_number_id' => $serial->id,
            'quantity_allocated' => 1,
            'dispatch_date' => now(),
            'return_date' => null,
        ]);

        $posReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'total_amount' => 1500,
        ]);

        $saleReturn = $this->createSaleReturn($posReturn, $sale, [
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'return_type' => 'Cash Return',
            'total_amount' => 1500,
            'paid_amount' => 0,
            'due_amount' => 1500,
        ]);

        $line = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'tax_id' => null,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 1500,
            'line_total' => 1500,
            'expected_cash_amount' => 1500,
            'serial_number_ids' => [$serial->id],
            'returned_serial_id' => $serial->id,
            'bundle_group_key' => null,
            'bundle_parent_sale_detail_id' => null,
            'bundle_quantity' => null,
            'component_quantity_per_bundle' => null,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => null,
            'replacement_quantity' => null,
        ]);

        SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $line->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'serial_number_ids' => [$serial->id],
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        return [$posReturn, $saleReturn, $sale, $product, $serial];
    }

    /**
     * @return array{0: PosReturn, 1: Sale, 2: PosReturnLine, 3: DispatchDetail, 4: ProductSerialNumber, 5: ProductSerialNumber, 6: Product}
     */
    protected function createPendingApprovalSerialReplacementReturn(): array
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'SER-REP-' . uniqid(),
            'product_name' => 'Serialized Replacement ' . uniqid(),
            'sale_price' => 1500,
            'stock_qty' => 1,
            'serial_number_required' => true,
        ]);

        $sale = $this->createSale('DISPATCHED');
        $sale->update([
            'total_amount' => 1500,
            'paid_amount' => 1500,
            'due_amount' => 0,
        ]);

        $saleDetail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $sourceDispatch = Dispatch::query()->create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        $sourceDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $sourceDispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'serial_numbers' => json_encode(['SERIAL-' . uniqid()]),
        ]);

        $returnedSerial = $this->createSerialNumber($product, $this->location, 'SN-RET-' . uniqid());
        $returnedSerial->update([
            'dispatch_detail_id' => $sourceDispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $replacementSerial = $this->createSerialNumber($product, $this->location, 'SN-REP-' . uniqid());
        $replacementSerial->update([
            'status' => ProductSerialNumber::STATUS_ACTIVE,
        ]);

        SalesOrderSerialTracking::query()->create([
            'sale_id' => $sale->id,
            'product_serial_number_id' => $returnedSerial->id,
            'quantity_allocated' => 1,
            'dispatch_date' => now(),
            'return_date' => null,
        ]);

        $posReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'total_amount' => 1500,
        ]);

        $saleReturn = $this->createSaleReturn($posReturn, $sale, [
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'return_type' => 'Replacement',
            'total_amount' => 1500,
            'paid_amount' => 0,
            'due_amount' => 1500,
        ]);

        $line = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $sourceDispatchDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'tax_id' => null,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 1500,
            'line_total' => 1500,
            'serial_number_ids' => [$returnedSerial->id],
            'returned_serial_id' => $returnedSerial->id,
            'replacement_serial_id' => $replacementSerial->id,
            'bundle_group_key' => null,
            'bundle_parent_sale_detail_id' => null,
            'bundle_quantity' => null,
            'component_quantity_per_bundle' => null,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $product->id,
            'replacement_quantity' => 1,
        ]);

        SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $line->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $sourceDispatchDetail->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'serial_number_ids' => [$returnedSerial->id],
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        return [$posReturn, $sale, $line, $sourceDispatchDetail, $returnedSerial, $replacementSerial, $product];
    }

    /**
     * @return array{0: PosReturn, 1: PosReturnLine, 2: PosReturnLine, 3: int, 4: Product, 5: Product}
     */
    protected function createPendingApprovalBundleReplacementReturn(): array
    {
        $bundleId = 9000 + random_int(1, 999);

        $parentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'BUNDLE-REP-PARENT-' . uniqid(),
            'sale_price' => 300,
            'stock_qty' => 1,
        ]);
        $componentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'BUNDLE-REP-COMP-' . uniqid(),
            'sale_price' => 60,
            'stock_qty' => 2,
        ]);

        $sale = $this->createSale('DISPATCHED');
        $sale->update([
            'total_amount' => 420,
            'paid_amount' => 420,
            'due_amount' => 0,
        ]);

        $parentSaleDetail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'price' => 300,
            'unit_price' => 300,
            'sub_total' => 300,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        $componentSaleDetail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'quantity' => 2,
            'price' => 60,
            'unit_price' => 60,
            'sub_total' => 120,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $dispatch = Dispatch::query()->create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        $parentDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $parentProduct->id,
            'bundle_id' => $bundleId,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'tax_id' => null,
        ]);
        $componentDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $componentProduct->id,
            'bundle_id' => $bundleId,
            'dispatched_quantity' => 2,
            'location_id' => $this->location->id,
            'tax_id' => null,
        ]);

        $posReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'total_amount' => 420,
        ]);
        $saleReturn = $this->createSaleReturn($posReturn, $sale, [
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'return_type' => 'Replacement',
            'total_amount' => 420,
            'paid_amount' => 0,
            'due_amount' => 420,
            'location_id' => $this->location->id,
        ]);

        $bundleGroupKey = 'BUNDLE-REP-' . uniqid();

        $parentLine = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'dispatch_detail_id' => $parentDispatchDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'tax_id' => null,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'unit_price' => 300,
            'line_total' => 300,
            'bundle_group_key' => $bundleGroupKey,
            'bundle_parent_sale_detail_id' => $parentSaleDetail->id,
            'bundle_quantity' => 1,
            'component_quantity_per_bundle' => null,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $parentProduct->id,
            'replacement_quantity' => 1,
            'line_meta' => ['bundle_id' => $bundleId],
        ]);
        $componentLine = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $componentSaleDetail->id,
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'tax_id' => null,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'quantity' => 2,
            'unit_price' => 60,
            'line_total' => 120,
            'bundle_group_key' => $bundleGroupKey,
            'bundle_parent_sale_detail_id' => $parentSaleDetail->id,
            'bundle_quantity' => 1,
            'component_quantity_per_bundle' => 2,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $componentProduct->id,
            'replacement_quantity' => 2,
            'line_meta' => ['bundle_id' => $bundleId],
        ]);

        SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $parentLine->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'dispatch_detail_id' => $parentDispatchDetail->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 300,
            'unit_price' => 300,
            'sub_total' => 300,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'bundle_group_key' => $bundleGroupKey,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);
        SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $componentLine->id,
            'sale_detail_id' => $componentSaleDetail->id,
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'quantity' => 2,
            'price' => 60,
            'unit_price' => 60,
            'sub_total' => 120,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'bundle_group_key' => $bundleGroupKey,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        return [$posReturn, $parentLine, $componentLine, $bundleId, $parentProduct, $componentProduct];
    }

    /**
     * @return array{0: PosReturn, 1: Product, 2: Product, 3: Product}
     */
    protected function createPendingApprovalBundleCashReturnWithStocklessComponent(): array
    {
        $tax = Tax::query()->create([
            'name' => 'PPN RETUR ' . uniqid(),
            'value' => 11,
            'is_default' => true,
        ]);

        $parentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'BUNDLE-PARENT-' . uniqid(),
            'sale_price' => 300,
            'stock_qty' => 0,
            'sale_tax_id' => $tax->id,
        ]);
        ProductStock::query()
            ->where('product_id', $parentProduct->id)
            ->where('location_id', $this->location->id)
            ->update(['tax_id' => $tax->id]);

        $componentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'BUNDLE-COMP-' . uniqid(),
            'sale_price' => 60,
            'stock_qty' => 0,
        ]);

        $stocklessProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'BUNDLE-AUDIT-' . uniqid(),
            'sale_price' => 80,
            'stock_qty' => 0,
        ]);
        $stocklessProduct->update([
            'stock_managed' => false,
            'product_quantity' => 0,
        ]);

        $sale = $this->createSale('DISPATCHED');
        $sale->update([
            'total_amount' => 500,
            'paid_amount' => 500,
            'due_amount' => 0,
        ]);

        $parentSaleDetail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'price' => 300,
            'unit_price' => 300,
            'sub_total' => 300,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        $componentSaleDetail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'quantity' => 2,
            'price' => 60,
            'unit_price' => 60,
            'sub_total' => 120,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        $stocklessSaleDetail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $stocklessProduct->id,
            'product_name' => $stocklessProduct->product_name,
            'product_code' => $stocklessProduct->product_code,
            'quantity' => 1,
            'price' => 80,
            'unit_price' => 80,
            'sub_total' => 80,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $dispatch = Dispatch::query()->create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        $parentDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $parentProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'tax_id' => $tax->id,
        ]);
        $componentDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $componentProduct->id,
            'dispatched_quantity' => 2,
            'location_id' => $this->location->id,
            'tax_id' => null,
        ]);

        $posReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'total_amount' => 500,
        ]);
        $saleReturn = $this->createSaleReturn($posReturn, $sale, [
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'return_type' => 'Cash Return',
            'total_amount' => 500,
            'paid_amount' => 0,
            'due_amount' => 500,
            'location_id' => $this->location->id,
        ]);

        $bundleGroupKey = 'BUNDLE-' . uniqid();

        $parentLine = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'dispatch_detail_id' => $parentDispatchDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'tax_id' => $tax->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'unit_price' => 300,
            'line_total' => 300,
            'expected_cash_amount' => 300,
            'serial_number_ids' => null,
            'bundle_group_key' => $bundleGroupKey,
            'bundle_parent_sale_detail_id' => $parentSaleDetail->id,
            'bundle_quantity' => 1,
            'component_quantity_per_bundle' => null,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => null,
            'replacement_quantity' => null,
        ]);
        $componentLine = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $componentSaleDetail->id,
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'tax_id' => null,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'quantity' => 2,
            'unit_price' => 60,
            'line_total' => 120,
            'expected_cash_amount' => 120,
            'serial_number_ids' => null,
            'bundle_group_key' => $bundleGroupKey,
            'bundle_parent_sale_detail_id' => $parentSaleDetail->id,
            'bundle_quantity' => 1,
            'component_quantity_per_bundle' => 2,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => null,
            'replacement_quantity' => null,
        ]);
        $stocklessLine = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $stocklessSaleDetail->id,
            'dispatch_detail_id' => null,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'tax_id' => null,
            'product_id' => $stocklessProduct->id,
            'product_name' => $stocklessProduct->product_name,
            'product_code' => $stocklessProduct->product_code,
            'quantity' => 1,
            'unit_price' => 80,
            'line_total' => 80,
            'expected_cash_amount' => 80,
            'serial_number_ids' => null,
            'bundle_group_key' => $bundleGroupKey,
            'bundle_parent_sale_detail_id' => $parentSaleDetail->id,
            'bundle_quantity' => 1,
            'component_quantity_per_bundle' => 1,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_STOCKLESS,
            'replacement_product_id' => null,
            'replacement_quantity' => null,
        ]);

        SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $parentLine->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'dispatch_detail_id' => $parentDispatchDetail->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'location_id' => $this->location->id,
            'tax_id' => $tax->id,
            'quantity' => 1,
            'price' => 300,
            'unit_price' => 300,
            'sub_total' => 300,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'bundle_group_key' => $bundleGroupKey,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);
        SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $componentLine->id,
            'sale_detail_id' => $componentSaleDetail->id,
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'quantity' => 2,
            'price' => 60,
            'unit_price' => 60,
            'sub_total' => 120,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'bundle_group_key' => $bundleGroupKey,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);
        SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $stocklessLine->id,
            'sale_detail_id' => $stocklessSaleDetail->id,
            'dispatch_detail_id' => null,
            'product_id' => $stocklessProduct->id,
            'product_name' => $stocklessProduct->product_name,
            'product_code' => $stocklessProduct->product_code,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 80,
            'unit_price' => 80,
            'sub_total' => 80,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'bundle_group_key' => $bundleGroupKey,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_STOCKLESS,
        ]);

        return [$posReturn, $parentProduct, $componentProduct, $stocklessProduct];
    }

    /**
     * @return array{0: PosReturn, 1: Sale, 2: Sale, 3: SaleDetails, 4: \Modules\Sale\Entities\SaleBundleItem, 5: DispatchDetail, 6: SalePayment, 7: Product}
     */
    protected function createPendingApprovalSplitOwnerBundleCashReturnWithZeroQuantityComponentPlaceholder(): array
    {
        $parentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'BUNDLE-ZQ-PARENT-' . uniqid(),
            'sale_price' => 300,
            'stock_qty' => 0,
        ]);
        $componentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'BUNDLE-ZQ-COMP-' . uniqid(),
            'sale_price' => 60,
            'stock_qty' => 0,
        ]);

        $parentSale = $this->createSale('DISPATCHED');
        $parentSale->update([
            'total_amount' => 300,
            'paid_amount' => 300,
            'due_amount' => 0,
        ]);

        $componentSale = $this->createSale('DISPATCHED');
        $componentSale->update([
            'total_amount' => 120,
            'paid_amount' => 120,
            'due_amount' => 0,
        ]);

        $parentSaleDetail = SaleDetails::query()->create([
            'sale_id' => $parentSale->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'price' => 300,
            'unit_price' => 300,
            'sub_total' => 300,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        $componentPlaceholderDetail = SaleDetails::query()->create([
            'sale_id' => $componentSale->id,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'quantity' => 0,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $componentBundleItem = \Modules\Sale\Entities\SaleBundleItem::query()->create([
            'sale_id' => $componentSale->id,
            'sale_detail_id' => $componentPlaceholderDetail->id,
            'bundle_id' => 9100 + random_int(1, 999),
            'bundle_item_id' => 9200 + random_int(1, 999),
            'product_id' => $componentProduct->id,
            'name' => $componentProduct->product_name,
            'quantity' => 2,
            'price' => 60,
            'sub_total' => 120,
            'tax_amount' => 0,
            'line_group_key' => 'split-owner-' . uniqid(),
        ]);

        $parentDispatch = Dispatch::query()->create([
            'sale_id' => $parentSale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        $componentDispatch = Dispatch::query()->create([
            'sale_id' => $componentSale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        $parentDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $parentDispatch->id,
            'sale_id' => $parentSale->id,
            'product_id' => $parentProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'tax_id' => null,
        ]);
        $componentDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $componentDispatch->id,
            'sale_id' => $componentSale->id,
            'product_id' => $componentProduct->id,
            'dispatched_quantity' => 2,
            'location_id' => $this->location->id,
            'tax_id' => null,
        ]);

        $componentPayment = SalePayment::query()->create([
            'sale_id' => $componentSale->id,
            'amount' => 120,
            'date' => now()->toDateString(),
            'reference' => 'PAY-ZQ-' . uniqid(),
            'payment_method' => 'Cash',
            'stage_order' => 1,
        ]);

        $posReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'total_amount' => 420,
        ]);

        $parentSaleReturn = $this->createSaleReturn($posReturn, $parentSale, [
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'return_type' => 'Cash Return',
            'total_amount' => 300,
            'paid_amount' => 0,
            'due_amount' => 300,
            'location_id' => $this->location->id,
        ]);
        $componentSaleReturn = $this->createSaleReturn($posReturn, $componentSale, [
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'return_type' => 'Cash Return',
            'total_amount' => 120,
            'paid_amount' => 0,
            'due_amount' => 120,
            'location_id' => $this->location->id,
        ]);

        $bundleGroupKey = 'BUNDLE-ZQ-' . uniqid();

        $parentLine = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'sale_return_id' => $parentSaleReturn->id,
            'sale_id' => $parentSale->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'dispatch_detail_id' => $parentDispatchDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'tax_id' => null,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'unit_price' => 300,
            'line_total' => 300,
            'expected_cash_amount' => 300,
            'serial_number_ids' => null,
            'bundle_group_key' => $bundleGroupKey,
            'bundle_parent_sale_detail_id' => $parentSaleDetail->id,
            'bundle_quantity' => 1,
            'component_quantity_per_bundle' => null,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => null,
            'replacement_quantity' => null,
            'line_meta' => [
                'bundle_trace' => [[
                    'product_id' => $componentProduct->id,
                    'quantity_per_bundle' => 2,
                    'total_component_quantity' => 2,
                ]],
            ],
        ]);

        $parentReturnDetail = SaleReturnDetail::query()->create([
            'sale_return_id' => $parentSaleReturn->id,
            'pos_return_line_id' => $parentLine->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'dispatch_detail_id' => $parentDispatchDetail->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 300,
            'unit_price' => 300,
            'sub_total' => 300,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'bundle_group_key' => $bundleGroupKey,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'parent',
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                'dispatch_resolution' => 'pos_return_line.dispatch_detail_id',
                'source_sale_id' => $parentSale->id,
                'source_sale_detail_id' => $parentSaleDetail->id,
                'component_source_sale_detail_id' => $parentSaleDetail->id,
                'component_dispatch_detail_id' => $parentDispatchDetail->id,
                'component_sale_bundle_item_id' => null,
                'component_line_group_key' => '',
                'component_bundle_id' => null,
                'component_quantity_per_bundle' => null,
                'quantity_source' => 'sale_detail',
                'commercial_value_source' => 'sale_detail',
                'cash_return_amount' => 300,
                'planned_amount' => 300,
            ],
        ]);
        SaleReturnDetail::query()->create([
            'sale_return_id' => $componentSaleReturn->id,
            'pos_return_line_id' => $parentLine->id,
            'sale_detail_id' => $componentPlaceholderDetail->id,
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'quantity' => 2,
            'price' => 60,
            'unit_price' => 60,
            'sub_total' => 120,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'bundle_group_key' => $bundleGroupKey,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'component',
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                'dispatch_resolution' => 'component.sale_detail_id',
                'source_sale_id' => $componentSale->id,
                'source_sale_detail_id' => $parentSaleDetail->id,
                'component_source_sale_detail_id' => $componentPlaceholderDetail->id,
                'component_dispatch_detail_id' => $componentDispatchDetail->id,
                'component_sale_bundle_item_id' => $componentBundleItem->id,
                'component_line_group_key' => (string) $componentBundleItem->line_group_key,
                'component_bundle_id' => (int) $componentBundleItem->bundle_id,
                'component_quantity_per_bundle' => 2,
                'quantity_source' => 'sale_bundle_item',
                'commercial_value_source' => 'sale_bundle_item',
                'cash_return_amount' => 0,
                'planned_amount' => 120,
            ],
        ]);

        $parentLine->update([
            'sale_return_detail_id' => $parentReturnDetail->id,
        ]);

        return [
            $posReturn,
            $parentSale,
            $componentSale,
            $componentPlaceholderDetail,
            $componentBundleItem,
            $componentDispatchDetail,
            $componentPayment,
            $componentProduct,
        ];
    }

    /**
     * @return array{0: PosReturn, 1: Sale, 2: Sale, 3: Sale, 4: \Modules\Sale\Entities\SaleBundleItem, 5: SaleDetails, 6: ProductSerialNumber, 7: ProductSerialNumber, 8: ProductSerialNumber}
     */
    protected function createPendingApprovalMixedBundleCashReturnAndReplacement(): array
    {
        $parentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'BUNDLE-MIX-PARENT-' . uniqid(),
            'product_name' => 'Mixed Bundle Parent ' . uniqid(),
            'sale_price' => 300,
            'stock_qty' => 1,
            'serial_number_required' => true,
        ]);
        $componentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'BUNDLE-MIX-COMP-' . uniqid(),
            'product_name' => 'Mixed Bundle Component ' . uniqid(),
            'sale_price' => 60,
            'stock_qty' => 0,
        ]);

        $cashSale = $this->createSale('DISPATCHED');
        $cashSale->update(['total_amount' => 300, 'paid_amount' => 300, 'due_amount' => 0]);
        $componentSale = $this->createSale('DISPATCHED');
        $componentSale->update(['total_amount' => 120, 'paid_amount' => 120, 'due_amount' => 0]);
        $replacementSale = $this->createSale('DISPATCHED');
        $replacementSale->update(['total_amount' => 300, 'paid_amount' => 300, 'due_amount' => 0]);

        $cashSaleDetail = SaleDetails::query()->create([
            'sale_id' => $cashSale->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'price' => 300,
            'unit_price' => 300,
            'sub_total' => 300,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        $componentPlaceholderDetail = SaleDetails::query()->create([
            'sale_id' => $componentSale->id,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'quantity' => 0,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        $replacementSaleDetail = SaleDetails::query()->create([
            'sale_id' => $replacementSale->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'price' => 300,
            'unit_price' => 300,
            'sub_total' => 300,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $componentBundleItem = \Modules\Sale\Entities\SaleBundleItem::query()->create([
            'sale_id' => $componentSale->id,
            'sale_detail_id' => $componentPlaceholderDetail->id,
            'bundle_id' => 9500,
            'bundle_item_id' => 9501,
            'product_id' => $componentProduct->id,
            'name' => $componentProduct->product_name,
            'quantity' => 2,
            'price' => 60,
            'sub_total' => 120,
            'tax_amount' => 0,
            'line_group_key' => 'mixed-bundle-component-0',
        ]);

        $cashDispatch = Dispatch::query()->create([
            'sale_id' => $cashSale->id,
            'dispatch_date' => now()->subDay(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        $componentDispatch = Dispatch::query()->create([
            'sale_id' => $componentSale->id,
            'dispatch_date' => now()->subDay(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        $replacementSourceDispatch = Dispatch::query()->create([
            'sale_id' => $replacementSale->id,
            'dispatch_date' => now()->subDay(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        $cashDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $cashDispatch->id,
            'sale_id' => $cashSale->id,
            'product_id' => $parentProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'serial_numbers' => json_encode(['SN-MIX-CASH-OLD']),
        ]);
        $componentDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $componentDispatch->id,
            'sale_id' => $componentSale->id,
            'product_id' => $componentProduct->id,
            'dispatched_quantity' => 2,
            'location_id' => $this->location->id,
            'tax_id' => null,
        ]);
        $replacementSourceDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $replacementSourceDispatch->id,
            'sale_id' => $replacementSale->id,
            'product_id' => $parentProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'serial_numbers' => json_encode(['SN-MIX-REP-OLD']),
        ]);

        $cashReturnedSerial = $this->createSerialNumber($parentProduct, $this->location, 'SN-MIX-CASH-OLD');
        $cashReturnedSerial->update([
            'dispatch_detail_id' => $cashDispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);
        $replacementReturnedSerial = $this->createSerialNumber($parentProduct, $this->location, 'SN-MIX-REP-OLD');
        $replacementReturnedSerial->update([
            'dispatch_detail_id' => $replacementSourceDispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);
        $replacementSerial = $this->createSerialNumber($parentProduct, $this->location, 'SN-MIX-REP-NEW');
        $replacementSerial->update(['status' => ProductSerialNumber::STATUS_ACTIVE]);

        SalesOrderSerialTracking::query()->create([
            'sale_id' => $cashSale->id,
            'product_serial_number_id' => $cashReturnedSerial->id,
            'quantity_allocated' => 1,
            'dispatch_date' => now()->subDay(),
            'return_date' => null,
        ]);
        SalesOrderSerialTracking::query()->create([
            'sale_id' => $replacementSale->id,
            'product_serial_number_id' => $replacementReturnedSerial->id,
            'quantity_allocated' => 1,
            'dispatch_date' => now()->subDay(),
            'return_date' => null,
        ]);

        $posReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'total_amount' => 600,
        ]);

        $cashSaleReturn = $this->createSaleReturn($posReturn, $cashSale, [
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'return_type' => 'Cash Return',
            'total_amount' => 300,
            'paid_amount' => 0,
            'due_amount' => 300,
            'location_id' => $this->location->id,
        ]);
        $componentSaleReturn = $this->createSaleReturn($posReturn, $componentSale, [
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'return_type' => 'Cash Return',
            'total_amount' => 120,
            'paid_amount' => 0,
            'due_amount' => 120,
            'location_id' => $this->location->id,
        ]);
        $replacementSaleReturn = $this->createSaleReturn($posReturn, $replacementSale, [
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'return_type' => 'Replacement',
            'total_amount' => 300,
            'paid_amount' => 0,
            'due_amount' => 300,
            'location_id' => $this->location->id,
        ]);

        $cashLine = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'sale_return_id' => $cashSaleReturn->id,
            'sale_id' => $cashSale->id,
            'sale_detail_id' => $cashSaleDetail->id,
            'dispatch_detail_id' => $cashDispatchDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'tax_id' => null,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'unit_price' => 300,
            'line_total' => 300,
            'expected_cash_amount' => 300,
            'serial_number_ids' => [$cashReturnedSerial->id],
            'returned_serial_id' => $cashReturnedSerial->id,
            'bundle_group_key' => 'BUNDLE-MIX-CASH-' . uniqid(),
            'bundle_parent_sale_detail_id' => $cashSaleDetail->id,
            'bundle_quantity' => 1,
            'component_quantity_per_bundle' => null,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => null,
            'replacement_quantity' => null,
            'line_meta' => [
                'bundle_trace' => [[
                    'product_id' => $componentProduct->id,
                    'quantity_per_bundle' => 2,
                    'total_component_quantity' => 2,
                ]],
            ],
        ]);
        $replacementLine = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'sale_return_id' => $replacementSaleReturn->id,
            'sale_id' => $replacementSale->id,
            'sale_detail_id' => $replacementSaleDetail->id,
            'dispatch_detail_id' => $replacementSourceDispatchDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'tax_id' => null,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'unit_price' => 300,
            'line_total' => 300,
            'serial_number_ids' => [$replacementReturnedSerial->id],
            'returned_serial_id' => $replacementReturnedSerial->id,
            'replacement_serial_id' => $replacementSerial->id,
            'bundle_group_key' => 'BUNDLE-MIX-REP-' . uniqid(),
            'bundle_parent_sale_detail_id' => $replacementSaleDetail->id,
            'bundle_quantity' => 1,
            'component_quantity_per_bundle' => null,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $parentProduct->id,
            'replacement_quantity' => 1,
            'line_meta' => [
                'bundle_trace' => [[
                    'product_id' => $componentProduct->id,
                    'quantity_per_bundle' => 2,
                    'total_component_quantity' => 2,
                ]],
            ],
        ]);

        $cashParentDetail = SaleReturnDetail::query()->create([
            'sale_return_id' => $cashSaleReturn->id,
            'pos_return_line_id' => $cashLine->id,
            'sale_detail_id' => $cashSaleDetail->id,
            'dispatch_detail_id' => $cashDispatchDetail->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 300,
            'unit_price' => 300,
            'sub_total' => 300,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'bundle_group_key' => $cashLine->bundle_group_key,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'serial_number_ids' => [$cashReturnedSerial->id],
            'execution_context' => [
                'row_type' => 'parent',
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                'dispatch_resolution' => 'pos_return_line.dispatch_detail_id',
                'source_sale_id' => $cashSale->id,
                'source_sale_detail_id' => $cashSaleDetail->id,
                'component_source_sale_detail_id' => $cashSaleDetail->id,
                'component_dispatch_detail_id' => $cashDispatchDetail->id,
                'component_sale_bundle_item_id' => null,
                'component_line_group_key' => '',
                'component_bundle_id' => null,
                'component_quantity_per_bundle' => null,
                'quantity_source' => 'sale_detail',
                'commercial_value_source' => 'sale_detail',
                'cash_return_amount' => 300,
                'planned_amount' => 300,
            ],
        ]);
        SaleReturnDetail::query()->create([
            'sale_return_id' => $componentSaleReturn->id,
            'pos_return_line_id' => $cashLine->id,
            'sale_detail_id' => $componentPlaceholderDetail->id,
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'quantity' => 2,
            'price' => 60,
            'unit_price' => 60,
            'sub_total' => 120,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'bundle_group_key' => $cashLine->bundle_group_key,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'component',
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                'dispatch_resolution' => 'component.sale_id+product_id',
                'source_sale_id' => $componentSale->id,
                'source_sale_detail_id' => $cashSaleDetail->id,
                'component_source_sale_detail_id' => $componentPlaceholderDetail->id,
                'component_dispatch_detail_id' => $componentDispatchDetail->id,
                'component_sale_bundle_item_id' => $componentBundleItem->id,
                'component_line_group_key' => (string) $componentBundleItem->line_group_key,
                'component_bundle_id' => (int) $componentBundleItem->bundle_id,
                'component_quantity_per_bundle' => 2,
                'quantity_source' => 'sale_bundle_item',
                'commercial_value_source' => 'sale_bundle_item',
                'cash_return_amount' => 0,
                'planned_amount' => 120,
            ],
        ]);
        SaleReturnDetail::query()->create([
            'sale_return_id' => $replacementSaleReturn->id,
            'pos_return_line_id' => $replacementLine->id,
            'sale_detail_id' => $replacementSaleDetail->id,
            'dispatch_detail_id' => $replacementSourceDispatchDetail->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 300,
            'unit_price' => 300,
            'sub_total' => 300,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'bundle_group_key' => $replacementLine->bundle_group_key,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'serial_number_ids' => [$replacementReturnedSerial->id],
        ]);

        $cashLine->update(['sale_return_detail_id' => $cashParentDetail->id]);

        return [
            $posReturn,
            $cashSale,
            $componentSale,
            $replacementSale,
            $componentBundleItem,
            $replacementSaleDetail,
            $cashReturnedSerial,
            $replacementReturnedSerial,
            $replacementSerial,
        ];
    }

    protected function createPosReturn(array $overrides = []): PosReturn
    {
        return PosReturn::query()->create(array_merge([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-' . uniqid(),
            'receipt_number' => 'RCP-' . uniqid(),
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'reference' => 'PR-' . uniqid(),
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'total_amount' => 100,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ], $overrides));
    }

    protected function createSale(string $status): Sale
    {
        return Sale::query()->create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SO-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 2000,
            'paid_amount' => 2000,
            'due_amount' => 0,
            'status' => $status,
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);
    }

    protected function createSaleReturn(PosReturn $posReturn, Sale $sale, array $overrides = []): SaleReturn
    {
        return SaleReturn::query()->create(array_merge([
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'pos_return_id' => $posReturn->id,
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference,
            'return_type' => 'Cash Return',
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SR-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 600,
            'paid_amount' => 0,
            'due_amount' => 600,
            'status' => 'AWAITING RECEIVING',
            'approval_status' => 'APPROVED',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ], $overrides));
    }
}
