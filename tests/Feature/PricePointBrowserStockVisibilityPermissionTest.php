<?php

namespace Tests\Feature;

use App\Livewire\PricePoint\Browser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Location;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PricePointBrowserStockVisibilityPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;
    private Setting $setting;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        \Spatie\Permission\Models\Permission::create([
            'name' => 'inventory.view_remaining_stock',
            'guard_name' => 'web',
        ]);

        $this->currency = Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        $this->setting = Setting::create([
            'company_name'                  => 'Test Outlet',
            'company_email'                 => 'test@example.com',
            'company_phone'                 => '123456789',
            'notification_email'            => 'notify@example.com',
            'default_currency_id'           => $this->currency->id,
            'default_currency_position'     => 'prefix',
            'footer_text'                   => 'Footer',
            'company_address'               => 'Address'
        ]);

        $this->location = Location::create([
            'name' => 'Default Location',
            'setting_id' => $this->setting->id,
        ]);
    }

    private function createProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'setting_id'              => $this->setting->id,
            'product_name'            => 'Test Product',
            'product_code'            => 'CODE-' . uniqid(),
            'product_quantity'        => 5,
            'product_cost'            => 0,
            'product_price'           => 0,
            'product_stock_alert'     => 0,
            'is_purchased'            => false,
            'is_sold'                 => true,
            'stock_managed'           => true,
            'is_active'               => true,
        ], $attributes));
    }

    private function createProductPrice(Product $product, int $salePrice = 100000): ProductPrice
    {
        return ProductPrice::create([
            'product_id'             => $product->id,
            'setting_id'             => $this->setting->id,
            'sale_price'             => $salePrice,
            'tier_1_price'           => 0,
            'tier_2_price'           => 0,
        ]);
    }

    private function createStockForProduct(Product $product, int $qty): void
    {
        $product->productStocks()->create([
            'location_id' => $this->location->id,
            'quantity' => $qty,
            'quantity_non_tax' => $qty,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);
    }

    public function test_permitted_user_receives_available_qty_and_formatted_available_qty()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.view_remaining_stock');

        $product = $this->createProduct(['product_name' => 'Test Product']);
        $this->createProductPrice($product);
        $this->createStockForProduct($product, 15);

        $component = Livewire::actingAs($user)
            ->test(Browser::class, ['setting' => $this->setting]);

        $component->set('q', 'Test Product');

        $products = $component->viewData('products');
        $this->assertCount(1, $products);

        $product = $products->first();
        $this->assertTrue(isset($product->available_qty), 'available_qty should be present for permitted user');
        $this->assertEquals(15, $product->available_qty);
        $this->assertTrue(isset($product->formatted_available_qty), 'formatted_available_qty should be present for permitted user');
    }

    public function test_unpermitted_user_does_not_receive_available_qty_or_formatted_available_qty()
    {
        $user = User::factory()->create();
        // Do not grant the permission

        $product = $this->createProduct(['product_name' => 'Test Product']);
        $this->createProductPrice($product);
        $this->createStockForProduct($product, 15);

        $component = Livewire::actingAs($user)
            ->test(Browser::class, ['setting' => $this->setting]);

        $component->set('q', 'Test Product');

        $products = $component->viewData('products');
        $this->assertCount(1, $products);

        $product = $products->first();
        $this->assertFalse(isset($product->available_qty), 'available_qty should not be present for unpermitted user');
        $this->assertFalse(isset($product->formatted_available_qty), 'formatted_available_qty should not be present for unpermitted user');
    }

    public function test_unpermitted_user_still_sees_stock_state_badge()
    {
        $user = User::factory()->create();
        // Do not grant the permission

        $product = $this->createProduct(['product_name' => 'Test Product']);
        $this->createProductPrice($product);
        $this->createStockForProduct($product, 0);

        $component = Livewire::actingAs($user)
            ->test(Browser::class, ['setting' => $this->setting]);

        $component->set('q', 'Test Product');

        $products = $component->viewData('products');
        $this->assertCount(1, $products);

        $product = $products->first();
        $this->assertEquals('out_of_stock', $product->stock_state);
        $this->assertFalse(isset($product->available_qty), 'available_qty should not be present');
    }

    public function test_unpermitted_user_still_sees_service_badge()
    {
        $user = User::factory()->create();
        // Do not grant the permission

        $product = $this->createProduct(['product_name' => 'Test Service Product', 'stock_managed' => false]);
        $this->createProductPrice($product);

        $component = Livewire::actingAs($user)
            ->test(Browser::class, ['setting' => $this->setting]);

        $component->set('q', 'Test Service Product');

        $products = $component->viewData('products');
        $this->assertCount(1, $products);

        $product = $products->first();
        $this->assertEquals('service', $product->stock_state);
        $this->assertFalse(isset($product->available_qty), 'available_qty should not be present');
    }
}
