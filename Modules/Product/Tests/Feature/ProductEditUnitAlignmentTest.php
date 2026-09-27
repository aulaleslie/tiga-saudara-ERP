<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use App\Models\User;
use Tests\TestCase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class ProductEditUnitAlignmentTest extends TestCase
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

    public function test_import_created_stock_managed_product_can_update_editable_price_fields_without_changing_locked_unit()
    {
        $unit = Unit::create(['name' => 'Pieces', 'short_name' => 'PCS']);
        $product = Product::create([
            'product_name' => 'Imported Product',
            'product_code' => 'SKU-123',
            'setting_id' => 1,
            'stock_managed' => 1,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_unit' => 'PCS',
            'product_cost' => 100,
            'product_price' => 200,
            'product_quantity' => 10, // Simulate stock exists so unit is locked
            'is_purchased' => 1,
            'is_sold' => 1,
        ]);

        $updateData = [
            'product_name' => 'Imported Product',
            'product_code' => 'SKU-123',
            'barcode_symbology' => 'C128',
            'is_purchased' => 1,
            'purchase_price' => 150,
            'is_sold' => 1,
            'sale_price' => 250,
            'tier_1_price' => 250,
            'tier_2_price' => 250,
            'stock_managed' => 1,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'product_note' => '',
            'category_id' => '',
            'brand_id' => '',
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id, // Sent as locked usually
            'product_unit' => 'PCS',
        ];

        $response = $this->actingAs($this->user)->put(route('products.update', $product->id), $updateData);

        $response->assertRedirect(route('products.index'));

        $product->refresh();
        $this->assertEquals(100, $product->product_cost); // Legacy remains untouched
        $this->assertEquals(200, $product->product_price); // Legacy remains untouched
        $this->assertEquals($unit->id, $product->base_unit_id);

        $priceRow = $product->prices()->where('setting_id', 1)->first();
        $this->assertNotNull($priceRow);
        $this->assertEquals(150, $priceRow->last_purchase_price);
        $this->assertEquals(250, $priceRow->sale_price);
    }
}
