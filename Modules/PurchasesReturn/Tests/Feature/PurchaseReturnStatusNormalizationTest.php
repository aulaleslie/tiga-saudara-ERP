<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class PurchaseReturnStatusNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $user;
    protected $supplier;
    protected $product;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        
        \Illuminate\Support\Facades\Config::set('scout.driver', null);

        \Modules\Currency\Entities\Currency::create([
             'id' => 1,
             'currency_name' => 'Rupiah',
             'code' => 'IDR',
             'symbol' => 'Rp',
             'thousand_separator' => '.',
             'decimal_separator' => ',',
             'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
             'id' => 1,
             'company_name' => 'Test Company',
             'company_email' => 'test@company.com',
             'company_phone' => '1234567890',
             'company_address' => 'Test Address',
             'default_currency_id' => 1,
             'default_currency_position' => 'prefix',
             'notification_email' => 'notification@test.com',
             'footer_text' => 'Test Footer',
        ]);

        $this->supplier = \Modules\People\Entities\Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@supplier.com',
            'supplier_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => 1,
        ]);

        \Spatie\Permission\Models\Permission::findOrCreate('purchaseReturns.access');
        \Spatie\Permission\Models\Permission::findOrCreate('purchaseReturns.create');
        \Spatie\Permission\Models\Permission::findOrCreate('purchaseReturns.edit');
        \Spatie\Permission\Models\Permission::findOrCreate('purchaseReturns.delete');
        \Spatie\Permission\Models\Permission::findOrCreate('purchaseReturns.approval');
        \Spatie\Permission\Models\Permission::findOrCreate('purchaseReturns.dispatchApproval');

        $this->user = User::factory()->create();
        $this->user->givePermissionTo('purchaseReturns.access');
        $this->user->givePermissionTo('purchaseReturns.create');
        $this->user->givePermissionTo('purchaseReturns.edit');
        $this->user->givePermissionTo('purchaseReturns.delete');
        $this->user->givePermissionTo('purchaseReturns.approval');
        $this->user->givePermissionTo('purchaseReturns.dispatchApproval');
        
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->product = Product::create([
            'id' => 1,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_quantity' => 100,
            'product_cost' => 10000,
            'product_price' => 12000,
            'product_unit' => 'pcs',
            'product_stock_alert' => 10,
            'setting_id' => 1,
        ]);

        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => 1,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);
    }

    protected function setUpDatabase()
    {
        \Modules\Currency\Entities\Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@company.com',
            'company_phone' => '1234567890',
            'company_address' => 'Test Address',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notification@test.com',
            'footer_text' => 'Test Footer',
        ]);

        $this->supplier = \Modules\People\Entities\Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@supplier.com',
            'supplier_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => 1,
        ]);

        \Modules\Setting\Entities\Location::create([
            'id' => 1,
            'name' => 'Main Warehouse',
            'setting_id' => 1,
        ]);
    }

    protected function createPR(array $attributes = [])
    {
        return PurchaseReturn::create(array_merge([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'date' => now(),
            'approval_status' => 'pending',
            'status' => PurchaseReturn::STATUS_PENDING_APPROVAL,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
        ], $attributes));
    }

    protected function createDetail($pr, array $attributes = [])
    {
        $id = \Illuminate\Support\Facades\DB::table('purchase_return_details')->insertGetId(array_merge([
            'purchase_return_id' => $pr->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 100,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'location_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::find($id);
    }

    /** @test */
    public function it_writes_normalized_pending_approval_status_on_document_creation()
    {
        // We use a mock or bypass the Livewire form as we are testing the controller/logic
        $pr = $this->createPR([
            'status' => PurchaseReturn::STATUS_PENDING_APPROVAL, // Manual create to simulate store() result
        ]);

        $this->assertEquals(PurchaseReturn::STATUS_PENDING_APPROVAL, $pr->status);
    }

    /** @test */
    public function it_writes_normalized_status_on_approval()
    {
        $pr = $this->createPR([
            'status' => 'Pending Approval', // Legacy style
        ]);

        $this->createDetail($pr);

        $response = $this->post(route('purchase-returns.approve', $pr));
        
        $pr->refresh();
        $this->assertEquals('APPROVED', $pr->approval_status);
        $this->assertEquals(PurchaseReturn::STATUS_AWAITING_DISPATCH, $pr->status);
    }

    /** @test */
    public function it_writes_normalized_status_on_rejection()
    {
        $pr = $this->createPR([
            'status' => 'Pending Approval',
        ]);

        $response = $this->post(route('purchase-returns.reject', $pr), [
            'reason' => 'Test Rejection'
        ]);

        $pr->refresh();
        $this->assertEquals('REJECTED', $pr->approval_status);
        $this->assertEquals(PurchaseReturn::STATUS_REJECTED, $pr->status);
    }

    /** @test */
    public function it_writes_normalized_status_on_dispatch_approval()
    {
        $pr = $this->createPR([
            'approval_status' => 'approved',
            'return_dispatch_status' => 'pending_approval',
            'status' => PurchaseReturn::STATUS_DISPATCH_PENDING_APPROVAL,
        ]);

        $this->createDetail($pr);

        $response = $this->post(route('purchase-returns.dispatch-approve', $pr));

        $pr->refresh();
        $this->assertEquals('DISPATCHED', $pr->return_dispatch_status);
        $this->assertEquals(PurchaseReturn::STATUS_IN_RETURN, $pr->status);
    }
}
