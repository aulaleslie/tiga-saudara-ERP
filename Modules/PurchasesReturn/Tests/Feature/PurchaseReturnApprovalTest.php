<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnSettlement;
use App\Models\User;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Category;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;

class PurchaseReturnApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'purchaseReturnSettlements.access',
            'purchaseReturnSettlements.submit',
            'purchaseReturnSettlements.approve',
            'purchaseReturnSettlements.execute',
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

    public function test_can_approve_settlement()
    {
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-APP',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'PENDING_APPROVAL',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'approval_status' => 'Approved',
        ]);

        $settlement = PurchaseReturnSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'method' => 'mixed',
            'status' => 'pending',
            'submitted_by' => $this->user->id,
            'submitted_at' => now(),
        ]);

        $response = $this->post(route('purchase-return-settlements.approve', $settlement->id));
        
        $response->assertStatus(302);
        $settlement->refresh();
        $this->assertEquals('APPROVED', $settlement->status);
        $this->assertEquals($this->user->id, $settlement->approved_by);
        $this->assertNotNull($settlement->approved_at);
    }

    public function test_can_execute_modify_purchase_settlement()
    {
        // Setup a purchase that is partially paid
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PUR-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'total_amount' => 5000,
            'paid_amount' => 1000,
            'due_amount' => 4000,
            'status' => 'Completed',
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 5,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_amount' => 0,
            'sub_total' => 5000,
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 1,
        ]);

        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-EXEC',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'PENDING_APPROVAL',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'approval_status' => 'Approved',
        ]);

        $settlement = PurchaseReturnSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'method' => 'mixed',
            'status' => 'approved',
            'approved_by' => $this->user->id,
            'approved_at' => now(),
        ]);

        // Create detail for the return
        $detail = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
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

        // Create settlement item for MODIFY_PURCHASE
        \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 1000,
            'target_purchase_id' => $purchase->id,
        ]);

        $response = $this->post(route('purchase-return-settlements.execute', $settlement->id));
        
        $response->assertStatus(302);
        $settlement->refresh();
        $this->assertEquals('COMPLETED', $settlement->status);

        $purchase->refresh();
        $this->assertEquals(4000, (float)$purchase->total_amount);
        $this->assertEquals(3000, (float)$purchase->due_amount);
        $this->assertEquals(1000, (float)$purchase->paid_amount);

        $purchaseReturn->refresh();
        $this->assertEquals('COMPLETED', $purchaseReturn->status);
        $this->assertEquals('PAID', $purchaseReturn->payment_status);
        $this->assertEquals(1000, (float)$purchaseReturn->paid_amount);
    }

    public function test_execute_product_repair_sets_status_to_executing()
    {
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-REPAIR',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'PENDING_APPROVAL',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'approval_status' => 'Approved',
        ]);

        $settlement = PurchaseReturnSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'method' => 'mixed',
            'status' => 'approved',
            'approved_by' => $this->user->id,
            'approved_at' => now(),
        ]);

        // Create detail for the return
        $detail = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
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

        // Create settlement item for PRODUCT_REPAIR
        \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'PRODUCT_REPAIR',
            'nominal' => 1000,
        ]);

        $response = $this->post(route('purchase-return-settlements.execute', $settlement->id));
        
        $response->assertStatus(302);
        $settlement->refresh();
        $this->assertEquals('EXECUTING', $settlement->status);
    }
}
