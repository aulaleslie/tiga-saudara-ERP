<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class ProductStockAlertEditTest extends TestCase
{
    use RefreshDatabase;

    private Setting $settingA;
    private Setting $settingB;
    private User $user;
    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Gate::before(fn () => true);

        $this->settingA = Setting::factory()->create(['company_name' => 'Business A']);
        $this->settingB = Setting::factory()->create(['company_name' => 'Business B']);

        $this->user = User::factory()->create();

        $this->unit = Unit::create([
            'name'       => 'Pieces',
            'short_name' => 'PCS',
            'setting_id' => $this->settingA->id,
        ]);
    }

    public function test_submitting_new_threshold_for_product_with_existing_stock_updates_global_product_without_creating_setting_specific_threshold(): void
    {
        $product = Product::create([
            'product_name'        => 'Stocked Widget',
            'product_code'        => 'WID-001',
            'setting_id'          => $this->settingA->id,
            'base_unit_id'        => $this->unit->id,
            'unit_id'             => $this->unit->id,
            'stock_managed'       => 1,
            'is_purchased'        => 1,
            'is_sold'             => 1,
            'product_cost'        => 0,
            'product_price'       => 0,
            'product_stock_alert' => 5,
        ]);

        $location = Location::create([
            'name'       => 'Warehouse A',
            'setting_id' => $this->settingA->id,
            'is_active'  => true,
        ]);

        ProductStock::create([
            'product_id'              => $product->id,
            'location_id'             => $location->id,
            'quantity'                => 20,
            'quantity_non_tax'        => 20,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        $updatePayload = [
            'product_name'        => 'Stocked Widget',
            'product_code'        => 'WID-001',
            'barcode_symbology'   => 'C128',
            'base_unit_id'        => $this->unit->id,
            'unit_id'             => $this->unit->id,
            'stock_managed'       => 1,
            'product_stock_alert' => 15,
            'is_purchased'        => 1,
            'purchase_price'      => 500,
            'is_sold'             => 1,
            'sale_price'          => 1000,
            'tier_1_price'        => 1000,
            'tier_2_price'        => 1000,
        ];

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->settingA->id])
            ->put(route('products.update', $product->id), $updatePayload);

        $response->assertRedirect(route('products.index'));

        $product->refresh();
        $this->assertEquals(15, $product->product_stock_alert);

        // Verify that products table holds the single authoritative stock alert
        $this->assertDatabaseHas('products', [
            'id'                  => $product->id,
            'product_stock_alert' => 15,
        ]);

        // Verify product price row does not contain any stock alert column
        $priceRow = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settingA->id)
            ->first();
        $this->assertNotNull($priceRow);
        $this->assertEquals(1000, $priceRow->sale_price);
        $this->assertArrayNotHasKey('product_stock_alert', $priceRow->getAttributes());
    }

    public function test_threshold_validation_rejects_negative_values(): void
    {
        $product = Product::create([
            'product_name'        => 'Stocked Widget',
            'product_code'        => 'WID-002',
            'setting_id'          => $this->settingA->id,
            'base_unit_id'        => $this->unit->id,
            'unit_id'             => $this->unit->id,
            'stock_managed'       => 1,
            'product_cost'        => 0,
            'product_price'       => 0,
            'product_stock_alert' => 10,
        ]);

        $updatePayload = [
            'product_name'        => 'Stocked Widget',
            'product_code'        => 'WID-002',
            'base_unit_id'        => $this->unit->id,
            'stock_managed'       => 1,
            'product_stock_alert' => -5,
        ];

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->settingA->id])
            ->put(route('products.update', $product->id), $updatePayload);

        $response->assertSessionHasErrors(['product_stock_alert']);
        $this->assertEquals(10, $product->fresh()->product_stock_alert);
    }

    public function test_threshold_update_preserves_price_scoping_to_active_business_and_protects_structural_rules(): void
    {
        $product = Product::create([
            'product_name'            => 'Scoped Widget',
            'product_code'            => 'WID-003',
            'setting_id'              => $this->settingA->id,
            'base_unit_id'            => $this->unit->id,
            'unit_id'                 => $this->unit->id,
            'stock_managed'           => 1,
            'serial_number_required'  => false,
            'is_purchased'            => 1,
            'is_sold'                 => 1,
            'product_cost'            => 0,
            'product_price'           => 0,
            'product_stock_alert'     => 5,
        ]);

        // Seed initial prices for both businesses
        ProductPrice::updateOrCreate(
            ['product_id' => $product->id, 'setting_id' => $this->settingA->id],
            ['sale_price' => 100, 'last_purchase_price' => 50, 'tier_1_price' => 100, 'tier_2_price' => 100]
        );
        ProductPrice::updateOrCreate(
            ['product_id' => $product->id, 'setting_id' => $this->settingB->id],
            ['sale_price' => 200, 'last_purchase_price' => 90, 'tier_1_price' => 200, 'tier_2_price' => 200]
        );

        $location = Location::create([
            'name'       => 'Warehouse A',
            'setting_id' => $this->settingA->id,
            'is_active'  => true,
        ]);

        ProductStock::create([
            'product_id'              => $product->id,
            'location_id'             => $location->id,
            'quantity'                => 10,
            'quantity_non_tax'        => 10,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        // Submit update under Business A session changing price and stock alert
        $updatePayload = [
            'product_name'        => 'Scoped Widget Updated',
            'product_code'        => 'WID-003',
            'base_unit_id'        => $this->unit->id,
            'unit_id'             => $this->unit->id,
            'stock_managed'       => 1,
            'product_stock_alert' => 12,
            'is_purchased'        => 1,
            'purchase_price'      => 60,
            'is_sold'             => 1,
            'sale_price'          => 120,
            'tier_1_price'        => 120,
            'tier_2_price'        => 120,
        ];

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->settingA->id])
            ->put(route('products.update', $product->id), $updatePayload);

        $response->assertRedirect(route('products.index'));

        // Shared threshold is updated
        $product->refresh();
        $this->assertEquals(12, $product->product_stock_alert);

        // Business A price updated
        $priceA = ProductPrice::where('product_id', $product->id)->where('setting_id', $this->settingA->id)->first();
        $this->assertEquals(120, $priceA->sale_price);
        $this->assertEquals(60, $priceA->last_purchase_price);

        // Business B price remains intact
        $priceB = ProductPrice::where('product_id', $product->id)->where('setting_id', $this->settingB->id)->first();
        $this->assertEquals(200, $priceB->sale_price);
        $this->assertEquals(90, $priceB->last_purchase_price);

        // Verify structural serial tracking lock is still enforced when stock exists
        $structuralLockPayload = array_merge($updatePayload, [
            'serial_number_required' => true,
        ]);

        $guardResponse = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->settingA->id])
            ->put(route('products.update', $product->id), $structuralLockPayload);

        $guardResponse->assertSessionHasErrors(['serial_number_required']);
        $this->assertFalse((bool) $product->fresh()->serial_number_required);
    }
}
