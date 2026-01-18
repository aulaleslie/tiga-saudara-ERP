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
use Spatie\Permission\Models\Permission;

class PurchaseReturnDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $supplier;
    protected $location;
    protected $category;
    protected $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'purchaseReturns.dispatchRequest',
            'purchaseReturns.dispatchApproval',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
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

    public function test_can_reject_dispatch_successfully()
    {
        // 1. Create a PurchaseReturn that is 'approved' and has 'pending_approval' dispatch status
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-DISPATCH-REJECT',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'return_dispatch_status' => 'pending_approval',
            'return_shipping_amount' => 50.00, // Non-zero amount
            'return_dispatch_note' => 'Dispatch note',
            'dispatch_requested_by' => $this->user->id,
            'dispatch_requested_at' => now(),
        ]);

        // 2. Send the reject dispatch request
        $response = $this->post(route('purchase-returns.dispatch-reject', $purchaseReturn->id), [
            'reason' => 'Test rejection reason',
        ]);

        // 3. Verify response
        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        // 4. Verify database state
        $purchaseReturn->refresh();
        $this->assertEquals('REJECTED', $purchaseReturn->return_dispatch_status);
        $this->assertEquals(0, (float)$purchaseReturn->return_shipping_amount); // Should be 0, not null
        $this->assertNull($purchaseReturn->return_dispatch_note);
        $this->assertEquals($this->user->id, $purchaseReturn->dispatch_rejected_by);
        $this->assertNotNull($purchaseReturn->dispatch_rejected_at);
        $this->assertEquals('TEST REJECTION REASON', $purchaseReturn->dispatch_rejection_reason);
    }

    public function test_request_dispatch_requires_attachments()
    {
        // 1. Create a PurchaseReturn that is 'approved'
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-DISPATCH-REQ-ERR',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
        ]);

        // 2. Send request without attachments
        $response = $this->post(route('purchase-returns.dispatch-request', $purchaseReturn->id), [
            'return_dispatch_note' => 'Dispatch note without attachments',
            'return_shipping_amount' => '50.00',
            // 'return_awb_attachments' is missing
        ]);

        // 3. Verify validation error
        $response->assertStatus(302);
        $response->assertSessionHasErrors(['return_awb_attachments']);
        
        $purchaseReturn->refresh();
        $this->assertNull($purchaseReturn->return_dispatch_status);
    }
}
