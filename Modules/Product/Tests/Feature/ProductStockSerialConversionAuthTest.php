<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductStockSerialConversionAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'products.convert_existing_stock_to_serialized', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'products.edit', 'guard_name' => 'web']);
    }

    public function test_authorized_user_can_access_conversion_page()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('products.convert_existing_stock_to_serialized');

        $response = $this->actingAs($user)->get(route('products.convert-to-serialized.show'));

        $response->assertStatus(200);
        $response->assertViewIs('product::products.convert-to-serialized');
    }

    public function test_unauthorized_user_cannot_access_conversion_page()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('products.convert-to-serialized.show'));

        $response->assertStatus(403);
    }

    public function test_generic_product_update_guard_rejects_false_to_true_serial_tracking_when_stock_exists()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('products.edit');

        $setting = Setting::create([
            'company_name' => 'Cabang Test',
            'company_email' => 'test@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'test@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Test',
            'company_address' => 'Test Address',
        ]);
        $location = Location::create(['name' => 'Gudang Utama', 'setting_id' => $setting->id, 'is_active' => true]);

        $product = Product::create([
            'product_name' => 'Kamera Canon DSLR',
            'product_code' => 'CAM-001',
            'setting_id' => $setting->id,
            'base_unit_id' => 1,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $response = $this->actingAs($user)->put(route('products.update', $product->id), [
            'product_name' => 'Kamera Canon DSLR Updated',
            'product_code' => 'CAM-001',
            'base_unit_id' => 1,
            'stock_managed' => true,
            'serial_number_required' => true,
        ]);

        $response->assertSessionHasErrors(['serial_number_required']);
        $this->assertFalse((bool) $product->fresh()->serial_number_required);
    }

    public function test_generic_product_update_guard_rejects_conversion_when_broken_stock_only_exists()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('products.edit');

        $setting = Setting::create([
            'company_name' => 'Cabang Test Broken',
            'company_email' => 'broken@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'broken@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Broken',
            'company_address' => 'Broken Address',
        ]);
        $location = Location::create(['name' => 'Gudang Rusak', 'setting_id' => $setting->id, 'is_active' => true]);

        $product = Product::create([
            'product_name' => 'Produk Rusak Saja',
            'product_code' => 'BRK-001',
            'setting_id' => $setting->id,
            'base_unit_id' => 1,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 2,
            'quantity_non_tax' => 0,
            'quantity_tax' => 0,
            'broken_quantity' => 2,
            'broken_quantity_non_tax' => 2,
            'broken_quantity_tax' => 0,
        ]);

        $response = $this->actingAs($user)->put(route('products.update', $product->id), [
            'product_name' => 'Produk Rusak Saja Updated',
            'product_code' => 'BRK-001',
            'base_unit_id' => 1,
            'stock_managed' => true,
            'serial_number_required' => true,
        ]);

        $response->assertSessionHasErrors(['serial_number_required']);
        $this->assertFalse((bool) $product->fresh()->serial_number_required);
    }

    public function test_get_form_submission_with_product_id_query_param_loads_selected_product_conversion_page()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('products.convert_existing_stock_to_serialized');

        $setting = Setting::create([
            'company_name' => 'Form GET Setting',
            'company_email' => 'get@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'get@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'GET',
            'company_address' => 'GET Address',
        ]);
        $location = Location::create(['name' => 'Gudang GET', 'setting_id' => $setting->id, 'is_active' => true]);

        $product = Product::create([
            'product_name' => 'Selected GET Product',
            'product_code' => 'SGP-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 3,
            'quantity_non_tax' => 3,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $response = $this->actingAs($user)->get(route('products.convert-to-serialized.show', ['product_id' => $product->id]));

        $response->assertStatus(200);
        $response->assertViewIs('product::products.convert-to-serialized');
        $response->assertSee('Selected GET Product');
        $response->assertSee('SGP-001');
    }
}
