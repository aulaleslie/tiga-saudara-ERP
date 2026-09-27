<?php

namespace Tests\Feature;

use App\Livewire\PricePoint\Browser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Unit;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PricePointBrowserSearchTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;
    private Setting $setting;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);

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

        $this->user = User::factory()->create();
    }

    private function createProduct(array $attributes = []): Product
    {
        $product = Product::create(array_merge([
            'setting_id'              => $this->setting->id,
            'product_name'            => 'Test Product',
            'product_code'            => 'CODE-' . uniqid(),
            'product_quantity'        => 5,
            'product_cost'            => 0,
            'product_price'           => 0,
            'product_stock_alert'     => 0,
            'is_purchased'            => false,
            'is_sold'                 => false,
        ], $attributes));

        return $product;
    }

    private function createProductPrice(Product $product, int $salePrice = 100000, int $settingId = null): ProductPrice
    {
        return ProductPrice::create([
            'product_id'             => $product->id,
            'setting_id'             => $settingId ?? $this->setting->id,
            'sale_price'             => $salePrice,
            'tier_1_price'           => 0,
            'tier_2_price'           => 0,
        ]);
    }

    public function test_multi_word_free_text_search_finds_product_when_tokens_partially_match_name(): void
    {
        $product = $this->createProduct(['product_name' => 'SAMSUNG GALAXY FOLD']);
        $this->createProductPrice($product);

        $component = Livewire::actingAs($this->user)
            ->test(Browser::class, ['setting' => $this->setting]);

        $component->set('q', 'SAM GAL FO');

        $products = $component->viewData('products');
        $this->assertCount(1, $products);
        $this->assertEquals($product->id, $products->first()->id);
    }

    public function test_free_text_tokens_can_match_across_different_fields(): void
    {
        $category = Category::create([
            'category_code' => 'CAT1', 
            'category_name' => 'Smartphones', 
            'created_by' => $this->user->id,
            'setting_id' => $this->setting->id
        ]);
        $brand = Brand::create([
            'name' => 'Samsung',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id
        ]);
        
        $product = $this->createProduct([
            'product_name' => 'Galaxy Fold',
            'product_code' => 'SM-F900F',
            'category_id'  => $category->id,
            'brand_id'     => $brand->id,
        ]);
        $this->createProductPrice($product);

        $component = Livewire::actingAs($this->user)
            ->test(Browser::class, ['setting' => $this->setting]);

        // Search tokens match across code, name, category, and brand
        $component->set('q', 'F900F GALA SMART SAM');

        $products = $component->viewData('products');
        $this->assertCount(1, $products);
        $this->assertEquals($product->id, $products->first()->id);
    }

    public function test_scanner_code_searches_use_whole_submitted_input(): void
    {
        $product1 = $this->createProduct(['barcode' => 'BARCODE 123']);
        $this->createProductPrice($product1);

        $baseUnit = Unit::create(['name' => 'Piece', 'short_name' => 'pc', 'operator' => '*', 'operation_value' => 1]);
        $unit = Unit::create(['name' => 'Box', 'short_name' => 'bx', 'operator' => '*', 'operation_value' => 1]);
        $product2 = $this->createProduct();
        $this->createProductPrice($product2);
        ProductUnitConversion::create([
            'product_id' => $product2->id,
            'base_unit_id' => $baseUnit->id,
            'unit_id' => $unit->id,
            'barcode' => 'CONV 456',
            'conversion_factor' => 1
        ]);

        $location = Location::create(['name' => 'Warehouse', 'setting_id' => $this->setting->id]);

        $product3 = $this->createProduct();
        $this->createProductPrice($product3);
        ProductSerialNumber::create([
            'product_id' => $product3->id,
            'location_id' => $location->id,
            'serial_number' => 'SERIAL 789'
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(Browser::class, ['setting' => $this->setting]);

        // Test barcode
        $component->set('q', 'BARCODE 123');
        $this->assertCount(1, $component->viewData('products'));
        $this->assertEquals($product1->id, $component->viewData('products')->first()->id);

        // Test conversion barcode
        $component->set('q', 'CONV 456');
        $this->assertCount(1, $component->viewData('products'));
        $this->assertEquals($product2->id, $component->viewData('products')->first()->id);

        // Test serial number
        $component->set('q', 'SERIAL 789');
        $this->assertCount(1, $component->viewData('products'));
        $this->assertEquals($product3->id, $component->viewData('products')->first()->id);
    }

    public function test_matching_products_without_active_setting_product_prices_row_remain_hidden(): void
    {
        $product = $this->createProduct(['product_name' => 'Hidden Product']);
        // Do NOT create ProductPrice for this setting

        $component = Livewire::actingAs($this->user)
            ->test(Browser::class, ['setting' => $this->setting]);

        $component->set('q', 'Hidden Product');

        $products = $component->viewData('products');
        $this->assertCount(0, $products);
    }

    public function test_stock_state_and_available_qty_are_computed_correctly_per_setting_locations(): void
    {
        $location = Location::create(['name' => 'Main Shop', 'setting_id' => $this->setting->id]);
        \Modules\Setting\Entities\SettingSaleLocation::create([
            'setting_id' => $this->setting->id,
            'location_id' => $location->id,
            'is_enabled' => true,
            'position' => 1,
        ]);
        \App\Support\SalesLocationResolver::forget($this->setting->id);

        // 1. In-stock product
        $inStockProduct = $this->createProduct([
            'product_name' => 'In Stock Item',
            'stock_managed' => true,
        ]);
        $this->createProductPrice($inStockProduct);
        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $inStockProduct->id,
            'location_id' => $location->id,
            'quantity' => 15,
            'quantity_non_tax' => 15,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        // 2. Out of stock product (quantity <= 0)
        $oosProduct = $this->createProduct([
            'product_name' => 'OOS Item',
            'stock_managed' => true,
        ]);
        $this->createProductPrice($oosProduct);
        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $oosProduct->id,
            'location_id' => $location->id,
            'quantity' => 0,
            'quantity_non_tax' => 0,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        // 3. Service product (stock_managed = false)
        $serviceProduct = $this->createProduct([
            'product_name' => 'Service Item',
            'stock_managed' => false,
            'is_sold' => true,
        ]);
        $this->createProductPrice($serviceProduct);

        $component = Livewire::actingAs($this->user)
            ->test(Browser::class, ['setting' => $this->setting]);

        $products = $component->viewData('products')->keyBy('id');

        $this->assertEquals(15, (float) $products[$inStockProduct->id]->available_qty);
        $this->assertEquals('in_stock', $products[$inStockProduct->id]->stock_state);

        $this->assertEquals(0, (float) $products[$oosProduct->id]->available_qty);
        $this->assertEquals('out_of_stock', $products[$oosProduct->id]->stock_state);

        $this->assertEquals('service', $products[$serviceProduct->id]->stock_state);

        // Verify HTML rendering contains badges and values
        $component->assertSee('Stok Kosong')
            ->assertSee('Service')
            ->assertSee('15');
    }

    public function test_unit_denominated_stock_quantity_formatting(): void
    {
        $location = Location::create(['name' => 'Storefront', 'setting_id' => $this->setting->id]);
        \Modules\Setting\Entities\SettingSaleLocation::create([
            'setting_id' => $this->setting->id,
            'location_id' => $location->id,
            'is_enabled' => true,
            'position' => 1,
        ]);
        \App\Support\SalesLocationResolver::forget($this->setting->id);

        $baseUnit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs', 'operator' => '*', 'operation_value' => 1]);
        $boxUnit = Unit::create(['name' => 'Dus', 'short_name' => 'dus', 'operator' => '*', 'operation_value' => 1]);
        $cartonUnit = Unit::create(['name' => 'Karton', 'short_name' => 'ktn', 'operator' => '*', 'operation_value' => 1]);

        // (a) Product with base unit and 1 conversion: 25 pcs with Dus (10 pcs) -> "2 dus 5 pcs"
        $prodA = $this->createProduct([
            'product_name' => 'Prod A Conversion',
            'stock_managed' => true,
            'base_unit_id' => $baseUnit->id,
        ]);
        $this->createProductPrice($prodA);
        ProductUnitConversion::create([
            'product_id' => $prodA->id,
            'base_unit_id' => $baseUnit->id,
            'unit_id' => $boxUnit->id,
            'conversion_factor' => 10,
        ]);
        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $prodA->id,
            'location_id' => $location->id,
            'quantity' => 25,
            'quantity_non_tax' => 25,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        // (b) Product with multiple conversions: Dus (10 pcs) & Karton (50 pcs), 125 pcs -> "2 ktn 25 pcs" (picks largest factor)
        $prodB = $this->createProduct([
            'product_name' => 'Prod B Multi Conversion',
            'stock_managed' => true,
            'base_unit_id' => $baseUnit->id,
        ]);
        $this->createProductPrice($prodB);
        ProductUnitConversion::create([
            'product_id' => $prodB->id,
            'base_unit_id' => $baseUnit->id,
            'unit_id' => $boxUnit->id,
            'conversion_factor' => 10,
        ]);
        ProductUnitConversion::create([
            'product_id' => $prodB->id,
            'base_unit_id' => $baseUnit->id,
            'unit_id' => $cartonUnit->id,
            'conversion_factor' => 50,
        ]);
        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $prodB->id,
            'location_id' => $location->id,
            'quantity' => 125,
            'quantity_non_tax' => 125,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        // (c) Product with base unit and no conversions: 7 pcs -> "7 pcs"
        $prodC = $this->createProduct([
            'product_name' => 'Prod C Base Unit Only',
            'stock_managed' => true,
            'base_unit_id' => $baseUnit->id,
        ]);
        $this->createProductPrice($prodC);
        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $prodC->id,
            'location_id' => $location->id,
            'quantity' => 7,
            'quantity_non_tax' => 7,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        // (d) Product with no base unit: 9 -> "9"
        $prodD = $this->createProduct([
            'product_name' => 'Prod D No Unit',
            'stock_managed' => true,
            'base_unit_id' => null,
        ]);
        $this->createProductPrice($prodD);
        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $prodD->id,
            'location_id' => $location->id,
            'quantity' => 9,
            'quantity_non_tax' => 9,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        // (e) Service product: stock_managed = false -> "-"
        $prodE = $this->createProduct([
            'product_name' => 'Prod E Service',
            'stock_managed' => false,
            'is_sold' => true,
            'base_unit_id' => $baseUnit->id,
        ]);
        $this->createProductPrice($prodE);

        $component = Livewire::actingAs($this->user)
            ->test(Browser::class, ['setting' => $this->setting]);

        $products = $component->viewData('products')->keyBy('id');

        $this->assertEquals('2 DUS 5 PCS', $products[$prodA->id]->formatted_available_qty);
        $this->assertEquals('2 KTN 25 PCS', $products[$prodB->id]->formatted_available_qty);
        $this->assertEquals('7 PCS', $products[$prodC->id]->formatted_available_qty);
        $this->assertEquals('9', $products[$prodD->id]->formatted_available_qty);

        $component->assertSee('2 DUS 5 PCS')
            ->assertSee('2 KTN 25 PCS')
            ->assertSee('7 PCS')
            ->assertSee('9')
            ->assertSee('Service');
    }
}
