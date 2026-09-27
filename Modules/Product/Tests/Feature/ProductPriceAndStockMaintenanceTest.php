<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use App\Models\User;
use Tests\TestCase;

class ProductPriceAndStockMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::factory()->create();

        \Illuminate\Support\Facades\Gate::before(fn() => true);

        $this->user = User::factory()->create([
            'email' => 'test@test.com',
        ]);
    }

    public function test_omitted_prices_are_accepted_and_default_to_zero()
    {
        $unit = Unit::create(['name' => 'Pieces', 'short_name' => 'PCS']);

        $response = $this->actingAs($this->user)->post(route('products.store'), [
            'product_name' => 'Zero Price Test',
            'product_code' => 'ZPT-1',
            'is_purchased' => 1,
            'is_sold' => 1,
            // Omitting prices
            'stock_managed' => 0,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('products.index'));

        $product = Product::where('product_code', 'ZPT-1')->first();
        $this->assertNotNull($product);

        $price = $product->prices()->first();
        $this->assertEquals(0, $price->last_purchase_price);
        $this->assertEquals(0, $price->sale_price);
        $this->assertEquals(0, $price->tier_1_price);
        $this->assertEquals(0, $price->tier_2_price);
    }

    public function test_negative_prices_are_rejected()
    {
        $response = $this->actingAs($this->user)->post(route('products.store'), [
            'product_name' => 'Negative Price Test',
            'product_code' => 'NPT-1',
            'is_purchased' => 1,
            'purchase_price' => -100,
            'is_sold' => 1,
            'sale_price' => -200,
            'tier_1_price' => -250,
            'tier_2_price' => -300,
        ]);

        $response->assertSessionHasErrors(['purchase_price', 'sale_price', 'tier_1_price', 'tier_2_price']);
    }

    public function test_successful_zero_stock_disabling_cleans_up_conversions()
    {
        $unit1 = Unit::create(['name' => 'Pieces', 'short_name' => 'PCS']);
        $unit2 = Unit::create(['name' => 'Box', 'short_name' => 'BOX']);

        $product = Product::create([
            'product_name' => 'Conversion Cleanup Test',
            'product_code' => 'CCT-1',
            'setting_id' => 1,
            'stock_managed' => 1,
            'base_unit_id' => $unit1->id,
            'is_purchased' => 1,
            'is_sold' => 1,
            'product_cost' => 0,
            'product_price' => 0,
            'purchase_price' => 0,
            'sale_price' => 0,
            'product_quantity' => 0,
        ]);

        $conv = $product->conversions()->create([
            'unit_id' => $unit2->id,
            'base_unit_id' => $unit1->id,
            'conversion_factor' => 10,
            'barcode' => 'BOX-123'
        ]);

        app(\Modules\Product\Services\BarcodeIdentityService::class)->reserve('BOX-123', null, $conv->id);

        $updateData = [
            'product_name' => 'Conversion Cleanup Test Updated',
            'is_purchased' => 1,
            'is_sold' => 1,
            'stock_managed' => 0,
            // Stale conversion payload
            'conversions' => [
                [
                    'id' => $conv->id,
                    'unit_id' => $unit2->id,
                    'conversion_factor' => 10,
                    'barcode' => 'BOX-123',
                    'price' => 500,
                ]
            ]
        ];

        $response = $this->actingAs($this->user)->put(route('products.update', $product->id), $updateData);

        $response->assertSessionHasNoErrors();
        $this->assertEquals(0, $product->fresh()->stock_managed);
        $this->assertEquals(0, $product->conversions()->count());
    }

    public function test_non_stock_managed_product_saves_price_only_update_without_base_unit()
    {
        $product = Product::create([
            'product_name' => 'Jasa Instalasi',
            'product_code' => 'SRV-EDIT-1',
            'setting_id' => 1,
            'stock_managed' => 0,
            'base_unit_id' => null,
            'is_purchased' => 1,
            'is_sold' => 1,
            'product_cost' => 0,
            'product_price' => 150000,
            'purchase_price' => 0,
            'sale_price' => 150000,
            'product_quantity' => 0,
        ]);

        // Mirrors the real Product Edit submission: the base unit field is disabled for
        // non-stock-managed products, so it arrives with no selected value (null).
        $response = $this->actingAs($this->user)->put(route('products.update', $product->id), [
            'product_name' => 'Jasa Instalasi',
            'is_purchased' => 1,
            'is_sold' => 1,
            'stock_managed' => 0,
            'base_unit_id' => null,
            'sale_price' => 0,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('product_prices', [
            'product_id' => $product->id,
            'setting_id' => 1,
            'sale_price' => 0,
        ]);
    }

    public function test_stock_managed_product_rejects_null_base_unit_on_edit()
    {
        $unit = Unit::create(['name' => 'Pieces', 'short_name' => 'PCS']);

        $product = Product::create([
            'product_name' => 'Stocked Product',
            'product_code' => 'SM-EDIT-NULL-1',
            'setting_id' => 1,
            'stock_managed' => 1,
            'base_unit_id' => $unit->id,
            'is_purchased' => 1,
            'is_sold' => 1,
            'product_cost' => 0,
            'product_price' => 100000,
            'purchase_price' => 50000,
            'sale_price' => 100000,
            'product_quantity' => 0,
        ]);

        // Attempting to update a stock-managed product with null base_unit_id should fail.
        $response = $this->actingAs($this->user)->put(route('products.update', $product->id), [
            'product_name' => 'Stocked Product Updated',
            'is_purchased' => 1,
            'is_sold' => 1,
            'stock_managed' => 1,
            'base_unit_id' => null,
        ]);

        $response->assertSessionHasErrors('base_unit_id');
    }

    public function test_positive_stock_protection_prevents_disabling_stock_management()
    {
        $unit = Unit::create(['name' => 'Pieces', 'short_name' => 'PCS']);

        $product = Product::create([
            'product_name' => 'Stocked Product',
            'product_code' => 'SP-1',
            'setting_id' => 1,
            'stock_managed' => 1,
            'base_unit_id' => $unit->id,
            'is_purchased' => 1,
            'is_sold' => 1,
            'product_cost' => 0,
            'product_price' => 0,
            'purchase_price' => 0,
            'sale_price' => 0,
            'product_quantity' => 10,
        ]);

        $location = \Modules\Setting\Entities\Location::create([
            'name' => 'Test Location',
            'setting_id' => 1
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'cost_tax' => 0,
            'cost_non_tax' => 0,
            'cost' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $updateData = [
            'product_name' => 'Stocked Product Updated',
            'is_purchased' => 1,
            'is_sold' => 1,
            'stock_managed' => 0, // Attempt to disable
        ];

        $response = $this->actingAs($this->user)->put(route('products.update', $product->id), $updateData);

        $response->assertSessionHasErrors(['stock_managed']);
        $this->assertEquals(1, $product->fresh()->stock_managed);
    }
}
