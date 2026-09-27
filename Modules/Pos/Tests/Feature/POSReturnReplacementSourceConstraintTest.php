<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Spatie\Permission\Models\Permission;

class POSReturnReplacementSourceConstraintTest extends PosTransactionFeatureTestCase
{
    protected PosReturnLifecycleService $service;

    protected $setting;

    protected $otherSetting;

    protected $location;

    protected $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PosReturnLifecycleService::class);
        $this->setting = $this->createSetting('POS Return Replacement Source Test');
        $this->otherSetting = $this->createSetting('POS Return Replacement Source Test 2');
        [, $this->location] = $this->createTerminalWithLocation($this->setting);

        Permission::findOrCreate('pos.access', 'web');

        $this->actor = $this->createUserForSetting($this->setting, 'POS Return Replacement Source Actor', [
            'pos.access',
        ]);
    }

    /** @test */
    public function it_blocks_replacement_dispatch_when_the_linked_sale_return_owner_setting_differs_from_the_original_source(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-SRC-' . uniqid(),
            'sale_price' => 500,
            'stock_qty' => 10,
        ]);
        $sale = Sale::query()->create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SO-SRC-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'status' => 'COMPLETED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);
        $posReturn = PosReturn::query()->create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-SRC-' . uniqid(),
            'receipt_number' => 'RCP-SRC-' . uniqid(),
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'reference' => 'PR-SRC-' . uniqid(),
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_AWAITING_DISPATCH,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'total_amount' => 1000,
            'received_by' => $this->actor->id,
            'received_at' => now(),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        $saleReturn = SaleReturn::query()->create([
            'setting_id' => $this->otherSetting->id,
            'location_id' => $this->location->id,
            'pos_return_id' => $posReturn->id,
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference,
            'return_type' => 'Replacement',
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SR-SRC-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'AWAITING DISPATCH',
            'approval_status' => 'APPROVED',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
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
            'unit_price' => 500,
            'line_total' => 1000,
            'serial_number_ids' => null,
            'bundle_group_key' => null,
            'bundle_parent_sale_detail_id' => null,
            'bundle_quantity' => null,
            'component_quantity_per_bundle' => null,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $product->id,
            'replacement_quantity' => 2,
        ]);
        SaleReturnDetail::query()->create([
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
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Pengiriman pengganti harus berasal dari owner atau setting sumber asli retur.');

        $this->service->dispatchReplacement($posReturn->id);
    }
}