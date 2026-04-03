<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\ProductUnitConversionPrice;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class ProductUnitConversionPriceTest extends TestCase
{
    use RefreshDatabase;

    private function seedSettings(): array
    {
        $currency = Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        $primary = Setting::create([
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

        $secondary = Setting::create([
            'company_name'              => 'Beta Co',
            'company_email'             => 'beta@example.com',
            'company_phone'             => '22222',
            'site_logo'                 => null,
            'default_currency_id'       => $currency->id,
            'default_currency_position' => 'left',
            'notification_email'        => 'notify-beta@example.com',
            'footer_text'               => 'Beta',
            'company_address'           => 'Beta Street',
        ]);

        return [$primary, $secondary];
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

    public function test_product_store_creates_conversion_prices_for_all_settings(): void
    {
        [$primarySetting, $secondarySetting] = $this->seedSettings();
        $this->authenticateForAbility('products.create');

        $baseUnit       = $this->createUnit($primarySetting, 'Piece');
        $conversionUnit = $this->createUnit($primarySetting, 'Box');

        $payload = [
            'product_name'   => 'Conversion Product',
            'product_code'   => 'CONV-001',
            'stock_managed'  => true,
            'base_unit_id'   => $baseUnit->id,
            'conversions'    => [
                [
                    'unit_id'           => $conversionUnit->id,
                    'conversion_factor' => 2,
                    'price'             => 9900,
                    'barcode'           => 'BOX-9900',
                ],
            ],
            'is_purchased'   => false,
            'is_sold'        => false,
        ];

        $response = $this->withSession(['setting_id' => $primarySetting->id])
            ->post(route('products.store'), $payload, ['X-Idempotency-Token' => 'test-product-token']);

        $response->assertRedirect(route('products.index'));

        $conversion = ProductUnitConversion::first();
        $this->assertNotNull($conversion);

        $prices = ProductUnitConversionPrice::where('product_unit_conversion_id', $conversion->id)
            ->pluck('price', 'setting_id')
            ->map(fn ($value) => (float) $value)
            ->all();

        $this->assertCount(2, $prices);
        $this->assertSame(9900.0, $prices[$primarySetting->id]);
        $this->assertSame(9900.0, $prices[$secondarySetting->id]);
    }

    public function test_product_update_updates_only_active_setting_conversion_price(): void
    {
        [$primarySetting, $secondarySetting] = $this->seedSettings();
        $this->authenticateForAbility('products.edit');

        $baseUnit       = $this->createUnit($primarySetting, 'Piece');
        $conversionUnit = $this->createUnit($primarySetting, 'Pack');

        $product = Product::create([
            'product_name'       => 'Existing Product',
            'product_code'       => 'EXIST-001',
            'product_quantity'   => 0,
            'product_cost'       => 0,
            'product_price'      => 0,
            'product_stock_alert'=> 0,
            'base_unit_id'       => $baseUnit->id,
            'unit_id'            => $baseUnit->id,
            'stock_managed'      => 1,
            'is_purchased'       => 0,
            'is_sold'            => 0,
            'setting_id'         => $primarySetting->id,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id'        => $product->id,
            'unit_id'           => $conversionUnit->id,
            'base_unit_id'      => $baseUnit->id,
            'conversion_factor' => 3,
            'barcode'           => 'PACK-OLD',
        ]);

        ProductUnitConversionPrice::upsertFor([
            'product_unit_conversion_id' => $conversion->id,
            'setting_id'                 => $primarySetting->id,
            'price'                      => 15000,
        ]);
        ProductUnitConversionPrice::upsertFor([
            'product_unit_conversion_id' => $conversion->id,
            'setting_id'                 => $secondarySetting->id,
            'price'                      => 21000,
        ]);

        $payload = [
            'product_name'  => 'Existing Product',
            'product_code'  => 'EXIST-001',
            'category_id'   => null,
            'brand_id'      => null,
            'stock_managed' => true,
            'base_unit_id'  => $baseUnit->id,
            'conversions'   => [
                [
                    'id'                => $conversion->id,
                    'unit_id'           => $conversionUnit->id,
                    'conversion_factor' => 3,
                    'price'             => 17500,
                    'barcode'           => 'PACK-OLD',
                ],
            ],
            'is_purchased'  => false,
            'is_sold'       => false,
        ];

        $response = $this->withSession(['setting_id' => $primarySetting->id])
            ->put(route('products.update', $product), $payload);

        $response->assertRedirect(route('products.index'));

        $this->assertSame(17500.0, (float) ProductUnitConversionPrice::where([
            'product_unit_conversion_id' => $conversion->id,
            'setting_id'                 => $primarySetting->id,
        ])->value('price'));

        $this->assertSame(21000.0, (float) ProductUnitConversionPrice::where([
            'product_unit_conversion_id' => $conversion->id,
            'setting_id'                 => $secondarySetting->id,
        ])->value('price'));
    }

    public function test_product_store_accepts_formatted_conversion_price_payload(): void
    {
        [$primarySetting, $secondarySetting] = $this->seedSettings();
        $this->authenticateForAbility('products.create');

        $baseUnit = $this->createUnit($primarySetting, 'Piece');
        $conversionUnit = $this->createUnit($primarySetting, 'Box');

        $payload = [
            'product_name' => 'Formatted Conversion Product',
            'product_code' => 'CONV-FMT-001',
            'stock_managed' => true,
            'base_unit_id' => $baseUnit->id,
            'conversions' => [
                [
                    'unit_id' => $conversionUnit->id,
                    'conversion_factor' => 2,
                    'price' => 'RP 9.900,00',
                    'barcode' => 'BOX-9900-FMT',
                ],
            ],
            'is_purchased' => false,
            'is_sold' => false,
        ];

        $response = $this->withSession(['setting_id' => $primarySetting->id])
            ->post(route('products.store'), $payload, ['X-Idempotency-Token' => 'test-product-token-formatted']);

        $response->assertRedirect(route('products.index'));

        $conversion = ProductUnitConversion::first();
        $this->assertNotNull($conversion);

        $prices = ProductUnitConversionPrice::where('product_unit_conversion_id', $conversion->id)
            ->pluck('price', 'setting_id')
            ->map(fn ($value) => (float) $value)
            ->all();

        $this->assertCount(2, $prices);
        $this->assertSame(9900.0, $prices[$primarySetting->id]);
        $this->assertSame(9900.0, $prices[$secondarySetting->id]);
    }

    public function test_product_create_validation_round_trip_preserves_conversion_price_display_and_raw_value(): void
    {
        [$primarySetting] = $this->seedSettings();
        $this->authenticateForAbility('products.create');

        $baseUnit = $this->createUnit($primarySetting, 'Piece');
        $conversionUnit = $this->createUnit($primarySetting, 'Box');

        $payload = [
            'product_name' => '',
            'product_code' => 'CONV-ROUNDTRIP-001',
            'stock_managed' => true,
            'base_unit_id' => $baseUnit->id,
            'conversions' => [
                [
                    'unit_id' => $conversionUnit->id,
                    'conversion_factor' => 2,
                    'price' => 'RP 65.000,00',
                    'barcode' => 'BOX-ROUNDTRIP',
                ],
            ],
            'is_purchased' => false,
            'is_sold' => false,
        ];

        $response = $this->withSession(['setting_id' => $primarySetting->id])
            ->from(route('products.create'))
            ->post(route('products.store'), $payload, ['X-Idempotency-Token' => 'test-product-roundtrip-token']);

        $response
            ->assertRedirect(route('products.create'))
            ->assertSessionHasErrors(['product_name']);

        $page = $this->withSession(['setting_id' => $primarySetting->id])
            ->get(route('products.create'));

        $page->assertOk();
        $page->assertSee('name="conversions[0][price]"', false);
        $page->assertSee('value="65000"', false);
        $page->assertSee('value="RP 65.000,00"', false);
    }

    public function test_product_edit_validation_round_trip_preserves_conversion_price_display_and_raw_value(): void
    {
        [$primarySetting] = $this->seedSettings();
        $this->authenticateForAbility('products.edit');

        $baseUnit = $this->createUnit($primarySetting, 'Piece');
        $conversionUnit = $this->createUnit($primarySetting, 'Pack');

        $product = Product::create([
            'product_name' => 'Existing Product',
            'product_code' => 'EXIST-ROUNDTRIP-001',
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_price' => 0,
            'product_stock_alert' => 0,
            'base_unit_id' => $baseUnit->id,
            'unit_id' => $baseUnit->id,
            'stock_managed' => 1,
            'is_purchased' => 0,
            'is_sold' => 0,
            'setting_id' => $primarySetting->id,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $conversionUnit->id,
            'base_unit_id' => $baseUnit->id,
            'conversion_factor' => 3,
            'barcode' => 'PACK-ROUNDTRIP-OLD',
        ]);

        ProductUnitConversionPrice::upsertFor([
            'product_unit_conversion_id' => $conversion->id,
            'setting_id' => $primarySetting->id,
            'price' => 15000,
        ]);

        $payload = [
            'product_name' => '',
            'product_code' => 'EXIST-ROUNDTRIP-001',
            'stock_managed' => true,
            'base_unit_id' => $baseUnit->id,
            'conversions' => [
                [
                    'id' => $conversion->id,
                    'unit_id' => $conversionUnit->id,
                    'conversion_factor' => 3,
                    'price' => 'RP 17.500,00',
                    'barcode' => 'PACK-ROUNDTRIP-OLD',
                ],
            ],
            'is_purchased' => false,
            'is_sold' => false,
        ];

        $response = $this->withSession(['setting_id' => $primarySetting->id])
            ->from(route('products.edit', $product))
            ->put(route('products.update', $product), $payload);

        $response
            ->assertRedirect(route('products.edit', $product))
            ->assertSessionHasErrors(['product_name']);

        $page = $this->withSession(['setting_id' => $primarySetting->id])
            ->get(route('products.edit', $product));

        $page->assertOk();
        $page->assertSee('name="conversions[0][price]"', false);
        $page->assertSee('value="17500"', false);
        $page->assertSee('value="RP 17.500,00"', false);
    }
}
