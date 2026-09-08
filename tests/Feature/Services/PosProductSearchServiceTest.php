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

    public function test_search_results_include_unit_options_with_business_isolation_and_missing_price_defaults(): void
    {
        $user = $this->createUserWithPermission('inventory.view_remaining_stock');
        $this->actingAs($user);

        $baseUnit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Piece',
            'short_name' => 'PCS',
        ]);
        $boxUnit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Box',
            'short_name' => 'BOX',
        ]);
        $dusUnit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Dus',
            'short_name' => 'DS',
        ]);

        $product = $this->createProduct([
            'product_name' => 'Product Unit Options',
            'product_code' => 'SKU-UOPT-1',
            'base_unit_id' => $baseUnit->id,
            'unit_id' => $baseUnit->id,
            'product_price' => 10000,
        ]);
        $this->seedProductPriceForSetting($product, 10000);
        $this->createStockForProduct($product, 100);

        // Conversion 1: Box of 10. No price row for setting -> defaults to enabled!
        $convBox = \Modules\Product\Entities\ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $baseUnit->id,
            'conversion_factor' => 10,
            'barcode' => 'CONV-BOX-1',
        ]);

        // Conversion 2: Dus of 50. Setting row has sales_enabled = false, purchase_enabled = true (purchase flag independence)
        $convDus = \Modules\Product\Entities\ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $dusUnit->id,
            'base_unit_id' => $baseUnit->id,
            'conversion_factor' => 50,
            'barcode' => 'CONV-DUS-1',
        ]);
        \Modules\Product\Entities\ProductUnitConversionPrice::create([
            'product_unit_conversion_id' => $convDus->id,
            'setting_id' => $this->setting->id,
            'price' => 450000,
            'sales_enabled' => false,
            'purchase_enabled' => true,
        ]);

        $service = new PosProductSearchService();
        $results = $service->search($this->setting->id, 'Product Unit Options');

        $this->assertCount(1, $results['results']);
        $item = $results['results'][0];
        $this->assertArrayHasKey('unit_options', $item);
        $this->assertNotNull($item['unit_options']);

        $options = $item['unit_options'];
        $this->assertTrue($options['has_selectable_units']);
        $this->assertSame('PCS', $options['base_unit']['short_name']);

        // Conversions list has 2 conversions: Box (sales enabled, valid) and Dus (sales disabled)
        $this->assertCount(2, $options['conversions']);
        
        $boxOpt = collect($options['conversions'])->firstWhere('id', $convBox->id);
        $this->assertNotNull($boxOpt);
        $this->assertTrue($boxOpt['is_valid']);
        $this->assertTrue($boxOpt['sales_enabled']);
        $this->assertSame(10, $boxOpt['conversion_factor']);
        $this->assertNull($boxOpt['price_for_setting']);

        $dusOpt = collect($options['conversions'])->firstWhere('id', $convDus->id);
        $this->assertNotNull($dusOpt);
        $this->assertTrue($dusOpt['is_valid']);
        $this->assertFalse($dusOpt['sales_enabled']);
        $this->assertSame(50, $dusOpt['conversion_factor']);
        $this->assertSame(450000.0, $dusOpt['price_for_setting']);
    }

    public function test_unit_options_conversion_price_is_isolated_per_business(): void
    {
        $user = $this->createUserWithPermission('inventory.view_remaining_stock');
        $this->actingAs($user);

        $baseUnit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Piece',
            'short_name' => 'PCS',
        ]);
        $boxUnit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Box',
            'short_name' => 'BOX',
        ]);

        $otherSetting = Setting::create([
            'company_name' => 'Other Business',
            'company_email' => 'other@test.com',
            'company_phone' => '456',
            'notification_email' => 'other-notify@test.com',
            'default_currency_id' => $this->setting->default_currency_id,
            'default_currency_position' => 'prefix',
            'footer_text' => '',
            'company_address' => '',
        ]);
        Location::create([
            'name' => 'Other Business Location',
            'setting_id' => $otherSetting->id,
        ]);

        $product = $this->createProduct([
            'product_name' => 'Cross Business Conversion Product',
            'product_code' => 'SKU-UOPT-XB',
            'base_unit_id' => $baseUnit->id,
            'unit_id' => $baseUnit->id,
            'product_price' => 10000,
        ]);
        $this->seedProductPriceForSetting($product, 10000);
        $this->createStockForProduct($product, 100);
        $product->prices()->create([
            'setting_id' => $otherSetting->id,
            'sale_price' => 10000,
        ]);

        $convBox = \Modules\Product\Entities\ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $baseUnit->id,
            'conversion_factor' => 10,
            'barcode' => 'CONV-BOX-XB',
        ]);

        \Modules\Product\Entities\ProductUnitConversionPrice::create([
            'product_unit_conversion_id' => $convBox->id,
            'setting_id' => $this->setting->id,
            'price' => 120000,
            'sales_enabled' => true,
            'purchase_enabled' => true,
        ]);

        $service = new PosProductSearchService();

        $ownResults = $service->search($this->setting->id, 'Cross Business Conversion Product');
        $ownOpt = collect($ownResults['results'][0]['unit_options']['conversions'])->firstWhere('id', $convBox->id);
        $this->assertEquals(120000, $ownOpt['price_for_setting']);

        $otherResults = $service->search($otherSetting->id, 'Cross Business Conversion Product');
        $otherOpt = collect($otherResults['results'][0]['unit_options']['conversions'])->firstWhere('id', $convBox->id);
        $this->assertNull($otherOpt['price_for_setting']);
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
