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

    public function test_can_execute_exchange_receive_flow_full()
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
             'serial_number_required' => true, // Serialized
             'product_order_tax' => 0,
             'product_tax_type' => 1,
             'category_id' => $category->id,
             // 'setting_id' => $setting->id, // If product has setting_id
             'setting_id' => $setting->id,
        ]);

        $location = \Modules\Setting\Entities\Location::create([
             'name' => 'Test Location',
             'setting_id' => $setting->id,
        ]);

        $purchaseReturn = PurchaseReturn::create([
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
            'approval_status' => 'Approved', // Pre-approve
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ]);

        // Create initial Goods (that are being returned)
        // Usually these come from details. For Exchange, we also have "goods" table for replacements.
        // Assuming the "planned" replacement is already there.
        $good = PurchaseReturnGood::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_value' => 1000,
            'sub_total' => 1000,
            'received_quantity' => 0,
        ]);

        // Create Settlement
        $settlement = PurchaseReturnSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'method' => 'Exchange',
            'status' => 'approved', // Pre-approve
            'submitted_by' => auth()->id(),
            'submitted_at' => now(),
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        // Execute Settlement (Start Executing)
        // Simulate 'execute' call or manual state change
        $this->post(route('purchase-return-settlements.execute', $settlement->id));
        $settlement->refresh();
        $this->assertEquals('EXECUTING', $settlement->status);

        // Dispatch Return (Simulate)
        // We need 'return_dispatched_at' to be set
        $purchaseReturn->update(['return_dispatched_at' => now(), 'status' => 'Return Dispatched']);

        // 2. Perform Receive
        // Initial Stock
        $initialStock = $product->fresh()->product_quantity;

        // Perform Receive Action
        $response = $this->post(route('purchase-return-settlements.receive', $settlement->id), [
            'items' => [
                [
                    'id' => $good->id,
                    'received_quantity' => 1,
                    'note' => 'Replacement received',
                    'serial_numbers' => ['SN-REPLACED-001']
                ]
            ]
        ]);

        $response->assertSessionHas('success');

        // 3. Verify Results
        // Stock should increase
        $this->assertEquals($initialStock + 1, $product->fresh()->product_quantity);

        // Good should be fully received
        $good->refresh();
        $this->assertEquals(1, $good->received_quantity);
        $this->assertNotNull($good->received_at);

        // Serial Number should exist and be active
        $this->assertDatabaseHas('product_serial_numbers', [
            'product_id' => $product->id,
            'serial_number' => 'SN-REPLACED-001',
            'status' => 'ACTIVE'
        ]);

        // Settlement should be completed
        $settlement->refresh();
        $this->assertEquals('completed', strtolower($settlement->status)); // Model stores uppercase?

        // Purchase Return should be Completed
        $purchaseReturn->refresh();
        $this->assertEquals('COMPLETED', $purchaseReturn->status);
    }
}
