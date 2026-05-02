<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Product\Entities\ProductStock;
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