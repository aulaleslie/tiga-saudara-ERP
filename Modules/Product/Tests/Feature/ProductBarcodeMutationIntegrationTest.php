<?php

namespace Modules\Product\Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\BarcodeIdentity;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Utils\BarcodeUtils;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class ProductBarcodeMutationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function createSetting(): Setting
    {
        $currency = Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        return Setting::create([
            'company_name'              => 'Alpha Co',
            'company_email'             => 'alpha@example.com',
            'company_phone'             => '11111',
            'site_logo'                 => null,
            'default_currency_id'       => $currency->id,
            'default_currency_position' => 'left',
            'notification_email'        => 'notify-alpha@example.com',
            'footer_text'               => 'Alpha',
            'company_address'           => 'Alpha Street',
        ]);
    }

    private function createUnit(Setting $setting, string $name): Unit
    {
        return Unit::create([
            'name'       => $name,
            'short_name' => substr($name, 0, 3),
            'operator'   => null,
            'operation_value' => null,
            'setting_id' => $setting->id,
        ]);
    }

    private function authenticateForAbility(string $ability): void
    {
        Gate::shouldReceive('denies')->withAnyArgs()->andReturnFalse();
        Gate::shouldReceive('allows')->withAnyArgs()->andReturnTrue();
        Gate::shouldReceive('check')->withAnyArgs()->andReturnTrue();
        Gate::shouldReceive('any')->withAnyArgs()->andReturnTrue();
        Gate::shouldReceive('denies')->with($ability)->andReturnFalse();
        Gate::shouldReceive('allows')->with($ability)->andReturnTrue();

        $user = User::factory()->create();
        $this->actingAs($user);
        $this->withoutMiddleware([CheckUserRoleForSetting::class]);
    }

    public function test_product_store_reserves_barcode_and_conversions(): void
    {
        $setting = $this->createSetting();
        $this->authenticateForAbility('products.create');

        $baseUnit = $this->createUnit($setting, 'Piece');
        $conversionUnit = $this->createUnit($setting, 'Box');

        $payload = [
            'product_name'   => 'Test Barcode Product',
            'product_code'   => 'BC-001',
            'barcode'        => '1234567890',
            'stock_managed'  => true,
            'base_unit_id'   => $baseUnit->id,
            'is_purchased'   => false,
            'is_sold'        => false,
            'conversions'    => [
                [
                    'unit_id'           => $conversionUnit->id,
                    'conversion_factor' => 2,
                    'price'             => 100,
                    'barcode'           => '9876543210',
                ],
            ],
        ];

        $response = $this->withSession(['setting_id' => $setting->id])
            ->post(route('products.store'), $payload);

        $response->assertRedirect(route('products.index'));

        $product = Product::where('product_code', 'BC-001')->first();
        $this->assertNotNull($product);

        $identity = BarcodeIdentity::where('value', '1234567890')->first();
        $this->assertNotNull($identity);
        $this->assertEquals($product->id, $identity->product_id);

        $conversion = ProductUnitConversion::where('product_id', $product->id)->first();
        $this->assertNotNull($conversion);

        $convIdentity = BarcodeIdentity::where('value', '9876543210')->first();
        $this->assertNotNull($convIdentity);
        $this->assertEquals($conversion->id, $convIdentity->product_unit_conversion_id);
    }

    public function test_product_update_replaces_barcode_and_handles_deleted_conversions(): void
    {
        $setting = $this->createSetting();
        $this->authenticateForAbility('products.edit');

        $baseUnit = $this->createUnit($setting, 'Piece');
        $conversionUnit = $this->createUnit($setting, 'Box');

        $product = Product::create([
            'product_name'       => 'Existing Product',
            'product_code'       => 'EXIST-001',
            'barcode'            => 'OLD-BARCODE',
            'product_quantity'   => 0,
            'product_cost'       => 0,
            'product_price'      => 0,
            'product_stock_alert'=> 0,
            'base_unit_id'       => $baseUnit->id,
            'unit_id'            => $baseUnit->id,
            'stock_managed'      => 1,
            'is_purchased'       => 0,
            'is_sold'            => 0,
            'setting_id'         => $setting->id,
        ]);

        BarcodeIdentity::create([
            'canonical_key' => BarcodeUtils::canonicalize('OLD-BARCODE'),
            'value'         => 'OLD-BARCODE',
            'product_id'    => $product->id,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id'        => $product->id,
            'unit_id'           => $conversionUnit->id,
            'base_unit_id'      => $baseUnit->id,
            'conversion_factor' => 3,
            'barcode'           => 'OLD-CONV-BARCODE',
        ]);

        BarcodeIdentity::create([
            'canonical_key' => BarcodeUtils::canonicalize('OLD-CONV-BARCODE'),
            'value'         => 'OLD-CONV-BARCODE',
            'product_unit_conversion_id' => $conversion->id,
        ]);

        $payload = [
            'product_name'  => 'Existing Product',
            'product_code'  => 'EXIST-001',
            'barcode'       => 'NEW-BARCODE',
            'category_id'   => null,
            'brand_id'      => null,
            'stock_managed' => true,
            'base_unit_id'  => $baseUnit->id,
            'conversions'   => [], // Empty to delete the conversion
            'is_purchased'  => false,
            'is_sold'       => false,
        ];

        $response = $this->withSession(['setting_id' => $setting->id])
            ->put(route('products.update', $product), $payload);

        $response->assertRedirect(route('products.index'));

        $this->assertEquals('NEW-BARCODE', $product->fresh()->barcode);

        $this->assertDatabaseMissing('barcode_identities', ['value' => 'OLD-BARCODE']);
        $this->assertDatabaseHas('barcode_identities', ['value' => 'NEW-BARCODE', 'product_id' => $product->id]);

        $this->assertDatabaseMissing('barcode_identities', ['value' => 'OLD-CONV-BARCODE']);
    }

    public function test_quick_add_barcode_persistence()
    {
        $setting = $this->createSetting();
        $this->authenticateForAbility('products.create');
        $unit = $this->createUnit($setting, 'Piece');

        \Livewire\Livewire::test(\App\Livewire\Modules\Product\Modals\ProductQuickAddModal::class)
            ->call('openModal', ['context' => 'purchase'])
            ->set('product_name', 'Quick Add')
            ->set('product_code', 'QA01')
            ->set('base_unit_id', $unit->id)
            ->set('barcode', 'QA12345')
            ->set('purchase_price', 800)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('products', [
            'product_code' => 'QA01',
            'barcode' => 'QA12345',
        ]);
        $this->assertDatabaseHas('barcode_identities', [
            'canonical_key' => 'qa12345',
        ]);
    }

    public function test_conversion_updates_deletions()
    {
        $setting = $this->createSetting();
        $this->authenticateForAbility('products.edit');
        $unit = $this->createUnit($setting, 'Piece');
        $unit2 = $this->createUnit($setting, 'Box');
        $category = \Modules\Product\Entities\Category::create(['category_code' => 'C01', 'category_name' => 'Cat', 'created_by' => auth()->id(), 'setting_id' => $setting->id]);
        $brand = \Modules\Product\Entities\Brand::create(['name' => 'Brand', 'created_by' => auth()->id(), 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'Prod',
            'product_code' => 'PR01',
            'base_unit_id' => $unit->id,
            'setting_id' => $setting->id,
            'barcode' => null,
            'product_price' => 100,
            'product_cost' => 80,
            'stock_managed' => true,
        ]);

        // Add conversion
        // Let's assume the controller route doesn't exist separately but is handled via product update
        // We'll just test the mutation of product with conversions
        $response = $this->withSession(['setting_id' => $setting->id])
            ->put(route('products.update', $product), [
            'product_name' => 'Prod',
            'product_code' => 'PR01',
            'base_unit_id' => $unit->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'stock_managed' => true,
            'conversions' => [
                [
                    'unit_id' => $unit2->id,
                    'conversion_factor' => 10,
                    'price' => 1000,
                    'barcode' => 'CONV123'
                ]
            ]
        ]);

        $this->assertDatabaseHas('barcode_identities', [
            'canonical_key' => 'conv123',
        ]);

        // Update conversion
        $response = $this->withSession(['setting_id' => $setting->id])
            ->put(route('products.update', $product), [
            'product_name' => 'Prod',
            'product_code' => 'PR01',
            'base_unit_id' => $unit->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'stock_managed' => true,
            'conversions' => [
                [
                    'unit_id' => $unit2->id,
                    'conversion_factor' => 10,
                    'price' => 1000,
                    'barcode' => 'CONV456'
                ]
            ]
        ]);

        $this->assertDatabaseMissing('barcode_identities', [
            'canonical_key' => 'conv123',
        ]);
        $this->assertDatabaseHas('barcode_identities', [
            'canonical_key' => 'conv456',
        ]);

        // Delete conversion (empty conversions array)
        $response = $this->withSession(['setting_id' => $setting->id])
            ->put(route('products.update', $product), [
            'product_name' => 'Prod',
            'product_code' => 'PR01',
            'base_unit_id' => $unit->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'stock_managed' => true,
            'conversions' => []
        ]);

        $this->assertDatabaseMissing('barcode_identities', [
            'canonical_key' => 'conv456',
        ]);
    }
}
