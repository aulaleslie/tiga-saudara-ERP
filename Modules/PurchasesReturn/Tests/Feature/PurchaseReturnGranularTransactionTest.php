<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Tests\TestCase;

class PurchaseReturnGranularTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected $location;
    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting = \Modules\Setting\Entities\Setting::first() ?? \Modules\Setting\Entities\Setting::create([
            'company_name' => 'Test',
            'company_email' => 'test@test.com',
            'company_phone' => '123',
            'company_address' => 'Addr',
            'notification_email' => 'test@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'footer',
        ]);
        $this->location = \Modules\Setting\Entities\Location::first() ?? \Modules\Setting\Entities\Location::create([
            'name' => 'Main',
            'setting_id' => $this->setting->id
        ]);
        session(['setting_id' => $this->setting->id]);
        $this->actingAs(\App\Models\User::first() ?? \App\Models\User::factory()->create());
        \Illuminate\Support\Facades\Gate::before(fn () => true);
    }

    protected function createSupplier()
    {
        return \Modules\People\Entities\Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@supplier.com',
            'supplier_phone' => '123',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);
    }

    protected function createProduct($name, $code)
    {
        return Product::create([
            'product_name' => $name,
            'product_code' => $code,
            'product_barcode_symbology' => 'CODE128',
            'product_quantity' => 10,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pc',
            'unit_id' => 1,
            'setting_id' => $this->setting->id,
        ]);
    }

    /** @test */
    public function it_deducts_with_priority_and_creates_granular_transactions()
    {
        // 1. Setup Product with mixed stock
        $product = $this->createProduct('Mixed Stock Product', 'P01');

        $stock = ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_non_tax' => 5,
            'quantity_tax' => 5,
            'broken_quantity' => 5,
            'broken_quantity_non_tax' => 3,
            'broken_quantity_tax' => 2,
        ]);

        // 2. Create Purchase Return for 7 items
        $supplier = $this->createSupplier();
        $pr = PurchaseReturn::create([
            'reference' => 'PR-TEST-01',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'date' => now(),
            'status' => 'APPROVED', 
            'approval_status' => 'APPROVED',
            'return_dispatch_status' => 'pending_approval',
            'setting_id' => $this->setting->id,
            'total_amount' => 7000,
            'paid_amount' => 0,
            'due_amount' => 7000,
            'payment_status' => 'DUE',
            'payment_method' => 'CASH',
        ]);

        PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'quantity' => 7,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 700,
            'product_tax_amount' => 10,
            'product_discount_amount' => 0,
        ]);

        // 3. Approve Dispatch
        $this->post(route('purchase-returns.dispatch-approve', $pr->id));

        // 4. Verify Stock
        $stock->refresh();
        $this->assertEquals(0, $stock->broken_quantity_non_tax);
        $this->assertEquals(0, $stock->broken_quantity_tax);
        $this->assertEquals(0, $stock->broken_quantity);
        $this->assertEquals(3, $stock->quantity_non_tax);
        $this->assertEquals(5, $stock->quantity_tax);
        $this->assertEquals(8, $stock->quantity);

        // 5. Verify Transactions
        $this->assertDatabaseHas('transactions', [
            'product_id' => $product->id,
            'type' => 'PURCHASE_RETURN_BROKEN_NON_TAX',
            'quantity' => -3,
            'broken_quantity_non_tax' => -3,
        ]);

        $this->assertDatabaseHas('transactions', [
            'product_id' => $product->id,
            'type' => 'PURCHASE_RETURN_BROKEN_TAX',
            'quantity' => -2,
            'broken_quantity_tax' => -2,
        ]);

        $this->assertDatabaseHas('transactions', [
            'product_id' => $product->id,
            'type' => 'PURCHASE_RETURN_GOOD_NON_TAX',
            'quantity' => -2,
            'quantity_non_tax' => -2,
        ]);
    }

    /** @test */
    public function it_preserves_tax_status_in_break_stock_helper_manual()
    {
        $product = $this->createProduct('Manual Tax Product', 'P03');
        $sourceId = $this->location->id;
        $targetId = \Modules\Setting\Entities\Location::create([
            'name' => 'Target Manual',
            'setting_id' => $this->setting->id,
        ])->id;

        $sourceStock = ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $sourceId,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Access the protected method via reflection
        $controller = new \Modules\PurchasesReturn\Http\Controllers\PurchasesReturnSettlementController();
        $method = new \ReflectionMethod($controller, 'breakStock');
        $method->setAccessible(true);

        $method->invoke($controller, $product->id, $sourceId, $targetId, 5, false, false, $this->setting->id);

        $targetStock = ProductStock::where('product_id', $product->id)
            ->where('location_id', $targetId)
            ->first();

        // Should be broken_quantity_tax = 5
        $this->assertEquals(5, $targetStock->broken_quantity_tax);
        $this->assertEquals(0, $targetStock->broken_quantity_non_tax);
        $this->assertEquals(5, $targetStock->broken_quantity);
    }
}
