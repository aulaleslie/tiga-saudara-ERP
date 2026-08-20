<?php

namespace Modules\Pos\Tests\Feature;

use App\Services\Reports\SaleHppAggregateService;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Spatie\Permission\Models\Permission;

/**
 * Section 8 release gate: end-to-end verification, through the real
 * PosReturnLifecycleService (not synthetic aggregate fixtures), that:
 *
 *   net HPP = original HPP - effective return reversal + effective replacement-dispatch HPP
 *
 * for both same-owner and cross-owner replacement paths, with no extra
 * revenue recognized and no duplicate HPP on idempotent retry.
 */
class POSReturnReplacementHppReleaseGateTest extends PosTransactionFeatureTestCase
{
    protected Setting $settingA;
    protected Location $locationA;
    protected Setting $settingB;
    protected Location $locationB;
    protected $actor;
    protected PosReturnLifecycleService $service;
    protected SaleHppAggregateService $aggregate;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('pos.access', 'web');
        Permission::findOrCreate('pos.returns.approve', 'web');

        $this->settingA = $this->createSetting('Release Gate Setting A');
        [, $this->locationA] = $this->createTerminalWithLocation($this->settingA);

        $this->settingB = $this->createSetting('Release Gate Setting B');
        [, $this->locationB] = $this->createTerminalWithLocation($this->settingB);

        $this->actor = $this->createUserForSetting($this->settingA, 'Release Gate Actor', [
            'pos.access',
            'pos.returns.approve',
        ]);

