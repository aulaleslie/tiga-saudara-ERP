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

class ProductConversionValidationRoundTripTest extends TestCase
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

        return [$primary];
    }

    private function createUnit(Setting $setting, string $name): Unit
    {
        return Unit::create([
            'name'            => $name,
            'short_name'      => substr($name, 0, 3),
            'operator'        => null,
            'operation_value' => null,
            'setting_id'      => $setting->id,
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

        Gate::shouldReceive('forUser')->withAnyArgs()->andReturnSelf();

        $user = User::factory()->create();
        $this->actingAs($user);
        $this->withoutMiddleware([CheckUserRoleForSetting::class]);
    }

    public function test_product_create_validation_round_trip_restores_surviving_conversions_after_row_removal_and_keeps_removed_absent(): void
    {
        [$primarySetting] = $this->seedSettings();
        $this->authenticateForAbility('products.create');

        $baseUnit = $this->createUnit($primarySetting, 'Piece');
        $boxUnit = $this->createUnit($primarySetting, 'Box');
        $cartonUnit = $this->createUnit($primarySetting, 'Carton');
        $palletUnit = $this->createUnit($primarySetting, 'Pallet');

        // Payload simulating that Carton (middle row) was removed before submit,
        // leaving surviving rows at compacted indexes 0 (Box) and 1 (Pallet).
        $payload = [
            'product_name'   => '', // Triggers validation error
            'product_code'   => 'CONV-CREATE-RT',
            'stock_managed'  => true,
            'base_unit_id'   => $baseUnit->id,
            'is_purchased'   => false,
            'is_sold'        => false,
            'conversions'    => [
                [
                    'unit_id'           => $boxUnit->id,
                    'conversion_factor' => 10,
                    'barcode'           => 'BC-BOX',
                    'price'             => 'RP 25.000,00',
                    'sales_enabled'     => '1',
                    'purchase_enabled'  => '0',
                ],
                [
                    'unit_id'           => $palletUnit->id,
                    'conversion_factor' => 100,
                    'barcode'           => 'BC-PALLET',
                    'price'             => 'RP 250.000,00',
                    'sales_enabled'     => '0',
                    'purchase_enabled'  => '1',
                ],
            ],
        ];

        $response = $this->withSession(['setting_id' => $primarySetting->id])
            ->from(route('products.create'))
            ->post(route('products.store'), $payload, ['X-Idempotency-Token' => 'token-create-rt']);

        $response
            ->assertRedirect(route('products.create'))
            ->assertSessionHasErrors(['product_name']);

        $page = $this->withSession(['setting_id' => $primarySetting->id])
            ->get(route('products.create'));

        $page->assertOk();
        $content = $page->getContent();

        // Removed conversion index 2 must NOT exist
        $this->assertStringNotContainsString('name="conversions[2]', $content);

        // Surviving row 0 (Box) restored with canonical price and explicit purchase_enabled=false
        $this->assertStringContainsString('name="conversions[0][unit_id]" value="' . $boxUnit->id . '"', $content);
        $this->assertStringContainsString('name="conversions[0][conversion_factor]"', $content);
        $this->assertStringContainsString('value="10"', $content);
        $this->assertStringContainsString('name="conversions[0][barcode]"', $content);
        $this->assertStringContainsString('value="BC-BOX"', $content);
        $this->assertStringContainsString('name="conversions[0][price]"', $content);
        $this->assertStringContainsString('value="25000"', $content);

        // Surviving row 1 (Pallet) restored with canonical price and explicit sales_enabled=false
        $this->assertStringContainsString('name="conversions[1][unit_id]" value="' . $palletUnit->id . '"', $content);
        $this->assertStringContainsString('name="conversions[1][conversion_factor]"', $content);
        $this->assertStringContainsString('value="100"', $content);
        $this->assertStringContainsString('name="conversions[1][barcode]"', $content);
        $this->assertStringContainsString('value="BC-PALLET"', $content);
        $this->assertStringContainsString('name="conversions[1][price]"', $content);
        $this->assertStringContainsString('value="250000"', $content);

        // Verify restored enablement flags scoped to each surviving row
        preg_match('/<input[^>]*type="checkbox"[^>]*name="conversions\[0\]\[sales_enabled\]"[^>]*>/s', $content, $m0Sale);
        $this->assertNotEmpty($m0Sale, 'Row 0 sales_enabled checkbox must exist');
        $this->assertStringContainsString('checked', $m0Sale[0], 'Row 0 sales_enabled must be checked');

        preg_match('/<input[^>]*type="checkbox"[^>]*name="conversions\[0\]\[purchase_enabled\]"[^>]*>/s', $content, $m0Pur);
        $this->assertNotEmpty($m0Pur, 'Row 0 purchase_enabled checkbox must exist');
        $this->assertStringNotContainsString('checked', $m0Pur[0], 'Row 0 purchase_enabled must NOT be checked');

        preg_match('/<input[^>]*type="checkbox"[^>]*name="conversions\[1\]\[sales_enabled\]"[^>]*>/s', $content, $m1Sale);
        $this->assertNotEmpty($m1Sale, 'Row 1 sales_enabled checkbox must exist');
        $this->assertStringNotContainsString('checked', $m1Sale[0], 'Row 1 sales_enabled must NOT be checked');

        preg_match('/<input[^>]*type="checkbox"[^>]*name="conversions\[1\]\[purchase_enabled\]"[^>]*>/s', $content, $m1Pur);
        $this->assertNotEmpty($m1Pur, 'Row 1 purchase_enabled checkbox must exist');
        $this->assertStringContainsString('checked', $m1Pur[0], 'Row 1 purchase_enabled must be checked');
    }

    public function test_product_edit_validation_round_trip_restores_surviving_saved_conversions_after_row_removal_and_keeps_removed_absent(): void
    {
        [$primarySetting] = $this->seedSettings();
        $this->authenticateForAbility('products.edit');

        $baseUnit = $this->createUnit($primarySetting, 'Piece');
        $boxUnit = $this->createUnit($primarySetting, 'Box');
        $cartonUnit = $this->createUnit($primarySetting, 'Carton');
        $palletUnit = $this->createUnit($primarySetting, 'Pallet');

        $product = Product::create([
            'product_name'        => 'Existing Product',
            'product_code'        => 'EXIST-EDIT-RT',
            'product_quantity'    => 0,
            'product_cost'        => 0,
            'product_price'       => 0,
            'product_stock_alert' => 0,
            'base_unit_id'        => $baseUnit->id,
            'unit_id'             => $baseUnit->id,
            'stock_managed'       => 1,
            'is_purchased'        => 0,
            'is_sold'             => 0,
            'setting_id'          => $primarySetting->id,
        ]);

        $conv1 = ProductUnitConversion::create([
            'product_id'        => $product->id,
            'unit_id'           => $boxUnit->id,
            'base_unit_id'      => $baseUnit->id,
            'conversion_factor' => 2,
            'barcode'           => 'EXIST-BC-1',
        ]);
        ProductUnitConversionPrice::upsertFor([
            'product_unit_conversion_id' => $conv1->id,
            'setting_id'                 => $primarySetting->id,
            'price'                      => 15000,
        ]);

        $conv2 = ProductUnitConversion::create([
            'product_id'        => $product->id,
            'unit_id'           => $cartonUnit->id,
            'base_unit_id'      => $baseUnit->id,
            'conversion_factor' => 12,
            'barcode'           => 'EXIST-BC-2',
        ]);
        ProductUnitConversionPrice::upsertFor([
            'product_unit_conversion_id' => $conv2->id,
            'setting_id'                 => $primarySetting->id,
            'price'                      => 80000,
        ]);

        $conv3 = ProductUnitConversion::create([
            'product_id'        => $product->id,
            'unit_id'           => $palletUnit->id,
            'base_unit_id'      => $baseUnit->id,
            'conversion_factor' => 120,
            'barcode'           => 'EXIST-BC-3',
        ]);
        ProductUnitConversionPrice::upsertFor([
            'product_unit_conversion_id' => $conv3->id,
            'setting_id'                 => $primarySetting->id,
            'price'                      => 750000,
        ]);

        // Payload simulating removal of Conv2 (carton), leaving Conv1 (at index 0) and Conv3 (at index 1)
        $payload = [
            'product_name'   => '', // Triggers validation error
            'product_code'   => 'EXIST-EDIT-RT',
            'stock_managed'  => true,
            'base_unit_id'   => $baseUnit->id,
            'is_purchased'   => false,
            'is_sold'        => false,
            'conversions'    => [
                [
                    'id'                => $conv1->id,
                    'unit_id'           => $boxUnit->id,
                    'conversion_factor' => 2,
                    'barcode'           => 'EXIST-BC-1',
                    'price'             => 'RP 18.000,00',
                    'sales_enabled'     => '1',
                    'purchase_enabled'  => '0',
                ],
                [
                    'id'                => $conv3->id,
                    'unit_id'           => $palletUnit->id,
                    'conversion_factor' => 120,
                    'barcode'           => 'EXIST-BC-3',
                    'price'             => 'RP 800.000,00',
                    'sales_enabled'     => '0',
                    'purchase_enabled'  => '1',
                ],
            ],
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
        $content = $page->getContent();

        // Removed conversion conv2 ($conv2->id) must NOT be present in conversions inputs
        $this->assertStringNotContainsString('name="conversions[0][id]" value="' . $conv2->id . '"', $content);
        $this->assertStringNotContainsString('name="conversions[1][id]" value="' . $conv2->id . '"', $content);
        $this->assertStringNotContainsString('name="conversions[2]', $content);

        // Conv1 restored under index 0
        $this->assertMatchesRegularExpression('/name="conversions\[0\]\[id\]"[^>]*value="' . $conv1->id . '"/', $content);
        $this->assertStringContainsString('name="conversions[0][unit_id]" value="' . $boxUnit->id . '"', $content);
        $this->assertStringContainsString('name="conversions[0][price]"', $content);
        $this->assertStringContainsString('value="18000"', $content);

        // Conv3 restored under index 1
        $this->assertMatchesRegularExpression('/name="conversions\[1\]\[id\]"[^>]*value="' . $conv3->id . '"/', $content);
        $this->assertStringContainsString('name="conversions[1][unit_id]" value="' . $palletUnit->id . '"', $content);
        $this->assertStringContainsString('name="conversions[1][price]"', $content);
        $this->assertStringContainsString('value="800000"', $content);

        // Verify restored enablement flags scoped to each surviving row in edit
        preg_match('/<input[^>]*type="checkbox"[^>]*name="conversions\[0\]\[sales_enabled\]"[^>]*>/s', $content, $m0SaleEdit);
        $this->assertNotEmpty($m0SaleEdit, 'Row 0 sales_enabled checkbox must exist');
        $this->assertStringContainsString('checked', $m0SaleEdit[0], 'Row 0 sales_enabled must be checked');

        preg_match('/<input[^>]*type="checkbox"[^>]*name="conversions\[0\]\[purchase_enabled\]"[^>]*>/s', $content, $m0PurEdit);
        $this->assertNotEmpty($m0PurEdit, 'Row 0 purchase_enabled checkbox must exist');
        $this->assertStringNotContainsString('checked', $m0PurEdit[0], 'Row 0 purchase_enabled must NOT be checked');

        preg_match('/<input[^>]*type="checkbox"[^>]*name="conversions\[1\]\[sales_enabled\]"[^>]*>/s', $content, $m1SaleEdit);
        $this->assertNotEmpty($m1SaleEdit, 'Row 1 sales_enabled checkbox must exist');
        $this->assertStringNotContainsString('checked', $m1SaleEdit[0], 'Row 1 sales_enabled must NOT be checked');

        preg_match('/<input[^>]*type="checkbox"[^>]*name="conversions\[1\]\[purchase_enabled\]"[^>]*>/s', $content, $m1PurEdit);
        $this->assertNotEmpty($m1PurEdit, 'Row 1 purchase_enabled checkbox must exist');
        $this->assertStringContainsString('checked', $m1PurEdit[0], 'Row 1 purchase_enabled must be checked');
    }
}
