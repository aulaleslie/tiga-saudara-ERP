<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Tests\TestCase;
use Gloudemans\Shoppingcart\Facades\Cart;
use Livewire\Livewire;
use App\Livewire\Sale\CreateForm;
use Modules\Setting\Entities\Setting;

class SaleStockValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        // Required permissions
        Gate::define('sales.create', fn() => true);
        Gate::define('sales.access', fn() => true);

        // Standard setup
        $setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '12345',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'footer',
            'company_address' => 'address'
        ]);
        session(['setting_id' => $setting->id]);
    }

    public function test_standard_sale_creation_fails_when_stock_insufficient()
    {
        $customer = Customer::factory()->create(['setting_id' => session('setting_id')]);
        $paymentTerm = PaymentTerm::create(['name' => 'Cash', 'longevity' => 0]);
        $product = Product::create([
            'setting_id' => session('setting_id'),
            'product_name' => 'Test Product',
            'product_code' => 'PRD-001',
            'product_quantity' => 10,
            'product_cost' => 50,
            'product_price' => 100
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'test_item',
            'name' => $product->product_name,
            'qty' => 11,
            'price' => 100,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'code' => $product->product_code,
                'unit_price' => 100,
                'sub_total' => 1100,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        $response = $this->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'payment_term_id' => $paymentTerm->id,
            'shipping_amount' => 0,
            'total_amount' => 1100,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'is_tax_included' => false,
        ]);

        $response->assertSessionHasErrors('cart');
        $this->assertEquals(0, Sale::count());
    }

    public function test_standard_sale_creation_succeeds_when_stock_sufficient()
    {
        $customer = Customer::factory()->create(['setting_id' => session('setting_id')]);
        $paymentTerm = PaymentTerm::create(['name' => 'Cash', 'longevity' => 0]);
        $product = Product::create([
            'setting_id' => session('setting_id'),
            'product_name' => 'Test Product',
            'product_code' => 'PRD-002',
            'product_quantity' => 10,
            'product_cost' => 50,
            'product_price' => 100
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'test_item',
            'name' => $product->product_name,
            'qty' => 10,
            'price' => 100,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'code' => $product->product_code,
                'unit_price' => 100,
                'sub_total' => 1000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        $response = $this->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'payment_term_id' => $paymentTerm->id,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'is_tax_included' => false,
        ]);

        $response->assertRedirect(route('sales.index'));
        $this->assertEquals(1, Sale::count());
    }

    public function test_bundle_sale_creation_fails_when_bundle_item_stock_insufficient()
    {
        $customer = Customer::factory()->create(['setting_id' => session('setting_id')]);
        $paymentTerm = PaymentTerm::create(['name' => 'Cash', 'longevity' => 0]);
        
        $parentProduct = Product::create([
            'setting_id' => session('setting_id'),
            'product_name' => 'Bundle Parent',
            'product_code' => 'BNDL-001',
            'product_quantity' => 100,
            'product_cost' => 100,
            'product_price' => 200
        ]);
        $bundleComponent = Product::create([
            'setting_id' => session('setting_id'),
            'product_name' => 'Component',
            'product_code' => 'COMP-001',
            'product_quantity' => 5,
            'product_cost' => 40,
            'product_price' => 50
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'test_bundle',
            'name' => $parentProduct->product_name,
            'qty' => 2,
            'price' => 200,
            'weight' => 1,
            'options' => [
                'product_id' => $parentProduct->id,
                'code' => $parentProduct->product_code,
                'unit_price' => 200,
                'sub_total' => 400,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => [
                    [
                        'product_id' => $bundleComponent->id,
                        'name' => $bundleComponent->product_name,
                        'quantity' => 3, // 3 * 2 = 6 requested, but only 5 available
                        'price' => 50,
                        'sub_total' => 150
                    ]
                ]
            ]
        ]);

        $response = $this->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'payment_term_id' => $paymentTerm->id,
            'shipping_amount' => 0,
            'total_amount' => 400,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'is_tax_included' => false,
        ]);

        $response->assertSessionHasErrors('cart');
        $this->assertEquals(0, Sale::count());
    }

    public function test_livewire_sale_creation_fails_when_stock_insufficient()
    {
        $customer = Customer::factory()->create(['setting_id' => session('setting_id')]);
        $paymentTerm = PaymentTerm::create(['name' => 'Cash', 'longevity' => 0]);
        $product = Product::create([
            'setting_id' => session('setting_id'),
            'product_name' => 'Test Product',
            'product_code' => 'PRD-003',
            'product_quantity' => 10,
            'product_cost' => 50,
            'product_price' => 100
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'test_item',
            'name' => $product->product_name,
            'qty' => 11,
            'price' => 100,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'code' => $product->product_code,
                'unit_price' => 100,
                'sub_total' => 1100,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        Livewire::test(CreateForm::class, ['idempotencyToken' => (string) \Illuminate\Support\Str::uuid()])
            ->set('customerId', $customer->id)
            ->set('paymentTermId', $paymentTerm->id)
            ->set('date', now()->format('Y-m-d'))
            ->set('dueDate', now()->format('Y-m-d'))
            ->call('submit')
            ->assertDispatched('notify', function ($name, $params) {
                $data = $params[0];
                return $data['type'] === 'error' && str_contains($data['message'], 'tidak mencukupi');
            });

        $this->assertEquals(0, Sale::count());
    }
}
