<?php

namespace Tests\Feature;

use App\Livewire\Product\UnitConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductUnitConfigurationComponentTest extends TestCase
{
    use RefreshDatabase;

    public function test_component_formats_initial_conversion_prices_from_old_input(): void
    {
        Livewire::test(UnitConfiguration::class, [
            'initialStockManaged' => true,
            'initialConversions' => [
                [
                    'id' => 7,
                    'unit_id' => 10,
                    'conversion_factor' => 2,
                    'barcode' => 'BOX-1',
                    'price' => 'RP 65.000,00',
                ],
            ],
        ])
            ->assertSet('conversions.0.price', '65000')
            ->assertSet('displayPrices.0', 'RP 65.000,00');
    }

    public function test_component_preserves_canonical_and_display_values_after_row_updates(): void
    {
        $component = Livewire::test(UnitConfiguration::class, [
            'initialStockManaged' => true,
            'initialConversions' => [
                [
                    'id' => 7,
                    'unit_id' => 10,
                    'conversion_factor' => 2,
                    'barcode' => 'BOX-1',
                    'price' => '65000',
                ],
            ],
        ])
            ->set('conversions.0.price', 'RP 17.500,00')
            ->assertSet('conversions.0.price', '17500')
            ->assertSet('displayPrices.0', 'RP 17.500,00')
            ->call('addConversionRow')
            ->assertSet('conversions.0.price', '17500')
            ->assertSet('displayPrices.0', 'RP 17.500,00')
            ->assertSet('conversions.1.price', '')
            ->assertSet('displayPrices.1', '');

        $rowKey = $component->get('rowKeys.1');

        $component
            ->call('removeConversionRow', $rowKey)
            ->assertCount('conversions', 1)
            ->assertSet('conversions.0.price', '17500')
            ->assertSet('displayPrices.0', 'RP 17.500,00');
    }

    public function test_stock_alert_remains_enabled_when_locked_for_stock_managed_product_and_displays_global_scope_text(): void
    {
        $html = Livewire::test(UnitConfiguration::class, [
            'locked'              => true,
            'initialStockManaged' => true,
            'initialStockAlert'   => 15,
        ])
            ->assertSee('Ambang batas peringatan stok minimum ini berlaku secara global untuk semua bisnis dan lokasi.')
            ->html();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="product_stock_alert"[^>]*>/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*name="product_stock_alert"[^>]*\bdisabled\b[^>]*>/',
            $html
        );
    }

    public function test_stock_alert_is_disabled_when_stock_management_is_disabled(): void
    {
        $html = Livewire::test(UnitConfiguration::class, [
            'locked'              => false,
            'initialStockManaged' => false,
            'initialStockAlert'   => 5,
        ])->html();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="product_stock_alert"[^>]*\bdisabled\b[^>]*>/',
            $html
        );
    }
}
