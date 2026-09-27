<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;
use App\Models\User;
use Tests\TestCase;

class ManualProductPurchasePriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_registration()
    {
        $permissions = config('permissions');
        $this->assertArrayHasKey('products.manage_cross_business_prices', $permissions['Produk']);
    }

    public function test_product_creation_seeds_last_purchase_price_and_zeros_average_purchase_price()
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $user = User::where('email', 'manager@tigacom.com')->first();
        $this->actingAs($user);

        $unit = \Modules\Setting\Entities\Unit::firstOrCreate(['name' => 'Unit Test', 'short_name' => 'UT']);

        $response = $this->post(route('products.store'), [
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'is_purchased' => 1,
            'purchase_price' => 1000,
            'is_sold' => 1,
            'sale_price' => 1500,
            'tier_1_price' => 1400,
            'tier_2_price' => 1300,
            'base_unit_id' => $unit->id,
            'product_stock_alert' => 10,
        ]);

        $response->assertRedirect(route('products.index'));

        $product = Product::where('product_code', 'TEST-001')->first();
        $settingId = session('setting_id') ?? Setting::query()->min('id');

        $priceRow = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $settingId)
            ->first();

        $this->assertEquals(1000, $priceRow->last_purchase_price);
        $this->assertEquals(0, $priceRow->average_purchase_price);
    }

    public function test_product_edit_updates_last_purchase_price_and_preserves_average_purchase_price()
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $user = User::where('email', 'manager@tigacom.com')->first();
        $this->actingAs($user);

        $settingId = Setting::query()->min('id');

        $unit = \Modules\Setting\Entities\Unit::firstOrCreate(['name' => 'Unit Test 2', 'short_name' => 'UT2']);

        $product = app(\Modules\Product\Services\ProductCreator::class)->create([
            'product_name' => 'Old Product',
            'product_code' => 'TEST-002',
            'base_unit_id' => $unit->id,
            'is_purchased' => 1,
            'is_sold' => 1,
            'product_stock_alert' => 10,
        ]);

        ProductPrice::updateOrCreate(
            ['product_id' => $product->id, 'setting_id' => $settingId],
            [
                'last_purchase_price' => 500,
                'average_purchase_price' => 600,
                'sale_price' => 1000,
                'tier_1_price' => 1000,
                'tier_2_price' => 1000,
            ]
        );

        $response = $this->put(route('products.update', $product), [
            'product_name' => 'Updated Product',
            'is_purchased' => 1,
            'purchase_price' => 700,
            'is_sold' => 1,
            'sale_price' => 1500,
            'tier_1_price' => 1400,
            'tier_2_price' => 1300,
            'base_unit_id' => $product->base_unit_id,
            'product_stock_alert' => 10,
        ]);

        $response->assertRedirect(route('products.index'));

        $priceRow = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $settingId)
            ->first();

        $this->assertEquals(700, $priceRow->last_purchase_price);
        $this->assertEquals(600, $priceRow->average_purchase_price); // preserved
    }
}
