<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Spatie\Permission\Models\Permission;

class POSReturnReplacementDispatchWorkflowTest extends PosTransactionFeatureTestCase
{
    protected PosReturnLifecycleService $service;

    protected $setting;

    protected $location;

    protected $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PosReturnLifecycleService::class);
        $this->setting = $this->createSetting('POS Return Dispatch Workflow Test');
        [, $this->location] = $this->createTerminalWithLocation($this->setting);

        Permission::findOrCreate('pos.access', 'web');

        $this->actor = $this->createUserForSetting($this->setting, 'POS Return Dispatch Actor', [
            'pos.access',
        ]);
    }

    /** @test */
    public function it_dispatches_replacement_when_sku_quantity_and_source_match()
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $saleReturn, $detail] = $this->createAwaitingDispatchReturn();

        $this->service->dispatchReplacement($posReturn->id);

        $posReturn->refresh();
        $saleReturn->refresh();
        $detail->refresh();

        $this->assertEquals(PosReturn::STATUS_COMPLETED, $posReturn->status);
        $this->assertEquals('COMPLETED', $saleReturn->status);
        $this->assertTrue(Dispatch::query()->where('sale_id', $saleReturn->sale_id)->exists());
        $this->assertSame(8, (int) $detail->product->fresh()->product_quantity);
    }

    /** @test */
    public function it_allows_replacement_dispatch_when_the_explicit_replacement_product_is_not_persisted_but_the_original_sku_matches()
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $saleReturn, $detail] = $this->createAwaitingDispatchReturn([
            'replacement_product_id' => null,
        ]);

        $this->service->dispatchReplacement($posReturn->id);

        $posReturn->refresh();
        $saleReturn->refresh();
        $detail->refresh();

        $this->assertEquals(PosReturn::STATUS_COMPLETED, $posReturn->status);
        $this->assertEquals('COMPLETED', $saleReturn->status);
        $this->assertTrue(Dispatch::query()->where('sale_id', $saleReturn->sale_id)->exists());
        $this->assertSame(8, (int) $detail->product->fresh()->product_quantity);
    }

    /** @test */
    public function it_blocks_replacement_dispatch_for_cash_return_returns()
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn] = $this->createAwaitingDispatchReturn([
            'return_option' => PosReturn::OPTION_CASH_RETURN,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Hanya retur dengan opsi ganti produk yang dapat diproses sebagai pengiriman pengganti.');

        $this->service->dispatchReplacement($posReturn->id);
    }

    /** @test */
    public function it_blocks_replacement_dispatch_when_replacement_product_does_not_match_the_original_sku()
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn] = $this->createAwaitingDispatchReturn([
            'replacement_product_mismatch' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Produk pengganti harus menggunakan SKU yang sama dengan barang yang diretur.');

        $this->service->dispatchReplacement($posReturn->id);
    }

    /** @test */
    public function it_blocks_replacement_dispatch_when_replacement_quantity_does_not_match_received_quantity()
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn] = $this->createAwaitingDispatchReturn([
            'replacement_quantity' => 1,
            'detail_quantity' => 2,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Jumlah barang pengganti harus sama dengan jumlah barang retur yang sudah diterima.');

        $this->service->dispatchReplacement($posReturn->id);
    }

    /** @test */
    public function it_blocks_replacement_dispatch_when_stock_is_not_available_at_the_original_source_location()
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn] = $this->createAwaitingDispatchReturn([
            'available_stock' => 1,
            'detail_quantity' => 2,
            'replacement_quantity' => 2,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stok produk pengganti tidak mencukupi di lokasi sumber asli retur.');

        $this->service->dispatchReplacement($posReturn->id);
    }

    /**
     * @return array{0: PosReturn, 1: SaleReturn, 2: SaleReturnDetail}
     */
    protected function createAwaitingDispatchReturn(array $overrides = []): array
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-' . uniqid(),
            'sale_price' => 1000,
            'stock_qty' => $overrides['available_stock'] ?? 10,
        ]);

        $replacementProductId = array_key_exists('replacement_product_id', $overrides)
            ? $overrides['replacement_product_id']
            : $product->id;

        if (! empty($overrides['replacement_product_mismatch'])) {
            $replacementProductId = $this->createStockedProduct($this->setting, $this->location, [
                'product_code' => 'ALT-' . uniqid(),
                'sale_price' => 1000,
                'stock_qty' => 10,
            ])->id;
        }

        $sale = Sale::query()->create([
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
            'status' => 'COMPLETED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        $detailQuantity = $overrides['detail_quantity'] ?? 2;

        $posReturn = PosReturn::query()->create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-' . uniqid(),
            'receipt_number' => 'RCP-' . uniqid(),
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'reference' => 'PR-' . uniqid(),
            'return_option' => $overrides['return_option'] ?? PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_AWAITING_DISPATCH,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'total_amount' => 2000,
            'received_by' => $this->actor->id,
            'received_at' => now(),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);

        $saleReturn = SaleReturn::query()->create([
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'pos_return_id' => $posReturn->id,
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference,
            'return_type' => 'Replacement',
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SR-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
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
            'quantity' => $detailQuantity,
            'unit_price' => 1000,
            'line_total' => $detailQuantity * 1000,
            'serial_number_ids' => null,
            'bundle_group_key' => null,
            'bundle_parent_sale_detail_id' => null,
            'bundle_quantity' => null,
            'component_quantity_per_bundle' => null,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $replacementProductId,
            'replacement_quantity' => $overrides['replacement_quantity'] ?? $detailQuantity,
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
            'quantity' => $detailQuantity,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => $detailQuantity * 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        return [$posReturn, $saleReturn, $detail];
    }
}