        $this->service = app(PosReturnLifecycleService::class);
        $this->aggregate = new SaleHppAggregateService();
    }

    public function test_same_owner_replacement_net_hpp_equals_original_minus_returned_plus_replacement(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        $product = $this->createStockedProduct($this->settingA, $this->locationA, [
            'product_code' => 'GATE-SAME-' . uniqid(),
            'sale_price' => 1000,
            'stock_qty' => 5,
            'serial_number_required' => true,
        ]);
        // createStockedProduct seeds average_purchase_price = 5000.

        $replacementSerial = $this->createSerialNumber($product, $this->locationA, 'SN-GATE-REPL-' . uniqid());
        $replacementSerial->update(['status' => ProductSerialNumber::STATUS_ACTIVE]);

        $returnedSerial = $this->createSerialNumber($product, $this->locationA, 'SN-GATE-RET-' . uniqid());
        $returnedSerial->update(['status' => ProductSerialNumber::STATUS_SOLD]);

        $sale = Sale::query()->create([
            'setting_id' => $this->settingA->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in',
            'reference' => 'SO-GATE-SAME-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'Paid',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        // Original snapshot HPP = 3000 (independent of current average 5000).
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
            'cost_unit_snapshot' => 3000,
            'cost_total_snapshot' => 3000,
            'cost_snapshot_source' => 'CURRENT_AVERAGE_PRICE',
            'cost_snapshot_at' => now(),
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
            'location_id' => $this->locationA->id,
            'tax_id' => null,
        ]);

        $posReturn = PosReturn::query()->create([
            'setting_id' => $this->settingA->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-GATE-SAME-' . uniqid(),
            'receipt_number' => 'RCP-GATE-SAME-' . uniqid(),
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'reference' => 'PR-GATE-SAME-' . uniqid(),
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'total_amount' => 1000,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);

        $saleReturn = SaleReturn::query()->create([
            'setting_id' => $this->settingA->id,
            'location_id' => $this->locationA->id,
            'pos_return_id' => $posReturn->id,
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference,
            'return_type' => 'Replacement',
            'customer_id' => null,
            'customer_name' => 'Walk-in',
            'reference' => 'SR-GATE-SAME-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        $line = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $sourceDispatchDetail->id,
            'source_setting_id' => $this->settingA->id,
            'source_location_id' => $this->locationA->id,
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
            'cost_origin' => SaleReturnDetail::COST_ORIGIN_SALE_DETAIL,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->locationA->id,
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
            // No execution_mode = same-owner path.
        ]);

        $this->service->executeApprovalFromPreview($posReturn->id);

        $totals = $this->aggregate->totals([$this->settingA->id], now()->subDay()->toDateString(), now()->addDay()->toDateString());

        // original 3000 - returned 3000 + replacement (current average 5000) = 5000
        $this->assertEquals(5000, $totals->hpp);

        // No new revenue: DPP is unaffected by the replacement dispatch.
        $this->assertEquals(1000, $totals->dpp);

        $replacementDispatchDetail = DispatchDetail::query()
            ->where('replacement_of_dispatch_detail_id', $sourceDispatchDetail->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertEquals(5000, (float) $replacementDispatchDetail->replacement_cost_total_snapshot);

        // Retry: idempotency-guarded, must not throw a duplicate-HPP state and
        // must not change the aggregate.
        try {
            $this->service->executeApprovalFromPreview($posReturn->id);
        } catch (\Throwable $e) {
            // Expected: status guard rejects re-execution of a completed return.
        }

        $totalsAfterRetry = $this->aggregate->totals([$this->settingA->id], now()->subDay()->toDateString(), now()->addDay()->toDateString());
        $this->assertEquals(5000, $totalsAfterRetry->hpp);
    }

    public function test_cross_owner_replacement_net_hpp_equals_original_minus_returned_plus_replacement(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        $product = $this->createStockedProduct($this->settingA, $this->locationA, [
            'product_code' => 'GATE-CROSS-' . uniqid(),
            'product_name' => 'Gate Cross-Owner',
            'sale_price' => 1500,
            'stock_qty' => 2,
            'serial_number_required' => true,
        ]);

        // Setting B has its own average purchase price for the same product.
        ProductStock::query()->create([
            'product_id' => $product->id,
            'location_id' => $this->locationB->id,
            'quantity' => 3,
            'quantity_tax' => 0,
            'quantity_non_tax' => 3,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'tax_id' => null,
        ]);
        \Modules\Product\Entities\ProductPrice::updateOrCreate(
            ['product_id' => $product->id, 'setting_id' => $this->settingB->id],
            ['sale_price' => 1500, 'average_purchase_price' => 4200]
        );

        $returnedSerial = $this->createSerialNumber($product, $this->locationA, 'SN-GATE-CROSS-RET-' . uniqid());
        $returnedSerial->update(['status' => ProductSerialNumber::STATUS_SOLD]);

        $replacementSerial = $this->createSerialNumber($product, $this->locationB, 'SN-GATE-CROSS-REPL-' . uniqid());
        $replacementSerial->update(['status' => ProductSerialNumber::STATUS_ACTIVE]);

        $customer = Customer::query()->create([
            'setting_id' => $this->settingB->id,
            'customer_name' => 'Cross-Owner Customer',
            'customer_email' => 'gate-cross@example.com',
            'customer_phone' => '0811111111',
        ]);

        $sale = Sale::query()->create([
            'setting_id' => $this->settingA->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in',
            'reference' => 'SO-GATE-CROSS-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 3000,
            'paid_amount' => 3000,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'Paid',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        // Original snapshot HPP = 900/unit (independent of Setting B's current average).
        $originalSaleDetail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 3000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 900,
            'cost_total_snapshot' => 1800,
            'cost_snapshot_source' => 'CURRENT_AVERAGE_PRICE',
            'cost_snapshot_at' => now(),
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
            'dispatched_quantity' => 2,
            'location_id' => $this->locationA->id,
            'tax_id' => null,
        ]);

        $posReturn = PosReturn::query()->create([
            'setting_id' => $this->settingA->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-GATE-CROSS-' . uniqid(),
            'receipt_number' => 'RCP-GATE-CROSS-' . uniqid(),
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'reference' => 'PR-GATE-CROSS-' . uniqid(),
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'total_amount' => 1500,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);

        $saleReturn = SaleReturn::query()->create([
            'setting_id' => $this->settingA->id,
            'location_id' => $this->locationA->id,
            'pos_return_id' => $posReturn->id,
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference,
            'return_type' => 'Replacement',
            'customer_id' => null,
            'customer_name' => 'Walk-in',
            'reference' => 'SR-GATE-CROSS-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1500,
            'paid_amount' => 0,
            'due_amount' => 1500,
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        $line = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $originalSaleDetail->id,
            'dispatch_detail_id' => $sourceDispatchDetail->id,
            'source_setting_id' => $this->settingA->id,
            'source_location_id' => $this->locationA->id,
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
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $product->id,
            'replacement_quantity' => 1,
        ]);

        SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $line->id,
            'sale_detail_id' => $originalSaleDetail->id,
            'dispatch_detail_id' => $sourceDispatchDetail->id,
            'cost_origin' => SaleReturnDetail::COST_ORIGIN_SALE_DETAIL,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->locationA->id,
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
            'execution_context' => [
                'row_type' => 'parent',
                'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                'execution_mode' => 'cross_owner_replacement',
                'replacement_serial_owner_setting_id' => $this->settingB->id,
                'replacement_serial_location_id' => $this->locationB->id,
                'original_sale_correction_quantity' => 1,
                'original_sale_correction_amount' => 1500,
                'generated_replacement_sale_effects' => [
                    'setting_id' => $this->settingB->id,
                    'setting_name' => 'Release Gate Setting B',
                    'location_id' => $this->locationB->id,
                    'location_name' => 'Location B',
                    'sale_reference' => 'generated_on_approval',
                    'customer_id' => $customer->id,
                    'customer_resolution_source' => 'existing',
                    'payment_amount' => 1500.0,
                    'dispatch_quantity' => 1.0,
                ],
            ],
        ]);

        $this->service->executeApprovalFromPreview($posReturn->id);

        // Original Sale (Setting A): 900*2 original - 900*1 returned (1 unit returned) = 900.
        $totalsA = $this->aggregate->totals([$this->settingA->id], now()->subDay()->toDateString(), now()->addDay()->toDateString());
        $this->assertEquals(900, $totalsA->hpp);

        // Replacement Sale (Setting B): its own fresh SaleDetails snapshot at
        // Setting B's current average (4200), NOT double-counted through dispatch.
        $totalsB = $this->aggregate->totals([$this->settingB->id], now()->subDay()->toDateString(), now()->addDay()->toDateString());
        $this->assertEquals(4200, $totalsB->hpp);

        // The original Sale's DPP is correctly reduced by the cross-owner
        // correction (1 of 2 units returned: 3000 -> 1500 remaining commercial
        // revenue). The replacement Sale's DPP is its own real commercial
        // payment amount — no new revenue is invented by the replacement leg.
        $this->assertEquals(1500, $totalsA->dpp);
        $this->assertEquals(1500, $totalsB->dpp);

        // Retry: idempotency-guarded.
        try {
            $this->service->executeApprovalFromPreview($posReturn->id);
        } catch (\Throwable $e) {
            // Expected: status guard rejects re-execution of a completed return.
        }

        $totalsAAfterRetry = $this->aggregate->totals([$this->settingA->id], now()->subDay()->toDateString(), now()->addDay()->toDateString());
        $totalsBAfterRetry = $this->aggregate->totals([$this->settingB->id], now()->subDay()->toDateString(), now()->addDay()->toDateString());
        $this->assertEquals(900, $totalsAAfterRetry->hpp);
        $this->assertEquals(4200, $totalsBAfterRetry->hpp);
    }
}
