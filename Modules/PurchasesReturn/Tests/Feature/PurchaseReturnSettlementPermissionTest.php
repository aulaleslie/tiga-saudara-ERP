<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseReturnSettlementPermissionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private PurchaseReturn $purchaseReturn;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->user = User::factory()->create(['is_active' => 1]);
        
        $supplier = Supplier::create([
            'supplier_name' => 'Supplier Test',
            'supplier_phone' => '123',
            'supplier_email' => 'test@supplier.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);

        $location = Location::create([
             'name' => 'Test Location',
             'setting_id' => $this->setting->id,
        ]);

        $this->purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-001',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'setting_id' => $this->setting->id,
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
            'approval_status' => 'Approved',
        ]);

        $category = \Modules\Product\Entities\Category::create([
            'category_code' => 'TEST_CAT_SETUP',
            'category_name' => 'Test Category Setup',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $product = Product::create([
            'product_name' => 'Test Product Setup',
            'product_code' => 'TEST000',
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 10,
            'product_unit' => 'pcs',
            'product_stock_alert' => 0,
            'category_id' => $category->id,
            'setting_id' => $this->setting->id,
        ]);

        PurchaseReturnDetail::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
    }

    /** @test */
    public function unauthorized_user_cannot_access_settlement_form()
    {
        $this->actingAs($this->user);

        // Accessing the page should return 403
        $response = $this->get(route('purchase-returns.settlement', $this->purchaseReturn->id));
        $response->assertStatus(403);

        // Livewire component should also abort
        Livewire::test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $this->purchaseReturn->id])
            ->assertStatus(403);
    }


    /** @test */
    public function unauthorized_user_cannot_approve_items()
    {
        $this->actingAs($this->user);

        $category = \Modules\Product\Entities\Category::create([
            'category_code' => 'TEST_CAT',
            'category_name' => 'Test Category',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST001',
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 10,
            'product_unit' => 'pcs',
            'product_stock_alert' => 0,
            'category_id' => $category->id,
            'setting_id' => $this->setting->id,
        ]);
        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $itemSettlement = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'CASH',
            'nominal' => 1000,
            'status' => PurchaseReturnItemSettlement::STATUS_SUBMITTED,
        ]);

        $response = $this->post(route('purchase-return-settlements.item.approve', $itemSettlement->id));
        $response->assertStatus(403);
    }

    /** @test */
    public function unauthorized_user_cannot_reject_items()
    {
        $this->actingAs($this->user);

        $category = \Modules\Product\Entities\Category::create([
            'category_code' => 'TEST_CAT_REJ',
            'category_name' => 'Test Category Reject',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $product = Product::create([
            'product_name' => 'Test Product Reject',
            'product_code' => 'TEST002',
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 10,
            'product_unit' => 'pcs',
            'product_stock_alert' => 0,
            'category_id' => $category->id,
            'setting_id' => $this->setting->id,
        ]);
        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $itemSettlement = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'CASH',
            'nominal' => 1000,
            'status' => PurchaseReturnItemSettlement::STATUS_SUBMITTED,
        ]);

        $response = $this->post(route('purchase-return-settlements.item.reject', $itemSettlement->id), [
            'rejection_reason' => 'Unauthorized rejection'
        ]);
        $response->assertStatus(403);
    }

    /** @test */
    public function it_hides_nominal_when_user_cannot_view_price()
    {
        \Spatie\Permission\Models\Permission::findOrCreate('purchaseReturnSettlements.submit');
        $this->user->givePermissionTo('purchaseReturnSettlements.submit');
        
        $this->actingAs($this->user);

        Livewire::test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $this->purchaseReturn->id])
            ->assertSet('canViewPrice', false)
            ->assertDontSee('Nilai Penyelesaian');
    }

    /** @test */
    public function it_shows_nominal_when_user_can_view_price()
    {
        \Spatie\Permission\Models\Permission::findOrCreate('purchaseReturnSettlements.submit');
        \Spatie\Permission\Models\Permission::findOrCreate('purchaseReturns.viewPrice');
        $this->user->givePermissionTo(['purchaseReturnSettlements.submit', 'purchaseReturns.viewPrice']);
        
        $this->actingAs($this->user);

        Livewire::test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $this->purchaseReturn->id])
            ->assertSet('canViewPrice', true)
            ->assertSee('Nilai Penyelesaian');
    }
}
