<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Services\SalesCostSnapshotService;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Spatie\Permission\Models\Permission;

class POSReturnCostReversalTest extends PosTransactionFeatureTestCase
{
    protected $setting;
    protected $location;
    protected $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->createSetting('POS Return Cost Reversal Test');
        [, $this->location] = $this->createTerminalWithLocation($this->setting);

        Permission::findOrCreate('pos.access', 'web');

        $this->actor = $this->createUserForSetting($this->setting, 'POS Return Cost Reversal Actor', [
            'pos.access',
        ]);
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

    /**
     * Whole-bundle cash return: a parent Sale (with cost snapshot) plus one bundle
     * component (with its own independent cost snapshot), both returned for cash.
     * Only a fraction of the component quantity is returned (partial bundle cash
     * reversal).
     *
     * @return array{0: PosReturn, 1: SaleReturn, 2: SaleDetails, 3: SaleBundleItem}
     */
    protected function createPendingApprovalPartialBundleCashReturn(): array
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'BNDL-PARENT-' . uniqid(),
            'sale_price' => 600,
            'stock_qty' => 10,
        ]);
        $componentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'BNDL-COMP-' . uniqid(),
            'sale_price' => 0,
            'stock_qty' => 10,
        ]);

        $sale = $this->createSale('DISPATCHED');
        $sale->update(['total_amount' => 600, 'paid_amount' => 600, 'due_amount' => 0]);

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
            'cost_unit_snapshot' => 250,
            'cost_total_snapshot' => 250,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE,
            'cost_snapshot_at' => now(),
        ]);

        $bundleItem = SaleBundleItem::create([
            'sale_detail_id' => $saleDetail->id,
            'sale_id' => $sale->id,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $componentProduct->id,
            'name' => $componentProduct->product_name,
            'price' => 0,
            'quantity' => 4,
            'sub_total' => 0,
            'cost_unit_snapshot' => 60,
            'cost_total_snapshot' => 240,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE,
            'cost_snapshot_setting_id' => $this->setting->id,
            'cost_snapshot_setting_is_pkp' => false,
            'cost_snapshot_at' => now(),
        ]);

        $posReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'total_amount' => 120,
        ]);

        $saleReturn = $this->createSaleReturn($posReturn, $sale, [
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'return_type' => 'Cash Return',
            'total_amount' => 120,
            'paid_amount' => 0,
            'due_amount' => 120,
        ]);

        // Only 2 of the 4 component units are returned: a PARTIAL bundle cash reversal.
        $componentLine = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'bundle_parent_sale_detail_id' => $saleDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'tax_id' => null,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'quantity' => 2,
            'unit_price' => 0,
            'line_total' => 120,
            'expected_cash_amount' => 120,
            'serial_number_ids' => null,
            'bundle_group_key' => 'bundle-1',
            'bundle_quantity' => null,
            'component_quantity_per_bundle' => null,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => null,
            'replacement_quantity' => null,
        ]);

        $componentDetail = SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $componentLine->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'component_sale_bundle_item_id' => $bundleItem->id,
            'cost_origin' => SaleReturnDetail::COST_ORIGIN_BUNDLE_ITEM,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'quantity' => 2,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 120,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'component',
                'component_sale_bundle_item_id' => $bundleItem->id,
                'commercial_value_source' => 'sale_bundle_item',
                'planned_amount' => 120,
            ],
        ]);

        return [$posReturn, $saleReturn, $saleDetail, $bundleItem, $componentDetail];
    }

    public function test_partial_bundle_cash_reversal_copies_original_component_snapshot_times_returned_quantity(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, , , , $componentDetail] = $this->createPendingApprovalPartialBundleCashReturn();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $componentDetail = $componentDetail->fresh();

        $this->assertNotNull($componentDetail->cost_effective_at);
        $this->assertSame(SaleReturnDetail::COST_ORIGIN_BUNDLE_ITEM, $componentDetail->cost_origin);
        $this->assertEquals(60, (float) $componentDetail->cost_unit_snapshot);
        $this->assertEquals(2, (float) $componentDetail->cost_quantity);
        $this->assertEquals(120, (float) $componentDetail->cost_total_snapshot); // 60 * 2, not 60 * 4
        $this->assertEquals($this->setting->id, $componentDetail->cost_snapshot_setting_id);
    }

    public function test_reversal_uses_original_snapshot_and_ignores_changed_current_average(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, , , $bundleItem, $componentDetail] = $this->createPendingApprovalPartialBundleCashReturn();

        // Current average purchase price changes after the original sale but before the return.
        ProductPrice::query()->updateOrCreate(
            ['product_id' => $bundleItem->product_id, 'setting_id' => $this->setting->id],
            ['sale_price' => 0, 'average_purchase_price' => 999]
        );

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $componentDetail = $componentDetail->fresh();

        // Reversal must copy the ORIGINAL 60, never resolve the new current average of 999.
        $this->assertEquals(60, (float) $componentDetail->cost_unit_snapshot);
        $this->assertEquals(120, (float) $componentDetail->cost_total_snapshot);
    }

    public function test_rejected_return_never_receives_a_cost_effective_reversal(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, , , , $componentDetail] = $this->createPendingApprovalPartialBundleCashReturn();

        app(PosReturnLifecycleService::class)->reject($posReturn->id, 'Test rejection');

        $componentDetail = $componentDetail->fresh();

        $this->assertNull($componentDetail->cost_effective_at);
        $this->assertNull($componentDetail->cost_total_snapshot);
    }

    public function test_idempotent_final_approval_retry_does_not_change_persisted_cost_reversal(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, , , , $componentDetail] = $this->createPendingApprovalPartialBundleCashReturn();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $firstEffectiveAt = $componentDetail->fresh()->cost_effective_at;
        $firstTotal = (float) $componentDetail->fresh()->cost_total_snapshot;

        // A second call after completion must be rejected by the status guard,
        // never re-applying (or duplicating) the cost reversal.
        $this->expectException(\Throwable::class);
        try {
            app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);
        } finally {
            $componentDetail = $componentDetail->fresh();
            $this->assertEquals($firstEffectiveAt, $componentDetail->cost_effective_at);
            $this->assertEquals($firstTotal, (float) $componentDetail->cost_total_snapshot);
        }
    }

    public function test_standard_versus_pos_return_parity_parent_only_reversal_matches_pos_parent_reversal(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PARITY-' . uniqid(),
            'sale_price' => 600,
            'stock_qty' => 10,
        ]);

        $sale = $this->createSale('DISPATCHED');
        $sale->update(['total_amount' => 600, 'paid_amount' => 600, 'due_amount' => 0]);

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
            'cost_unit_snapshot' => 250,
            'cost_total_snapshot' => 250,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE,
            'cost_snapshot_at' => now(),
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

        $detail = SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $line->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'cost_origin' => SaleReturnDetail::COST_ORIGIN_SALE_DETAIL,
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

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $detail = $detail->fresh();

        $this->assertNotNull($detail->cost_effective_at);
        $this->assertSame(SaleReturnDetail::COST_ORIGIN_SALE_DETAIL, $detail->cost_origin);
        // Same original-unit-cost-times-quantity rule as the bundle-component path.
        $this->assertEquals(250, (float) $detail->cost_unit_snapshot);
        $this->assertEquals(1, (float) $detail->cost_quantity);
        $this->assertEquals(250, (float) $detail->cost_total_snapshot);
    }

    /**
     * Same-owner replacement dispatch: a new outgoing unit is dispatched against the
     * existing commercial Sale. Its outgoing HPP must be snapshotted independently
     * (from the current owner-aware average), never inherited from the returned item.
     */
    public function test_same_owner_replacement_dispatch_persists_independent_outgoing_cost_snapshot(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'REPL-SAME-' . uniqid(),
            'sale_price' => 1000,
            'stock_qty' => 5,
            'serial_number_required' => true,
        ]);
        // createStockedProduct seeds average_purchase_price = 5000 by default.

        $replacementSerial = $this->createSerialNumber($product, $this->location, 'SN-REPL-' . uniqid());
        $replacementSerial->update(['status' => ProductSerialNumber::STATUS_ACTIVE]);

        $returnedSerial = $this->createSerialNumber($product, $this->location, 'SN-RET-' . uniqid());
        $returnedSerial->update(['status' => ProductSerialNumber::STATUS_SOLD]);

        $sale = $this->createSale('DISPATCHED');
        $sale->update(['total_amount' => 1000, 'paid_amount' => 1000, 'due_amount' => 0]);

        $saleDetail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
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
        ]);

        $posReturn = $this->createPosReturn([
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'total_amount' => 1000,
        ]);

        $saleReturn = $this->createSaleReturn($posReturn, $sale, [
            'return_type' => 'Replacement',
            'total_amount' => 1000,
            'due_amount' => 1000,
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
            'unit_price' => 1000,
            'line_total' => 1000,
            'serial_number_ids' => [$returnedSerial->id],
            'returned_serial_id' => $returnedSerial->id,
            'replacement_serial_id' => $replacementSerial->id,
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
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'serial_number_ids' => [$returnedSerial->id],
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $replacementDispatchDetail = DispatchDetail::query()
            ->where('replacement_of_dispatch_detail_id', $sourceDispatchDetail->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertEquals(5000, (float) $replacementDispatchDetail->replacement_cost_unit_snapshot);
        $this->assertEquals(5000, (float) $replacementDispatchDetail->replacement_cost_total_snapshot); // qty 1
        $this->assertSame(SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE, $replacementDispatchDetail->replacement_cost_snapshot_source);
        $this->assertEquals($this->setting->id, $replacementDispatchDetail->replacement_cost_snapshot_setting_id);
        $this->assertNotNull($replacementDispatchDetail->replacement_cost_snapshot_at);
    }
}
