<?php

namespace Tests\Feature;

use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Services\SaleService;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleDispatchAggregationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->actingAs($user);

        \Illuminate\Support\Facades\Gate::before(fn() => true);

        $setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@test.com',
            'company_phone' => '123456789',
            'notification_email' => 'test@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => true,
        ]);

        Session::put('setting_id', $setting->id);
    }

    /** @test */
    public function dispatch_view_aggregates_duplicate_sale_details()
    {
        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'P001',
            'product_price' => 100,
            'product_quantity' => 100,
            'product_unit' => 'pcs',
            'product_cost' => 0,
            'setting_id' => 1,
        ]);

        $customer = Customer::factory()->create();

        Cart::instance('sale')->destroy();
        
        // Add same product twice
        Cart::instance('sale')->add([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'name' => $product->product_name,
            'qty' => 2,
            'price' => 100,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'sub_total' => 200,
                'sub_total_before_tax' => 200,
                'code' => $product->product_code,
            ]
        ]);

        Cart::instance('sale')->add([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'name' => $product->product_name,
            'qty' => 3,
            'price' => 100,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'sub_total' => 300,
                'sub_total_before_tax' => 300,
                'code' => $product->product_code,
            ]
        ]);

        $saleService = app(SaleService::class);
        $sale = $saleService->createSale([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'customer_id' => $customer->id,
            'setting_id' => 1,
            'status' => Sale::STATUS_APPROVED,
        ], Cart::instance('sale')->content());

        $this->assertEquals(2, $sale->saleDetails()->count());
        $this->assertEquals(5, $sale->saleDetails()->sum('quantity'));

        $response = $this->get(route('sales.dispatch', $sale));
        $response->assertStatus(200);
        
        // Check that only ONE row for the product exists in the view data
        $aggregatedProducts = $response->viewData('aggregatedProducts');
        $this->assertCount(1, $aggregatedProducts);
        $this->assertEquals(5, $aggregatedProducts[$product->id . '--0']['total_quantity']);
    }
}
