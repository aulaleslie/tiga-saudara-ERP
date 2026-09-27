<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Spatie\Permission\Models\Permission;

class POSReturnSettlementWorkflowTest extends PosTransactionFeatureTestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected $service;
    protected $setting;
    protected $location;
    protected $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PosReturnLifecycleService::class);
        $this->setting = $this->createSetting('Settlement Test Store');
        [$terminal, $location] = $this->createTerminalWithLocation($this->setting);
        $this->location = $location;
        $this->cashier = $this->createUserForSetting($this->setting, 'Cashier', ['pos.access']);
        
        Permission::findOrCreate('pos.access', 'web');
    }

    /** @test */
    public function it_can_settle_a_cash_return()
    {
        $this->actingAsInSetting($this->cashier, $this->setting);

        // 1. Create a POS return that is AWAITING SETTLEMENT
        $posReturn = $this->createAwaitingSettlementReturn(PosReturn::OPTION_CASH_RETURN);

        // 2. Settle it
        $this->service->settlePaymentReturn($posReturn->id);

        // 3. Assert status
        $posReturn->refresh();
        $this->assertEquals(PosReturn::STATUS_COMPLETED, $posReturn->status);

        // Check linked Sales Returns
        foreach ($posReturn->saleReturns as $saleReturn) {
            $this->assertEquals('COMPLETED', $saleReturn->status);
            $this->assertEquals('PAID', $saleReturn->payment_status);
            $this->assertEquals($saleReturn->total_amount, $saleReturn->paid_amount);
            $this->assertNotNull($saleReturn->settled_at);
            
            // Check Payment Record
            $this->assertTrue(SaleReturnPayment::where('sale_return_id', $saleReturn->id)->exists());
        }
    }

    /** @test */
    public function it_can_settle_a_replacement_return()
    {
        $this->actingAsInSetting($this->cashier, $this->setting);

        // 1. Create a POS return that is AWAITING DISPATCH
        $posReturn = $this->createAwaitingDispatchReturn(PosReturn::OPTION_PRODUCT_REPLACEMENT);

        // 2. Settle it (Dispatch Replacement)
        $this->service->dispatchReplacement($posReturn->id);

        // 3. Assert status
        $posReturn->refresh();
        $this->assertEquals(PosReturn::STATUS_COMPLETED, $posReturn->status);

        // Check linked Sales Returns
        foreach ($posReturn->saleReturns as $saleReturn) {
            $this->assertEquals('COMPLETED', $saleReturn->status);
            $this->assertNotNull($saleReturn->settled_at);
            
            // Check Dispatch Record
            $this->assertTrue(Dispatch::where('sale_id', $saleReturn->sale_id)->exists());
            
            // Check Stock Adjustment (Decrement)
            // Initial stock was 10, decrement by 1 => 9
            $saleReturn->load('saleReturnDetails.product');
            foreach ($saleReturn->saleReturnDetails as $detail) {
                $this->assertEquals(9, $detail->product->product_quantity);
            }
        }
    }

    /** @test */
    public function it_blocks_cash_settlement_for_product_replacement_returns()
    {
        $this->actingAsInSetting($this->cashier, $this->setting);

        $posReturn = $this->createAwaitingDispatchReturn(PosReturn::OPTION_PRODUCT_REPLACEMENT);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Hanya retur dengan opsi kembali uang yang dapat diproses sebagai pengembalian tunai.');

        $this->service->settlePaymentReturn($posReturn->id);
    }

    /** @test */
    public function it_blocks_replacement_dispatch_for_cash_return_returns()
    {
        $this->actingAsInSetting($this->cashier, $this->setting);

        $posReturn = $this->createAwaitingSettlementReturn(PosReturn::OPTION_CASH_RETURN);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Hanya retur dengan opsi ganti produk yang dapat diproses sebagai pengiriman pengganti.');

        $this->service->dispatchReplacement($posReturn->id);
    }

    protected function createAwaitingSettlementReturn(string $option): PosReturn
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-' . uniqid(),
            'sale_price' => 1000,
            'stock_qty' => 10,
        ]);
        
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SO-' . uniqid(),
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

        $saleDetail = SaleDetails::create([
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

        $posReturn = PosReturn::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-DUMMY',
            'receipt_number' => 'RCP-DUMMY',
            'source_snapshot' => [],
            'source_snapshot_hash' => 'dummy',
            'reference' => 'PR-' . uniqid(),
            'return_option' => $option,
            'status' => PosReturn::STATUS_AWAITING_SETTLEMENT,
            'approval_status' => 'approved',
            'total_amount' => 1000,
            'created_by' => $this->cashier->id,
        ]);

        \Illuminate\Support\Facades\DB::table('sale_returns')->insert([
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
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'AWAITING SETTLEMENT',
            'approval_status' => 'approved',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $posReturn;
    }

    protected function createAwaitingDispatchReturn(string $option): PosReturn
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-' . uniqid(),
            'sale_price' => 1000,
            'stock_qty' => 10,
        ]);
        
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SO-' . uniqid(),
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

        $saleDetail = SaleDetails::create([
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

        $posReturn = PosReturn::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-DUMMY',
            'receipt_number' => 'RCP-DUMMY',
            'source_snapshot' => [],
            'source_snapshot_hash' => 'dummy',
            'reference' => 'PR-' . uniqid(),
            'return_option' => $option,
            'status' => PosReturn::STATUS_AWAITING_DISPATCH,
            'approval_status' => 'approved',
            'total_amount' => 1000,
            'created_by' => $this->cashier->id,
        ]);

        \Illuminate\Support\Facades\DB::table('sale_returns')->insert([
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
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'AWAITING DISPATCH',
            'approval_status' => 'approved',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleReturn = SaleReturn::where('pos_return_id', $posReturn->id)->first();

        $line = PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => null,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'tax_id' => null,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 1000,
            'line_total' => 1000,
            'serial_number_ids' => null,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'replacement_product_id' => $product->id,
            'replacement_quantity' => 1,
        ]);

        SaleReturnDetail::create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $line->id,
            'sale_detail_id' => $saleDetail->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        return $posReturn;
    }
}
