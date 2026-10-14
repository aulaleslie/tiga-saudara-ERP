<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Livewire\Product\UnitConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportReactiveProps\SupportReactiveProps;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\ProductUnitConversionPrice;
use Modules\Product\Livewire\UnitSearchDropdown;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class ProductUnitConfigurationComponentTest extends TestCase
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

    public function test_remove_conversion_row_removes_row_with_saved_integer_id_using_string_argument(): void
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
                    'sales_enabled' => true,
                    'purchase_enabled' => false,
                ],
                [
                    'id' => 8,
                    'unit_id' => 11,
                    'conversion_factor' => 12,
                    'barcode' => 'CARTON-1',
                    'price' => '350000',
                    'sales_enabled' => false,
                    'purchase_enabled' => true,
                ],
            ],
        ]);

        // Simulating the blade button wire:click="removeConversionRow('7')"
        $component->call('removeConversionRow', '7')
            ->assertCount('conversions', 1)
            ->assertSet('conversions.0.id', 8)
            ->assertSet('conversions.0.unit_id', 11)
            ->assertSet('conversions.0.conversion_factor', 12)
            ->assertSet('conversions.0.barcode', 'CARTON-1')
            ->assertSet('conversions.0.price', '350000')
            ->assertSet('conversions.0.sales_enabled', false)
            ->assertSet('conversions.0.purchase_enabled', true);
    }

    public function test_remove_conversion_row_handles_first_middle_last_removal_with_string_arguments_and_preserves_surviving_values(): void
    {
        $component = Livewire::test(UnitConfiguration::class, [
            'initialStockManaged' => true,
            'initialConversions' => [
                [
                    'id' => 101,
                    'unit_id' => 1,
                    'conversion_factor' => 2,
                    'barcode' => 'FIRST',
                    'price' => '10000',
                    'sales_enabled' => true,
                    'purchase_enabled' => true,
                ],
                [
                    'id' => '202',
                    'unit_id' => 2,
                    'conversion_factor' => 5,
                    'barcode' => 'MIDDLE',
                    'price' => '25000',
                    'sales_enabled' => false,
                    'purchase_enabled' => true,
                ],
                [
                    'id' => null,
                    'unit_id' => 3,
                    'conversion_factor' => 10,
                    'barcode' => 'LAST',
                    'price' => '50000',
                    'sales_enabled' => true,
                    'purchase_enabled' => false,
                ],
            ],
        ]);

        $firstKey = (string) $component->get('rowKeys.0');
        $middleKey = (string) $component->get('rowKeys.1');
        $lastKey = (string) $component->get('rowKeys.2');

        // Middle removal test
        $component->call('removeConversionRow', $middleKey)
            ->assertCount('conversions', 2)
            ->assertSet('conversions.0.barcode', 'FIRST')
            ->assertSet('conversions.1.barcode', 'LAST');

        // Surviving rows: row 0 is FIRST, row 1 is LAST.
        // Now remove first row
        $component->call('removeConversionRow', $firstKey)
            ->assertCount('conversions', 1)
            ->assertSet('conversions.0.barcode', 'LAST')
            ->assertSet('conversions.0.conversion_factor', 10)
            ->assertSet('conversions.0.price', '50000');

        // Add a new row
        $component->call('addConversionRow');
        $this->assertCount(2, $component->get('conversions'));
        $newRowKey = (string) $component->get('rowKeys.1');

        // Remove last row
        $component->call('removeConversionRow', $newRowKey)
            ->assertCount('conversions', 1)
            ->assertSet('conversions.0.barcode', 'LAST');

        // Remove the remaining row
        $component->call('removeConversionRow', $lastKey)
            ->assertCount('conversions', 0);
    }

    public function test_remove_conversion_row_handles_empty_string_and_null_ids_consistently(): void
    {
        $component = Livewire::test(UnitConfiguration::class, [
            'initialStockManaged' => true,
            'initialConversions' => [
                [
                    'id' => '',
                    'unit_id' => 4,
                    'conversion_factor' => 3,
                    'barcode' => 'EMPTY-ID',
                    'price' => '15000',
                ],
                [
                    'id' => null,
                    'unit_id' => 5,
                    'conversion_factor' => 6,
                    'barcode' => 'NULL-ID',
                    'price' => '30000',
                ],
            ],
        ]);

        $key0 = (string) $component->get('rowKeys.0');
        $key1 = (string) $component->get('rowKeys.1');

        $this->assertNotEmpty($key0);
        $this->assertNotEmpty($key1);
        $this->assertNotEquals($key0, $key1);

        $component->call('removeConversionRow', $key0)
            ->assertCount('conversions', 1)
            ->assertSet('conversions.0.barcode', 'NULL-ID');
    }

    public function test_nested_conversion_dropdowns_align_with_compacted_indexes_after_removal_and_retain_stable_keys(): void
    {
        $unitOptions = [
            ['id' => 10, 'name' => 'Pcs'],
            ['id' => 20, 'name' => 'Box'],
            ['id' => 30, 'name' => 'Carton'],
            ['id' => 40, 'name' => 'Pallet'],
        ];

        $component = Livewire::test(UnitConfiguration::class, [
            'initialStockManaged' => true,
            'unitOptions' => $unitOptions,
            'initialConversions' => [
                [
                    'id' => 101,
                    'unit_id' => 10,
                    'conversion_factor' => 1,
                    'barcode' => 'BC-1',
                    'price' => '1000',
                ],
                [
                    'id' => 102,
                    'unit_id' => 20,
                    'conversion_factor' => 10,
                    'barcode' => 'BC-2',
                    'price' => '10000',
                ],
                [
                    'id' => 103,
                    'unit_id' => 30,
                    'conversion_factor' => 100,
                    'barcode' => 'BC-3',
                    'price' => '100000',
                ],
            ],
        ]);

        // Verify initial render contains child hidden inputs with matching names and values
        $htmlBefore = $component->html();
        $this->assertStringContainsString('name="conversions[0][unit_id]" value="10"', $htmlBefore);
        $this->assertStringContainsString('name="conversions[1][unit_id]" value="20"', $htmlBefore);
        $this->assertStringContainsString('name="conversions[2][unit_id]" value="30"', $htmlBefore);

        // Mount child dropdown component corresponding to surviving row index 2 (id 103)
        $childDropdown = Livewire::test(UnitSearchDropdown::class, [
            'name' => 'conversions[2][unit_id]',
            'selected' => 30,
            'options' => $unitOptions,
        ]);
        $childDropdown->assertSeeHtml('name="conversions[2][unit_id]" value="30"');

        // Select surviving unit (40 / Pallet) through the child dropdown component
        $childDropdown->call('select', 40)
            ->assertSet('selected', 40)
            ->assertSet('selectedLabel', 'Pallet')
            ->assertSeeHtml('name="conversions[2][unit_id]" value="40"');

        // Capture child stubs dispatched during subsequent parent render
        $stubs = [];
        Livewire::listen('mount.stub', function ($tag, $id, $params, $parent, $key) use (&$stubs) {
            $stubs[$key] = $params;
        });

        // Remove middle row (id 102) from parent
        $component->call('removeConversionRow', '102');

        // Verify parent array state
        $component->assertCount('conversions', 2)
            ->assertSet('conversions.0.id', 101)
            ->assertSet('conversions.0.unit_id', 10)
            ->assertSet('conversions.0.barcode', 'BC-1')
            ->assertSet('conversions.1.id', 103)
            ->assertSet('conversions.1.barcode', 'BC-3');

        // Verify child component stubs and reactive props:
        // Removed row dropdown stub is absent
        $this->assertArrayNotHasKey('unit-dropdown-102', $stubs);

        // Surviving dropdown 101 retains index 0 and selected value 10
        $this->assertArrayHasKey('unit-dropdown-101', $stubs);
        $this->assertEquals('conversions[0][unit_id]', $stubs['unit-dropdown-101']['name']);
        $this->assertEquals(10, $stubs['unit-dropdown-101']['selected']);

        // Surviving dropdown 103 is compacted to index 1
        $this->assertArrayHasKey('unit-dropdown-103', $stubs);
        $this->assertEquals('conversions[1][unit_id]', $stubs['unit-dropdown-103']['name']);

        // Feed parent's compacted reactive name prop into surviving child dropdown
        SupportReactiveProps::$pendingChildParams[$childDropdown->id()] = [
            'name' => $stubs['unit-dropdown-103']['name'],
        ];
        $childDropdown->call('$refresh');

        // Verify child hidden input reflects compacted index 1 and retains selection 40 selected through child
        $childDropdown->assertSet('name', 'conversions[1][unit_id]');
        $childDropdown->assertSet('selected', 40);
        $childHtml = $childDropdown->html();
        $this->assertStringContainsString('name="conversions[1][unit_id]" value="40"', $childHtml);
        $this->assertStringNotContainsString('name="conversions[2][unit_id]"', $childHtml);

        // Extract surviving child hidden input name and value
        preg_match('/<input[^>]*type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]+)"/s', $childHtml, $mChild);
        $this->assertNotEmpty($mChild);
        $this->assertEquals('conversions[1][unit_id]', $mChild[1]);
        $this->assertEquals('40', $mChild[2]);

        // Construct resulting form payload from parent row state and child hidden input
        $parentConversions = $component->get('conversions');
        $formPayload = [
            'stock_managed' => true,
            'conversions' => [
                0 => [
                    'id'                => $parentConversions[0]['id'],
                    'unit_id'           => 10,
                    'conversion_factor' => $parentConversions[0]['conversion_factor'],
                    'barcode'           => $parentConversions[0]['barcode'],
                    'price'             => $parentConversions[0]['price'],
                ],
                1 => [
                    'id'                => $parentConversions[1]['id'],
                    'unit_id'           => (int) $mChild[2],
                    'conversion_factor' => $parentConversions[1]['conversion_factor'],
                    'barcode'           => $parentConversions[1]['barcode'],
                    'price'             => $parentConversions[1]['price'],
                ],
            ],
        ];

        // Resulting payload has contiguous indexes, selected unit 40 at index 1, and no removed row
        $this->assertCount(2, $formPayload['conversions']);
        $this->assertEquals([0, 1], array_keys($formPayload['conversions']));
        $this->assertArrayNotHasKey(2, $formPayload['conversions']);
        $this->assertEquals(101, $formPayload['conversions'][0]['id']);
        $this->assertEquals(10, $formPayload['conversions'][0]['unit_id']);
        $this->assertEquals(103, $formPayload['conversions'][1]['id']);
        $this->assertEquals(40, $formPayload['conversions'][1]['unit_id']);
    }

    public function test_integration_child_unit_selection_and_parent_row_removal_persists_expected_conversions_via_form_payload(): void
    {
        [$primarySetting] = $this->seedSettings();
        $this->authenticateForAbility('products.edit');

        $baseUnit = $this->createUnit($primarySetting, 'Piece');
        $boxUnit = $this->createUnit($primarySetting, 'Box');
        $cartonUnit = $this->createUnit($primarySetting, 'Carton');
        $palletUnit = $this->createUnit($primarySetting, 'Pallet');
        $drumUnit = $this->createUnit($primarySetting, 'Drum');

        $product = Product::create([
            'product_name'        => 'Original Product',
            'product_code'        => 'PROD-PAYLOAD-INT',
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
            'barcode'           => 'INT-BC-1',
        ]);
        ProductUnitConversionPrice::upsertFor([
            'product_unit_conversion_id' => $conv1->id,
            'setting_id'                 => $primarySetting->id,
            'price'                      => 12000,
        ]);

        $conv2 = ProductUnitConversion::create([
            'product_id'        => $product->id,
            'unit_id'           => $cartonUnit->id,
            'base_unit_id'      => $baseUnit->id,
            'conversion_factor' => 10,
            'barcode'           => 'INT-BC-2',
        ]);
        ProductUnitConversionPrice::upsertFor([
            'product_unit_conversion_id' => $conv2->id,
            'setting_id'                 => $primarySetting->id,
            'price'                      => 50000,
        ]);

        $conv3 = ProductUnitConversion::create([
            'product_id'        => $product->id,
            'unit_id'           => $palletUnit->id,
            'base_unit_id'      => $baseUnit->id,
            'conversion_factor' => 100,
            'barcode'           => 'INT-BC-3',
        ]);
        ProductUnitConversionPrice::upsertFor([
            'product_unit_conversion_id' => $conv3->id,
            'setting_id'                 => $primarySetting->id,
            'price'                      => 400000,
        ]);

        $unitOptions = [
            ['id' => $boxUnit->id, 'name' => $boxUnit->name],
            ['id' => $cartonUnit->id, 'name' => $cartonUnit->name],
            ['id' => $palletUnit->id, 'name' => $palletUnit->name],
            ['id' => $drumUnit->id, 'name' => $drumUnit->name],
        ];

        // 1. Mount parent UnitConfiguration with the 3 conversions
        $component = Livewire::test(UnitConfiguration::class, [
            'initialStockManaged' => true,
            'unitOptions'         => $unitOptions,
            'initialConversions'  => [
                [
                    'id'                => $conv1->id,
                    'unit_id'           => $conv1->unit_id,
                    'conversion_factor' => 2,
                    'barcode'           => 'INT-BC-1',
                    'price'             => '12000',
                ],
                [
                    'id'                => $conv2->id,
                    'unit_id'           => $conv2->unit_id,
                    'conversion_factor' => 10,
                    'barcode'           => 'INT-BC-2',
                    'price'             => '50000',
                ],
                [
                    'id'                => $conv3->id,
                    'unit_id'           => $conv3->unit_id,
                    'conversion_factor' => 100,
                    'barcode'           => 'INT-BC-3',
                    'price'             => '400000',
                ],
            ],
        ]);

        // 2. Mount surviving row child dropdown (row 2 / conv3) and select drumUnit through child
        $survivingDropdown = Livewire::test(UnitSearchDropdown::class, [
            'name'     => 'conversions[2][unit_id]',
            'selected' => $conv3->unit_id,
            'options'  => $unitOptions,
        ]);
        $survivingDropdown->call('select', $drumUnit->id)
            ->assertSet('selected', $drumUnit->id)
            ->assertSeeHtml('name="conversions[2][unit_id]" value="' . $drumUnit->id . '"');

        // 3. Remove middle row (conv2) from parent
        $stubs = [];
        Livewire::listen('mount.stub', function ($tag, $id, $params, $parent, $key) use (&$stubs) {
            $stubs[$key] = $params;
        });
        $component->call('removeConversionRow', (string) $conv2->id);

        // 4. Propagate reactive name to surviving child dropdown and check its hidden input
        SupportReactiveProps::$pendingChildParams[$survivingDropdown->id()] = [
            'name' => $stubs['unit-dropdown-' . $conv3->id]['name'],
        ];
        $survivingDropdown->call('$refresh');
        $survivingDropdown->assertSeeHtml('name="conversions[1][unit_id]" value="' . $drumUnit->id . '"');

        preg_match('/<input[^>]*type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]+)"/s', $survivingDropdown->html(), $mSurviving);
        $this->assertNotEmpty($mSurviving);
        $this->assertEquals('conversions[1][unit_id]', $mSurviving[1]);
        $this->assertEquals((string) $drumUnit->id, $mSurviving[2]);

        // 5. Submit resulting form payload to products.update
        $parentConversions = $component->get('conversions');
        $payload = [
            'product_name'        => 'Updated Product via Payload',
            'product_code'        => 'PROD-PAYLOAD-INT',
            'stock_managed'       => 1,
            'base_unit_id'        => $baseUnit->id,
            'is_purchased'        => 0,
            'is_sold'             => 0,
            'conversions'         => [
                0 => [
                    'id'                => $parentConversions[0]['id'],
                    'unit_id'           => $boxUnit->id,
                    'conversion_factor' => 2,
                    'barcode'           => $parentConversions[0]['barcode'],
                    'price'             => $parentConversions[0]['price'],
                ],
                1 => [
                    'id'                => $parentConversions[1]['id'],
                    'unit_id'           => (int) $mSurviving[2],
                    'conversion_factor' => 100,
                    'barcode'           => $parentConversions[1]['barcode'],
                    'price'             => $parentConversions[1]['price'],
                ],
            ],
        ];

        $response = $this->withSession(['setting_id' => $primarySetting->id])
            ->put(route('products.update', $product), $payload);

        $response->assertRedirect(route('products.index'));

        // 6. Verify database: conv2 removed, conv3 updated to Drum, conv1 intact
        $this->assertDatabaseMissing('product_unit_conversions', ['id' => $conv2->id]);
        $this->assertDatabaseHas('product_unit_conversions', [
            'id'      => $conv3->id,
            'unit_id' => $drumUnit->id,
        ]);
        $this->assertDatabaseHas('product_unit_conversions', [
            'id'      => $conv1->id,
            'unit_id' => $boxUnit->id,
        ]);
    }
}
