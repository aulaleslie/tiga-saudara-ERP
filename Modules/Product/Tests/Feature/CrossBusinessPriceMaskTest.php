<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class CrossBusinessPriceMaskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $permission = Permission::firstOrCreate(['name' => 'products.manage_cross_business_prices', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        
        $this->user = User::factory()->create();
        $this->user->assignRole($role);
        $this->user->givePermissionTo('products.manage_cross_business_prices');
        $this->user->load('roles.permissions', 'permissions');
        
        Setting::truncate();
        Setting::factory()->create(['id' => 1, 'company_name' => 'Business A']);
        
        $unit = \Modules\Setting\Entities\Unit::firstOrCreate(['name' => 'Unit Test', 'short_name' => 'UT']);
        $this->product = app(\Modules\Product\Services\ProductCreator::class)->create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'base_unit_id' => $unit->id,
            'is_purchased' => 1,
            'is_sold' => 1,
            'product_stock_alert' => 10,
        ]);
        
        ProductPrice::where('product_id', $this->product->id)->delete();
    }

    public function test_cross_business_price_fields_are_emitted_as_two_decimal_formatted_strings()
    {
        ProductPrice::updateOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => 1],
            [
                'sale_price' => 2500000.00,
                'tier_1_price' => 2500000.49,
                'tier_2_price' => 2500000.50,
                'last_purchase_price' => 2500000.99,
                'average_purchase_price' => 2500000.00,
            ]
        );

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->get(route('products.cross-business-prices.edit', $this->product));

        $response->assertOk();
        
        // Assert specific HTML structure for each field with two-decimal formatting
        $response->assertSee('name="prices[0][sale_price]" value="2.500.000,00" data-original="2500000.00"', false);
        $response->assertSee('name="prices[0][tier_1_price]" value="2.500.000,49" data-original="2500000.49"', false);
        $response->assertSee('name="prices[0][tier_2_price]" value="2.500.000,50" data-original="2500000.50"', false);
        $response->assertSee('name="prices[0][last_purchase_price]" value="2.500.000,99" data-original="2500000.99"', false);
        $response->assertSee('value="2.500.000,00" readonly disabled', false); // average_purchase_price
    }

    public function test_cross_business_price_restored_old_input_preserves_two_decimals()
    {
        ProductPrice::updateOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => 1],
            [
                'sale_price' => 1000.00,
            ]
        );

        $response = $this->actingAs($this->user)
            ->withSession([
                'setting_id' => 1,
                '_old_input' => [
                    'prices' => [
                        0 => [
                            'sale_price' => '2500000.75'
                        ]
                    ]
                ]
            ])
            ->get(route('products.cross-business-prices.edit', $this->product));

        $response->assertOk();
        
        // It should render the old input formatted with two decimals
        $response->assertSee('name="prices[0][sale_price]" value="2.500.000,75"', false);
    }

    public function test_unchanged_cross_business_price_form_round_trips_safely()
    {
        $tax1 = Tax::firstOrCreate(['name' => 'Tax 1'], ['value' => 10]);
        $tax2 = Tax::firstOrCreate(['name' => 'Tax 2'], ['value' => 11]);

        $existing = ProductPrice::updateOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => 1],
            [
                'sale_price' => 2500000.75,
                'tier_1_price' => 90.50,
                'tier_2_price' => 80.25,
                'last_purchase_price' => 50.10,
                'average_purchase_price' => 45.33,
                'sale_tax_id' => $tax1->id,
                'purchase_tax_id' => $tax2->id,
            ]
        );

        // Frontend unmasks form control values before submission to canonical dot decimals
        $payload = [
            'prices' => [
                [
                    'setting_id' => 1,
                    'sale_price' => '2500000.75',
                    'tier_1_price' => '90.50',
                    'tier_2_price' => '80.25',
                    'last_purchase_price' => '50.10',
                    'version' => $existing->updated_at->format('Y-m-d H:i:s.u'),
                ]
            ]
        ];

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payload)
            ->assertRedirect(route('products.cross-business-prices.edit', $this->product))
            ->assertSessionHas('success');

        $updated = ProductPrice::where('product_id', $this->product->id)->where('setting_id', 1)->first();
        
        // Decimal values remain unchanged
        $this->assertEquals(2500000.75, (float) $updated->sale_price);
        $this->assertEquals(45.33, (float) $updated->average_purchase_price);

        // Ensure tax preservation is actually exercised
        $this->assertEquals($tax1->id, $updated->sale_tax_id);
        $this->assertEquals($tax2->id, $updated->purchase_tax_id);
    }

    public function test_cross_business_price_form_renders_apply_to_all_hooks_only_for_editable_columns()
    {
        ProductPrice::updateOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => 1],
            [
                'sale_price' => 100000.25,
                'tier_1_price' => 90000.50,
                'tier_2_price' => 80000.75,
                'last_purchase_price' => 70000.10,
                'average_purchase_price' => 65000.99,
            ]
        );

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->get(route('products.cross-business-prices.edit', $this->product));

        $response->assertOk();

        // Exactly 4 hidden buttons rendered per setting row (excluding JS script references)
        $content = $response->getContent();
        $tableHtml = substr($content, strpos($content, '<table'), strpos($content, '</table>') - strpos($content, '<table'));
        $this->assertEquals(4, substr_count($tableHtml, 'btn-apply-all'));

        // Assert exact structure per editable input: input with data-column followed by matching button with data-column
        $response->assertSee('name="prices[0][sale_price]" value="100.000,25" data-original="100000.25" data-column="sale_price"', false);
        $response->assertSee('button type="button" class="btn btn-sm btn-outline-primary btn-apply-all d-none ms-1" data-column="sale_price"', false);

        $response->assertSee('name="prices[0][tier_1_price]" value="90.000,50" data-original="90000.50" data-column="tier_1_price"', false);
        $response->assertSee('button type="button" class="btn btn-sm btn-outline-primary btn-apply-all d-none ms-1" data-column="tier_1_price"', false);

        $response->assertSee('name="prices[0][tier_2_price]" value="80.000,75" data-original="80000.75" data-column="tier_2_price"', false);
        $response->assertSee('button type="button" class="btn btn-sm btn-outline-primary btn-apply-all d-none ms-1" data-column="tier_2_price"', false);

        $response->assertSee('name="prices[0][last_purchase_price]" value="70.000,10" data-original="70000.10" data-column="last_purchase_price"', false);
        $response->assertSee('button type="button" class="btn btn-sm btn-outline-primary btn-apply-all d-none ms-1" data-column="last_purchase_price"', false);

        // Average purchase price must NOT carry column copy hook or button
        $response->assertDontSee('data-column="average_purchase_price"', false);
    }

    public function test_raw_canonical_input_is_persisted_correctly()
    {
        $existing = ProductPrice::updateOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => 1],
            ['sale_price' => 100]
        );

        // Raw canonical integer input 6853 -> persisted as 6853.00
        $payload1 = [
            'prices' => [
                [
                    'setting_id' => 1,
                    'sale_price' => '6853',
                    'tier_1_price' => '1111.23',
                    'tier_2_price' => '1000.00',
                    'last_purchase_price' => '500.00',
                    'version' => $existing->updated_at->format('Y-m-d H:i:s.u'),
                ]
            ]
        ];

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payload1)
            ->assertRedirect(route('products.cross-business-prices.edit', $this->product))
            ->assertSessionHas('success');

        $updated1 = ProductPrice::where('product_id', $this->product->id)->where('setting_id', 1)->first();
        $this->assertEquals(6853.00, (float) $updated1->sale_price);
        $this->assertEquals(1111.23, (float) $updated1->tier_1_price);
    }

    public function test_indonesian_formatted_input_is_persisted_as_canonical_decimal()
    {
        $existing = ProductPrice::updateOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => 1],
            ['sale_price' => 100]
        );

        // Simulated frontend unmasked Indonesian formatted input "6.853,25" -> "6853.25"
        $payload = [
            'prices' => [
                [
                    'setting_id' => 1,
                    'sale_price' => '6853.25',
                    'tier_1_price' => '1000.00',
                    'tier_2_price' => '1000.00',
                    'last_purchase_price' => '500.00',
                    'version' => $existing->updated_at->format('Y-m-d H:i:s.u'),
                ]
            ]
        ];

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payload)
            ->assertRedirect(route('products.cross-business-prices.edit', $this->product))
            ->assertSessionHas('success');

        $updated = ProductPrice::where('product_id', $this->product->id)->where('setting_id', 1)->first();
        $this->assertEquals(6853.25, (float) $updated->sale_price);
    }

    public function test_empty_input_triggers_validation_error()
    {
        $existing = ProductPrice::updateOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => 1],
            ['sale_price' => 100]
        );

        $payload = [
            'prices' => [
                [
                    'setting_id' => 1,
                    'sale_price' => '', // empty input
                    'tier_1_price' => '1000.00',
                    'tier_2_price' => '1000.00',
                    'last_purchase_price' => '500.00',
                    'version' => $existing->updated_at->format('Y-m-d H:i:s.u'),
                ]
            ]
        ];

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payload)
            ->assertSessionHasErrors(['prices.0.sale_price']);
    }

    public function test_malformed_input_triggers_validation_error()
    {
        $existing = ProductPrice::updateOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => 1],
            ['sale_price' => 100]
        );

        $payload = [
            'prices' => [
                [
                    'setting_id' => 1,
                    'sale_price' => '12abc', // malformed input
                    'tier_1_price' => '1000.00',
                    'tier_2_price' => '1000.00',
                    'last_purchase_price' => '500.00',
                    'version' => $existing->updated_at->format('Y-m-d H:i:s.u'),
                ]
            ]
        ];

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payload)
            ->assertSessionHasErrors(['prices.0.sale_price']);
    }

    /**
     * Verifies that the rendered HTML contains the focus/blur raw canonical logic,
     * view-mode event handler protection, and strict decimal parsing functions.
     */
    public function test_rendered_html_contains_focus_blur_raw_canonical_and_view_protection_structure()
    {
        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->get(route('products.cross-business-prices.edit', $this->product));

        $response->assertOk();

        // Verify key JS script elements exist in view
        $response->assertSee('function parseCanonicalDecimal(val)', false);
        $response->assertSee('function formatLocaleDisplay(val)', false);
        $response->assertSee('function getRawCanonicalValue(val)', false);
        $response->assertSee('idPattern', false);
        $response->assertSee('canonicalPattern', false);
        $response->assertSee('keydown keypress keyup input paste change', false);
        $response->assertSee('if ($(this).prop(\'readonly\'))', false);
        $response->assertSee('focus', false);
        $response->assertSee('blur', false);
    }
}
