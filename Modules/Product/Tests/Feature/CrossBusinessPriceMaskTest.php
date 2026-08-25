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

    public function test_cross_business_price_fields_are_emitted_as_rounded_whole_numbers()
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
        
        // Assert specific HTML structure for each field to ensure it is emitted exactly where expected
        $response->assertSee('name="prices[0][sale_price]" value="2500000" data-original="2500000"', false);
        $response->assertSee('name="prices[0][tier_1_price]" value="2500000" data-original="2500000"', false);
        $response->assertSee('name="prices[0][tier_2_price]" value="2500001" data-original="2500001"', false);
        $response->assertSee('name="prices[0][last_purchase_price]" value="2500001" data-original="2500001"', false);
        $response->assertSee('value="2500000" readonly disabled', false); // average_purchase_price
    }

    public function test_cross_business_price_restored_old_input_is_rounded()
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
                            'sale_price' => '2500000.00'
                        ]
                    ]
                ]
            ])
            ->get(route('products.cross-business-prices.edit', $this->product));

        $response->assertOk();
        
        // It should render the old value rounded, not "2500000.00"
        $response->assertSee('name="prices[0][sale_price]" value="2500000"', false);
        $response->assertDontSee('value="2500000.00"', false);
    }

    public function test_unchanged_cross_business_price_form_round_trips_safely()
    {
        $tax1 = Tax::firstOrCreate(['name' => 'Tax 1'], ['value' => 10]);
        $tax2 = Tax::firstOrCreate(['name' => 'Tax 2'], ['value' => 11]);

        $existing = ProductPrice::updateOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => 1],
            [
                'sale_price' => 2500000.00,
                'tier_1_price' => 90,
                'tier_2_price' => 80,
                'last_purchase_price' => 50,
                'average_purchase_price' => 45.00,
                'sale_tax_id' => $tax1->id,
                'purchase_tax_id' => $tax2->id,
            ]
        );

        // Simulate the JS unmask behavior: JS strips dots but leaves the numeric value as a whole number.
        // We simulate the frontend sending back exactly what it displayed for an unchanged form: 2500000
        $payload = [
            'prices' => [
                [
                    'setting_id' => 1,
                    'sale_price' => 2500000,
                    'tier_1_price' => 90,
                    'tier_2_price' => 80,
                    'last_purchase_price' => 50,
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
        
        // The value remains numerically identical (2500000.00 in DB vs 2500000 stored)
        $this->assertEquals(2500000, $updated->sale_price);
        $this->assertEquals(45, $updated->average_purchase_price);

        // Ensure tax preservation is actually exercised
        $this->assertEquals($tax1->id, $updated->sale_tax_id);
        $this->assertEquals($tax2->id, $updated->purchase_tax_id);
    }

    public function test_cross_business_price_form_renders_apply_to_all_hooks_only_for_editable_columns()
    {
        ProductPrice::updateOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => 1],
            [
                'sale_price' => 100000,
                'tier_1_price' => 90000,
                'tier_2_price' => 80000,
                'last_purchase_price' => 70000,
                'average_purchase_price' => 65000,
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
        $response->assertSee('name="prices[0][sale_price]" value="100000" data-original="100000" data-column="sale_price"', false);
        $response->assertSee('button type="button" class="btn btn-sm btn-outline-primary btn-apply-all d-none ms-1" data-column="sale_price"', false);

        $response->assertSee('name="prices[0][tier_1_price]" value="90000" data-original="90000" data-column="tier_1_price"', false);
        $response->assertSee('button type="button" class="btn btn-sm btn-outline-primary btn-apply-all d-none ms-1" data-column="tier_1_price"', false);

        $response->assertSee('name="prices[0][tier_2_price]" value="80000" data-original="80000" data-column="tier_2_price"', false);
        $response->assertSee('button type="button" class="btn btn-sm btn-outline-primary btn-apply-all d-none ms-1" data-column="tier_2_price"', false);

        $response->assertSee('name="prices[0][last_purchase_price]" value="70000" data-original="70000" data-column="last_purchase_price"', false);
        $response->assertSee('button type="button" class="btn btn-sm btn-outline-primary btn-apply-all d-none ms-1" data-column="last_purchase_price"', false);

        // Average purchase price must NOT carry column copy hook or button
        $response->assertDontSee('data-column="average_purchase_price"', false);
    }
}
