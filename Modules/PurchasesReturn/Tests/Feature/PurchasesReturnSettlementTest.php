<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnSettlement;
use Modules\PurchasesReturn\Entities\PurchaseReturnGood;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use App\Models\User;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Location;

class PurchasesReturnSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create Permissions
        $permissions = [
            'purchaseReturnSettlements.submit',
            'purchaseReturnSettlements.approve',
            'purchaseReturnSettlements.reject',
            'purchaseReturnSettlements.execute',
            'purchaseReturnSettlements.dispatch',
            'purchaseReturnSettlements.receive',
        ];

        foreach ($permissions as $permission) {
             \Spatie\Permission\Models\Permission::findOrCreate($permission);
        }

        $user = User::factory()->create(['is_active' => 1]);
        $user->givePermissionTo($permissions);

        $this->actingAs($user);
    }

    public function test_can_execute_settlement_with_cash_method()
    {
        // 1. Setup Data
        $setting = \Modules\Setting\Entities\Setting::create([
             'company_name' => 'Test Company',
             'company_email' => 'test@company.com',
             'company_phone' => '123456',
             'notification_email' => 'notify@company.com',
             'default_currency_id' => 1,
             'default_currency_position' => 'prefix',
             'footer_text' => 'Footer',
             'company_address' => 'Address',
        ]);
        
        $supplier = Supplier::create([
            'supplier_name' => 'Supplier Test', 
            'supplier_phone' => '123', 
            'supplier_email' => 'test@supplier.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $setting->id,
        ]);
        
        $category = \Modules\Product\Entities\Category::create([
            'category_code' => 'TEST_CAT', 
            'category_name' => 'Test Category',
            'created_by' => auth()->id(),
            'setting_id' => $setting->id,
        ]);

        $product = Product::create([
             'product_name' => 'Test Product',
             'product_code' => 'TEST001',
             'product_cost' => 1000,
             'product_price' => 2000,
             'product_quantity' => 10,
             'product_unit' => 'pcs',
             'product_stock_alert' => 0,
             'serial_number_required' => false,
             'product_order_tax' => 0,
             'product_tax_type' => 1,
             'category_id' => $category->id,
             'setting_id' => $setting->id,
        ]);

        $location = \Modules\Setting\Entities\Location::create([
             'name' => 'Test Location',
             'setting_id' => $setting->id,
        ]);

        $purchaseReturn = \Modules\PurchasesReturn\Entities\PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-TEST',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'setting_id' => $setting->id,
            'location_id' => $location->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => '',
            'approval_status' => 'Approved',
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ]);

        // Create detail for the return
        $detail = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $location->id,
        ]);

        // Create Settlement with 'mixed' method (granular)
        $settlement = PurchaseReturnSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'method' => 'mixed',
            'status' => 'approved',
            'submitted_by' => auth()->id(),
            'submitted_at' => now(),
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        // Create granular item settlement with CASH method
        \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'product_serial_number_id' => null,
            'method' => 'CASH',
            'nominal' => 0, // Will use sub_total
            'target_purchase_id' => null,
        ]);

        // Execute Settlement
        $this->post(route('purchase-return-settlements.execute', $settlement->id));
        $settlement->refresh();
        
        // Assert settlement is completed
        $this->assertEquals('completed', strtolower($settlement->status));

        // Assert payment record was created
        $this->assertDatabaseHas('purchase_return_payments', [
            'purchase_return_id' => $purchaseReturn->id,
            'amount' => 1000,
            'payment_method' => 'CASH',
        ]);

        // Purchase Return should be Completed
        $purchaseReturn->refresh();
        $this->assertEquals('COMPLETED', $purchaseReturn->status);
        $this->assertEquals('PAID', $purchaseReturn->payment_status);
    }

    public function test_can_execute_settlement_with_credit_method()
    {
        // 1. Setup Data
        $setting = \Modules\Setting\Entities\Setting::create([
             'company_name' => 'Test Company',
             'company_email' => 'test@company.com',
             'company_phone' => '123456',
             'notification_email' => 'notify@company.com',
             'default_currency_id' => 1,
             'default_currency_position' => 'prefix',
             'footer_text' => 'Footer',
             'company_address' => 'Address',
        ]);
        
        $supplier = Supplier::create([
            'supplier_name' => 'Supplier Test', 
            'supplier_phone' => '123', 
            'supplier_email' => 'test@supplier.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $setting->id,
        ]);
        
        $category = \Modules\Product\Entities\Category::create([
            'category_code' => 'TEST_CAT', 
            'category_name' => 'Test Category',
            'created_by' => auth()->id(),
            'setting_id' => $setting->id,
        ]);

        $product = Product::create([
             'product_name' => 'Test Product',
             'product_code' => 'TEST001',
             'product_cost' => 1000,
             'product_price' => 2000,
             'product_quantity' => 10,
             'product_unit' => 'pcs',
             'product_stock_alert' => 0,
             'serial_number_required' => false,
             'product_order_tax' => 0,
             'product_tax_type' => 1,
             'category_id' => $category->id,
             'setting_id' => $setting->id,
        ]);

        $location = \Modules\Setting\Entities\Location::create([
             'name' => 'Test Location',
             'setting_id' => $setting->id,
        ]);

        $purchaseReturn = \Modules\PurchasesReturn\Entities\PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-TEST-CREDIT',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'setting_id' => $setting->id,
            'location_id' => $location->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => '',
            'approval_status' => 'Approved',
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ]);

        // Create detail for the return
        $detail = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $location->id,
        ]);

        // Create Settlement
        $settlement = PurchaseReturnSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'method' => 'mixed',
            'status' => 'approved',
            'submitted_by' => auth()->id(),
            'submitted_at' => now(),
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        // Create granular item settlement with CREDIT method
        \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'product_serial_number_id' => null,
            'method' => 'CREDIT',
            'nominal' => 0,
            'target_purchase_id' => null,
        ]);

        // Execute Settlement
        $this->post(route('purchase-return-settlements.execute', $settlement->id));
        $settlement->refresh();
        
        // Assert settlement is completed
        $this->assertEquals('completed', strtolower($settlement->status));

        // Assert supplier credit was created
        $this->assertDatabaseHas('supplier_credits', [
            'supplier_id' => $supplier->id,
            'amount' => 2000,
            'remaining_amount' => 2000,
            'status' => 'OPEN',
        ]);

        // Purchase Return should be Completed
        $purchaseReturn->refresh();
        $this->assertEquals('COMPLETED', $purchaseReturn->status);
    }
}

