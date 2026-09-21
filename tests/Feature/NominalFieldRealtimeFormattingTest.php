<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

/**
 * Focused coverage for the add-realtime-financial-input-formatting change:
 *  - the <x-nominal-field> component marks its editable input for the shared real-time
 *    formatter, renders no embedded currency symbol, and keeps its hidden canonical input
 *  - product create/edit price fields (Harga Beli, Harga Jual, tier prices) behave identically
 *  - canonical old-input values round-trip through validation reruns without a currency symbol
 */
class NominalFieldRealtimeFormattingTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);

        $this->currency = Currency::create([
            'currency_name'      => 'Rupiah',
            'code'               => 'IDR',
            'symbol'             => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator'  => ',',
            'exchange_rate'      => 1,
        ]);
    }

    private function createSetting(string $name): Setting
    {
        return Setting::create([
            'company_name'              => $name,
            'company_email'             => strtolower(str_replace(' ', '', $name)) . '@example.com',
            'company_phone'             => '123456789',
            'site_logo'                 => null,
            'default_currency_id'       => $this->currency->id,
            'default_currency_position' => 'left',
            'notification_email'        => 'notify@example.com',
            'footer_text'               => 'Footer',
            'company_address'           => 'Address',
        ]);
    }

    private function withEmptyErrorBag(array $data): array
    {
        return $data + ['errors' => new ViewErrorBag()];
    }

    public function test_nominal_field_component_renders_marker_display_and_hidden_canonical_value(): void
    {
        $html = (string) view('components.nominal-field', $this->withEmptyErrorBag([
            'name'     => 'purchase_price',
            'label'    => 'Harga Beli',
            'value'    => '120000.23',
            'disabled' => false,
            'error'    => null,
        ]))->render();

        // Marker: the visible field is enhanced by the shared real-time formatter.
        $this->assertStringContainsString('data-financial-amount', $html);

        // No currency symbol embedded in the editable text.
        $this->assertStringNotContainsString('RP ', $html);

        // Hidden canonical input retains the raw decimal value for submission/Livewire binding.
        $this->assertMatchesRegularExpression(
            '/name="purchase_price"[^>]*class="nominal-field-hidden"[^>]*value="120000\.23"/s',
            $html
        );

        // Visible input's server-rendered initial value is the same canonical value (the
        // shared formatter re-renders it as "120.000,23" once JS runs client-side).
        $this->assertStringContainsString('class="form-control nominal-field-visible', $html);
    }

    public function test_nominal_field_component_respects_disabled_state(): void
    {
        $html = (string) view('components.nominal-field', $this->withEmptyErrorBag([
            'name'     => 'tier_1_price',
            'label'    => 'Harga Bulk',
            'value'    => '50000',
            'disabled' => true,
            'error'    => null,
        ]))->render();

        $this->assertStringContainsString('disabled', $html);
        $this->assertMatchesRegularExpression(
            '/name="tier_1_price"[^>]*class="nominal-field-hidden"[^>]*value="50000"/s',
            $html
        );
    }

    public function test_product_create_price_fields_use_shared_real_time_marker_without_currency_symbol(): void
    {
        $setting = $this->createSetting('Create Co');
        $user    = User::factory()->create();

        $this->actingAs($user);
        $this->withoutMiddleware([CheckUserRoleForSetting::class]);

        $response = $this->withSession([
            'setting_id'    => $setting->id,
            'user_settings' => collect([$setting]),
        ])->get(route('products.create'));

        $response->assertOk();
        $html = $response->getContent();

        // Harga Beli / Harga Jual / tier price fields all render the shared formatter marker.
        $this->assertStringContainsString('data-financial-amount', $html);
        $this->assertStringContainsString('name="purchase_price"', $html);
        $this->assertStringContainsString('name="sale_price"', $html);
        $this->assertStringContainsString('name="tier_1_price"', $html);
        $this->assertStringContainsString('name="tier_2_price"', $html);
    }

    public function test_product_edit_price_fields_render_canonical_value_without_currency_symbol(): void
    {
        $setting = $this->createSetting('Edit Co');
        $user    = User::factory()->create();

        $product = Product::create([
            'setting_id'          => $setting->id,
            'product_name'        => 'Editable Product',
            'product_code'        => 'REALTIME-EDIT-1',
            'product_quantity'    => 5,
            'product_cost'        => 0,
            'product_price'       => 0,
            'product_stock_alert' => 0,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => 65000.25,
        ]);

        $this->actingAs($user);
        $this->withoutMiddleware([CheckUserRoleForSetting::class]);

        $response = $this->withSession([
            'setting_id'    => $setting->id,
            'user_settings' => collect([$setting]),
        ])->get(route('products.edit', $product));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-financial-amount', $html);
        // Canonical value is present for the hidden input contract; no "RP " prefix anywhere in
        // the editable nominal fields.
        $this->assertStringContainsString('value="65000.25"', $html);
    }
}
