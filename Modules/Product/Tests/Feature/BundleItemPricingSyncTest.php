<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class BundleItemPricingSyncTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Product $parentProduct;
    protected Product $itemProduct;

    public function setUp(): void
    {
        parent::setUp();

        // Create settings with minimal required fields (following ProductBundlePricingTest pattern)
        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'default_currency_id' => 1,
            'default_currency_position' => 'left',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Test Footer',
            'company_address' => 'Test Address',
        ]);
        Session::put('setting_id', $this->setting->id);

        // Create products with required fields (following ProductBundlePricingTest pattern)
        $this->parentProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Parent Product',
            'product_code' => 'PARENT-001',
            'product_cost' => 0,
            'product_price' => 0,
            'product_quantity' => 0,
            'product_unit' => 'pc',
        ]);
        $this->itemProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Item Product',
            'product_code' => 'ITEM-001',
            'product_cost' => 0,
            'product_price' => 0,
            'product_quantity' => 0,
            'product_unit' => 'pc',
        ]);
    }

    protected function createSetting($name): Setting
    {
        return Setting::create([
            'company_name' => "Setting-$name",
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'default_currency_id' => 1,
            'default_currency_position' => 'left',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Test Footer',
            'company_address' => 'Test Address',
        ]);
    }

    /**
     * Test that item informational price defaults from product_prices.sale_price
     * for active setting when product is selected.
     */
    public function test_informational_item_price_defaults_from_sale_price()
    {
        $salePrice = 50000;
        ProductPrice::create([
            'product_id' => $this->itemProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => $salePrice,
            'last_purchase_price' => 30000,
        ]);

        // Verify the price was actually created
        $price = ProductPrice::where('product_id', $this->itemProduct->id)
            ->where('setting_id', $this->setting->id)
            ->first();
        $this->assertNotNull($price, 'ProductPrice should exist');
        $this->assertEquals($salePrice, $price->sale_price);

        $component = Livewire::test('product.bundle-table', [
            'productId' => $this->parentProduct->id,
        ]);

        // Simulate product selection
        $component->call('updateProductRow', [
            'index' => 'bundle_0',
            'product' => [
                'id' => $this->itemProduct->id,
                'product_name' => $this->itemProduct->product_name,
            ],
        ]);

        // Assert the informational_item_price was set to the sale price
        $this->assertArrayHasKey('informational_item_price', $component->instance()->items[0]);
        $this->assertEquals((float) $salePrice, $component->instance()->items[0]['informational_item_price']);
    }

    /**
     * Test that item informational price is null when no sale price exists for setting.
     */
    public function test_informational_item_price_null_when_no_sale_price()
    {
        // Product exists but no ProductPrice for this setting
        $component = Livewire::test('product.bundle-table', [
            'productId' => $this->parentProduct->id,
        ]);

        $component->call('updateProductRow', [
            'index' => 'bundle_0',
            'product' => [
                'id' => $this->itemProduct->id,
                'product_name' => $this->itemProduct->product_name,
            ],
        ]);

        $this->assertNull($component->instance()->items[0]['informational_item_price']);
    }

    /**
     * Test that changing product selection updates the informational price immediately.
     */
    public function test_changing_product_updates_informational_price()
    {
        $firstSalePrice = 30000;
        $secondProduct = Product::create(['product_name' => 'Test Product 2', 'product_cost' => 0, 'product_price' => 0, 'product_quantity' => 0, 'setting_id' => $this->setting->id]);
        $secondSalePrice = 60000;

        ProductPrice::create([
            'product_id' => $this->itemProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => $firstSalePrice,
            'purchase_price' => 20000,
        ]);

        ProductPrice::create([
            'product_id' => $secondProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => $secondSalePrice,
            'purchase_price' => 40000,
        ]);

        $component = Livewire::test('product.bundle-table', [
            'productId' => $this->parentProduct->id,
        ]);

        // Select first product
        $component->call('updateProductRow', [
            'index' => 'bundle_0',
            'product' => [
                'id' => $this->itemProduct->id,
                'product_name' => $this->itemProduct->product_name,
            ],
        ]);

        $this->assertEquals((float) $firstSalePrice, $component->instance()->items[0]['informational_item_price']);

        // Change to second product
        $component->call('updateProductRow', [
            'index' => 'bundle_0',
            'product' => [
                'id' => $secondProduct->id,
                'product_name' => $secondProduct->product_name,
            ],
        ]);

        $this->assertEquals((float) $secondSalePrice, $component->instance()->items[0]['informational_item_price']);
    }

    /**
     * Test that Livewire state retains informational price across morphs.
     * This verifies the data layer is stable (not testing UI).
     */
    public function test_informational_price_persists_across_component_updates()
    {
        $salePrice = 45000;
        ProductPrice::create([
            'product_id' => $this->itemProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => $salePrice,
            'purchase_price' => 25000,
        ]);

        $component = Livewire::test('product.bundle-table', [
            'productId' => $this->parentProduct->id,
        ]);

        $component->call('updateProductRow', [
            'index' => 'bundle_0',
            'product' => [
                'id' => $this->itemProduct->id,
                'product_name' => $this->itemProduct->product_name,
            ],
        ]);

        $initialPrice = $component->instance()->items[0]['informational_item_price'];

        // Simulate quantity change (which triggers a render/morph)
        $component->set('items.0.quantity', 5);

        // Price should still be there after morph
        $this->assertEquals($initialPrice, $component->instance()->items[0]['informational_item_price']);
    }

    /**
     * Test that price resolves correctly for different settings.
     */
    public function test_price_resolves_for_correct_setting()
    {
        $setting2 = $this->createSetting('secondary');
        $price1 = 20000;
        $price2 = 25000;

        ProductPrice::create([
            'product_id' => $this->itemProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => $price1,
            'purchase_price' => 15000,
        ]);

        ProductPrice::create([
            'product_id' => $this->itemProduct->id,
            'setting_id' => $setting2->id,
            'sale_price' => $price2,
            'purchase_price' => 18000,
        ]);

        // With setting 1
        $component = Livewire::test('product.bundle-table', [
            'productId' => $this->parentProduct->id,
        ]);

        $component->call('updateProductRow', [
            'index' => 'bundle_0',
            'product' => [
                'id' => $this->itemProduct->id,
                'product_name' => $this->itemProduct->product_name,
            ],
        ]);

        $this->assertEquals((float) $price1, $component->instance()->items[0]['informational_item_price']);

        // Switch to setting 2
        session(['setting_id' => $setting2->id]);

        $component2 = Livewire::test('product.bundle-table', [
            'productId' => $this->parentProduct->id,
        ]);

        $component2->call('updateProductRow', [
            'index' => 'bundle_0',
            'product' => [
                'id' => $this->itemProduct->id,
                'product_name' => $this->itemProduct->product_name,
            ],
        ]);

        $this->assertEquals((float) $price2, $component2->instance()->items[0]['informational_item_price']);
    }

    /**
     * Test that adding multiple items each gets their own price.
     */
    public function test_multiple_items_each_get_correct_price()
    {
        $product2 = Product::create(['product_name' => 'Test Product 3', 'product_cost' => 0, 'product_price' => 0, 'product_quantity' => 0, 'setting_id' => $this->setting->id]);
        $price1 = 15000;
        $price2 = 35000;

        ProductPrice::create([
            'product_id' => $this->itemProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => $price1,
            'purchase_price' => 10000,
        ]);

        ProductPrice::create([
            'product_id' => $product2->id,
            'setting_id' => $this->setting->id,
            'sale_price' => $price2,
            'purchase_price' => 25000,
        ]);

        $component = Livewire::test('product.bundle-table', [
            'productId' => $this->parentProduct->id,
        ]);

        // Add second item
        $component->call('addItem');

        // Select products for both items
        $component->call('updateProductRow', [
            'index' => 'bundle_0',
            'product' => [
                'id' => $this->itemProduct->id,
                'product_name' => $this->itemProduct->product_name,
            ],
        ]);

        $component->call('updateProductRow', [
            'index' => 'bundle_1',
            'product' => [
                'id' => $product2->id,
                'product_name' => $product2->product_name,
            ],
        ]);

        $this->assertEquals((float) $price1, $component->instance()->items[0]['informational_item_price']);
        $this->assertEquals((float) $price2, $component->instance()->items[1]['informational_item_price']);
    }
}
