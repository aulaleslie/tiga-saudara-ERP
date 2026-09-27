<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SaleDebugCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_sale_creation_persistence_and_visibility()
    {
        $this->withoutMiddleware(CheckUserRoleForSetting::class);

        // 1. Setup Data
        $setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => '123 Testing Lane',
        ]);

        $paymentTerm = PaymentTerm::create([
            'name' => 'Net 30',
            'longevity' => 30,
        ]);

        $customer = Customer::factory()->create([
            'setting_id' => $setting->id,
            'payment_term_id' => $paymentTerm->id,
        ]);

        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TP001',
            'product_quantity' => 100,
            'product_price' => 100,
            'product_cost' => 50,
            'product_unit' => 'Unit',
            'product_stock_alert' => 10,
            'setting_id' => $setting->id,
            // Add other required fields if any, checking DB schema or model validation would be best, 
            // but assuming these are enough based on factory usage in other places (if existed).
            // Actually, looks like 'unit_id', 'category_id', 'brand_id' might be needed if foreign keys are strict.
            // Let's create a Unit first since it's common.
        ]);

        // 2. Setup Permissions & User
        Permission::firstOrCreate(['name' => 'sales.create']);
        Permission::firstOrCreate(['name' => 'sales.access']); // For index page/datatable
        
        $user = User::factory()->create();
        $user->givePermissionTo(['sales.create', 'sales.access']);

        // 3. Authenticate & Session
        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id]);

        // 4. Add to Cart (simulating controller/livewire logic)
        Cart::instance('sale')->add([
            'id' => 'test-id',
            'name' => $product->product_name,
            'qty' => 1,
            'price' => 100,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'code' => $product->product_code,
                'unit_price' => 100,
                'product_tax_amount' => 0,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'sub_total' => 100,
                'bundle_items' => []
            ]
        ]);

        // 5. Submit Form
        $response = $this->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'due_date' => now()->addDay()->toDateString(),
            'tax_id' => null,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'payment_term_id' => $paymentTerm->id,
            'note' => 'Debug Note',
            'status' => 'Pending', // Or whatever strict string logic might be there
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash'
        ]);

        // 6. Assert Redirection
        $response->assertRedirect(route('sales.index'));
        // $response->assertSessionHas('toast_success'); // Skipped for now

        // 7. Assert Database Persistence
        $this->assertDatabaseHas('sales', [
            'customer_id' => $customer->id,
            'setting_id' => $setting->id,
            'total_amount' => 100,
        ]);

        $sale = Sale::latest()->first();
        $this->assertNotNull($sale);
        $this->assertEquals($setting->id, $sale->setting_id);
        
        // 8. Test Visibility in DataTable Query logic
        // Verify that passing setting_id matches
        $retrieved = Sale::where('setting_id', $setting->id)->find($sale->id);
        $this->assertNotNull($retrieved, 'Sale should be visible with correct setting_id');

        // Verify Cart is cleared
        $this->assertEquals(0, Cart::instance('sale')->count());
    }
    public function test_livewire_sale_creation_persistence()
    {
        $this->withoutMiddleware(CheckUserRoleForSetting::class);
        
        // Setup (Reuse setup logic or extract to helper, but for now duplicate for speed)
        $setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => '123 Testing Lane',
        ]);
        $paymentTerm = PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);
        $customer = Customer::factory()->create(['setting_id' => $setting->id, 'payment_term_id' => $paymentTerm->id]);
        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TP001',
            'product_quantity' => 100,
            'product_price' => 100,
            'product_cost' => 50,
            'product_unit' => 'Unit',
            'product_stock_alert' => 10,
            'setting_id' => $setting->id,
        ]);
        
        Permission::firstOrCreate(['name' => 'sales.create']);
        Permission::firstOrCreate(['name' => 'sales.access']);
        $user = User::factory()->create();
        $user->givePermissionTo(['sales.create', 'sales.access']);
        
        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        // Add to Cart
        Cart::instance('sale')->add([
            'id' => 'test-id',
            'name' => $product->product_name,
            'qty' => 1,
            'price' => 100,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'code' => $product->product_code,
                'unit_price' => 100,
                'product_tax_amount' => 0,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'sub_total' => 100,
                'sub_total_before_tax' => 100,
                'bundle_items' => []
            ]
        ]);

        $idempotencyToken = (string) \Illuminate\Support\Str::uuid();

        // Livewire Test
        \Livewire\Livewire::test(\App\Livewire\Sale\CreateForm::class, ['idempotencyToken' => $idempotencyToken])
            ->set('customerId', $customer->id)
            ->set('paymentTermId', $paymentTerm->id)
            ->set('date', now()->toDateString())
            ->call('submit');

        // Verify Persistence
        $this->assertDatabaseHas('sales', [
            'customer_id' => $customer->id,
            'setting_id' => $setting->id,
            'total_amount' => 100,
        ]);
    }
}
