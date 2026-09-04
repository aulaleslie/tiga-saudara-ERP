<?php

namespace Tests\Feature\Services;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Location;
use Modules\Pos\Services\PosProductSearchService;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PosProductSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        \Spatie\Permission\Models\Permission::create([
            'name' => 'inventory.view_remaining_stock',
            'guard_name' => 'web',
        ]);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test',
            'company_email' => 'test@test.com',
            'company_phone' => '123',
            'notification_email' => 'notify@test.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => '',
            'company_address' => '',
        ]);
        $this->location = Location::create([
            'name' => 'Default Location',
            'setting_id' => $this->setting->id,
        ]);
    }

    public function test_permitted_user_receives_available_qty()
    {
        $user = $this->createUserWithPermission('inventory.view_remaining_stock');
        $this->actingAs($user);

        $product = $this->createProduct(['product_name' => 'Test Product', 'stock_managed' => true]);
        $this->seedProductPriceForSetting($product, 50000);
        $this->createStockForProduct($product, 10);

        $service = new PosProductSearchService();
        $results = $service->search($this->setting->id, 'Test Product');

        $this->assertCount(1, $results['results']);
        $this->assertArrayHasKey('available_qty', $results['results'][0]);
        $this->assertEquals(10, $results['results'][0]['available_qty']);
    }

    public function test_unpermitted_user_does_not_receive_available_qty()
    {
        $user = $this->createUserWithoutPermission('inventory.view_remaining_stock');
        $this->actingAs($user);

        $product = $this->createProduct(['product_name' => 'Test Product', 'stock_managed' => true]);
        $this->seedProductPriceForSetting($product, 50000);
        $this->createStockForProduct($product, 10);

        $service = new PosProductSearchService();
        $results = $service->search($this->setting->id, 'Test Product');

        $this->assertCount(1, $results['results']);
        $this->assertArrayNotHasKey('available_qty', $results['results'][0]);
    }

    public function test_unpermitted_user_retains_other_fields()
    {
        $user = $this->createUserWithoutPermission('inventory.view_remaining_stock');
        $this->actingAs($user);

        $product = $this->createProduct([
            'product_name' => 'Test Product',
            'product_code' => 'SKU123',
            'stock_managed' => true,
        ]);
        $this->seedProductPriceForSetting($product, 50000);
        $this->createStockForProduct($product, 10);

        $service = new PosProductSearchService();
        $results = $service->search($this->setting->id, 'Test Product');

        $this->assertCount(1, $results['results']);
        $result = $results['results'][0];

        $this->assertArrayHasKey('product_name', $result);
        $this->assertEquals('Test Product', $result['product_name']);
        $this->assertArrayHasKey('product_code', $result);
        $this->assertEquals('SKU123', $result['product_code']);
        $this->assertArrayHasKey('sale_price', $result);
        $this->assertEquals(50000, $result['sale_price']);
        $this->assertArrayHasKey('stock_managed', $result);
        $this->assertTrue($result['stock_managed']);
        $this->assertArrayHasKey('stock_state', $result);
    }

    public function test_unpermitted_user_sees_correct_out_of_stock_badge()
    {
        $user = $this->createUserWithoutPermission('inventory.view_remaining_stock');
        $this->actingAs($user);

        $product = $this->createProduct(['product_name' => 'Test Product', 'stock_managed' => true]);
        $this->seedProductPriceForSetting($product);
        $this->createStockForProduct($product, 0);

        $service = new PosProductSearchService();
        $results = $service->search($this->setting->id, 'Test Product');

        $this->assertCount(1, $results['results']);
        $result = $results['results'][0];

        $this->assertEquals('out_of_stock', $result['stock_state']);
        $this->assertArrayNotHasKey('available_qty', $result);
    }

    public function test_unpermitted_user_sees_correct_service_badge()
    {
        $user = $this->createUserWithoutPermission('inventory.view_remaining_stock');
        $this->actingAs($user);

        $product = $this->createProduct(['product_name' => 'Test Product', 'stock_managed' => false]);
        $this->seedProductPriceForSetting($product);

        $service = new PosProductSearchService();
        $results = $service->search($this->setting->id, 'Test Product');

        $this->assertCount(1, $results['results']);
        $result = $results['results'][0];

        $this->assertEquals('service', $result['stock_state']);
        $this->assertArrayNotHasKey('available_qty', $result);
    }

    private function createProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'CODE-' . uniqid(),
            'is_sold' => true,
            'stock_managed' => true,
            'is_active' => true,
            'product_cost' => 0,
            'product_price' => 0,
        ], $attributes));
    }

    private function createUserWithPermission(string $permission): User
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test-' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->givePermissionTo($permission);
        return $user;
    }

    private function createUserWithoutPermission(string $permission = null): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => 'test-' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
    }

    private function seedProductPriceForSetting(Product $product, int $price = 50000)
    {
        $product->prices()->create([
            'setting_id' => $this->setting->id,
            'sale_price' => $price,
        ]);
    }

    private function createStockForProduct(Product $product, int $qty)
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
}
