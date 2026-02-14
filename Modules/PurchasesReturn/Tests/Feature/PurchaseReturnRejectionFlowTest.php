<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use App\Models\User;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Category;

class PurchaseReturnRejectionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'purchaseReturns.access',
            'purchaseReturns.create',
            'purchaseReturns.edit',
            'purchaseReturns.show',
            'purchaseReturns.delete',
            'purchaseReturns.approval',
        ];

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission);
        }

        $this->user = User::factory()->create(['is_active' => 1]);
        $this->user->givePermissionTo($permissions);
        $this->actingAs($this->user);
        
        $this->setupBaseData();
    }

    protected function setupBaseData()
    {
        $this->setting = Setting::create([
             'company_name' => 'Test Company',
             'company_email' => 'test@company.com',
             'company_phone' => '123456',
             'notification_email' => 'notify@company.com',
             'default_currency_id' => 1,
             'default_currency_position' => 'prefix',
             'footer_text' => 'Footer',
             'company_address' => 'Address',
        ]);
        
        $this->supplier = Supplier::create([
            'supplier_name' => 'Supplier Test', 
            'supplier_phone' => '123', 
            'supplier_email' => 'test@supplier.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->location = Location::create([
             'name' => 'Test Location',
             'setting_id' => $this->setting->id,
        ]);
        
        $this->category = Category::create([
            'category_code' => 'TEST_CAT', 
            'category_name' => 'Test Category',
            'created_by' => $this->user->id,
            'setting_id' => $this->setting->id,
        ]);

        $this->product = Product::create([
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
             'category_id' => $this->category->id,
             'setting_id' => $this->setting->id,
        ]);
    }

    public function test_can_edit_rejected_return_resets_status()
    {
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-REJECT-EDIT',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'REJECTED',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'approval_status' => 'Rejected',
            'rejected_at' => now(),
            'rejected_by' => $this->user->id,
            'rejection_reason' => 'Test Rejection'
        ]);

        // Mock Cart instance logic (bypassing full cart simulation for brevity if possible, 
        // but since update uses Cart, we might need to mock or just rely on update logic not crashing on empty details 
        // or populate details. The controller destroys cart, then repopulates from DB details.
        // We need to add details to the PR first.
        
        \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        $response = $this->put(route('purchase-returns.update', $purchaseReturn->id), [
            'date' => now()->toDateString(),
            'reference' => 'PRRN-REJECT-EDIT',
            'supplier_id' => $this->supplier->id,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'total_amount' => 1000,
            'payment_method' => 'Cash',
            'status' => 'PENDING_APPROVAL',
            'note' => 'Updated Note',
        ]);

        $response->assertStatus(302);
        $purchaseReturn->refresh();
        
        // Assert status is reset to Pending
        $this->assertEquals('pending', strtolower($purchaseReturn->approval_status));
        $this->assertEquals('PENDING_APPROVAL', $purchaseReturn->status);
    }

    public function test_can_delete_rejected_return()
    {
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-REJECT-DEL',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'REJECTED',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'approval_status' => 'Rejected',
            'rejected_at' => now(),
            'rejected_by' => $this->user->id,
        ]);

        $response = $this->delete(route('purchase-returns.destroy', $purchaseReturn->id));

        $response->assertStatus(302);
        $this->assertDatabaseMissing('purchase_returns', ['id' => $purchaseReturn->id]);
    }

    public function test_can_repropose_rejected_return()
    {
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-REPROPOSE',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'REJECTED',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'approval_status' => 'Rejected',
            'rejected_at' => now(),
            'rejected_by' => $this->user->id,
            'rejection_reason' => 'Bad request'
        ]);

        $response = $this->post(route('purchase-returns.repropose', $purchaseReturn->id));

        $response->assertStatus(302);
        $response->assertRedirect(route('purchase-returns.show', $purchaseReturn->id));

        $purchaseReturn->refresh();

        $this->assertEquals('pending', strtolower($purchaseReturn->approval_status));
        $this->assertEquals('PENDING_APPROVAL', $purchaseReturn->status);
        $this->assertNull($purchaseReturn->rejected_at);
        $this->assertNull($purchaseReturn->rejected_by);
        $this->assertNull($purchaseReturn->rejection_reason);
    }

    public function test_cannot_repropose_non_rejected_return()
    {
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-PENDING',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'approval_status' => 'Pending',
            'status' => 'PENDING_APPROVAL',
        ]);

        $response = $this->post(route('purchase-returns.repropose', $purchaseReturn->id));

        $response->assertStatus(403);
    }
}
