<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class CrossBusinessPriceBackendTest extends TestCase
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
        Setting::factory()->create(['id' => 2, 'company_name' => 'Business B']);
        
        $unit = \Modules\Setting\Entities\Unit::firstOrCreate(['name' => 'Unit Test', 'short_name' => 'UT']);
        $this->product = app(\Modules\Product\Services\ProductCreator::class)->create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'base_unit_id' => $unit->id,
            'is_purchased' => 1,
            'is_sold' => 1,
            'product_stock_alert' => 10,
        ]);
        
        // Remove auto-generated price rows so tests can cleanly set up their own existing/missing states
        ProductPrice::where('product_id', $this->product->id)->delete();
    }

    public function test_load_includes_every_setting_and_defaults_absent_rows_to_zero()
    {
        ProductPrice::updateOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => 1],
            [
                'sale_price' => 100,
                'tier_1_price' => 90,
                'tier_2_price' => 80,
                'last_purchase_price' => 50,
                'average_purchase_price' => 45,
            ]
        );

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->get(route('products.cross-business-prices.edit', $this->product))
            ->assertOk()
            ->assertViewHas('prices');

        $responsePrices = collect($this->actingAs($this->user)->withSession(['setting_id' => 1])->get(route('products.cross-business-prices.edit', $this->product))->original->getData()['prices']);
        
        $this->assertCount(2, $responsePrices);
        
        $priceA = $responsePrices->firstWhere('setting_id', 1);
        $this->assertTrue($priceA['is_existing']);
        $this->assertEquals(100, $priceA['sale_price']);
        
        $priceB = $responsePrices->firstWhere('setting_id', 2);
        $this->assertFalse($priceB['is_existing']);
        $this->assertEquals(0, $priceB['sale_price']);
        $this->assertEquals(0, $priceB['average_purchase_price']);
    }

    public function test_valid_save_updates_all_businesses_while_preserving_average_and_taxes()
    {
        $tax1 = \Modules\Setting\Entities\Tax::firstOrCreate(['name' => 'Tax 1'], ['value' => 10]);
        $tax2 = \Modules\Setting\Entities\Tax::firstOrCreate(['name' => 'Tax 2'], ['value' => 11]);

        $existing = ProductPrice::updateOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => 1],
            [
                'sale_price' => 100,
                'tier_1_price' => 90,
                'tier_2_price' => 80,
                'last_purchase_price' => 50,
                'average_purchase_price' => 45,
                'sale_tax_id' => $tax1->id,
                'purchase_tax_id' => $tax2->id,
            ]
        );

        $payload = [
            'prices' => [
                [
                    'setting_id' => 1,
                    'sale_price' => 110,
                    'tier_1_price' => 95,
                    'tier_2_price' => 85,
                    'last_purchase_price' => 55,
                    'version' => $existing->updated_at->format('Y-m-d H:i:s.u'),
                ],
                [
                    'setting_id' => 2,
                    'sale_price' => 200,
                    'tier_1_price' => 190,
                    'tier_2_price' => 180,
                    'last_purchase_price' => 150,
                    'version' => null,
                ]
            ]
        ];

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payload)
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success');

        $updatedA = ProductPrice::where('product_id', $this->product->id)->where('setting_id', 1)->first();
        $this->assertEquals(110, $updatedA->sale_price);
        $this->assertEquals(45, $updatedA->average_purchase_price);
        $this->assertEquals($tax1->id, $updatedA->sale_tax_id);
        $this->assertEquals($tax2->id, $updatedA->purchase_tax_id);

        $newB = ProductPrice::where('product_id', $this->product->id)->where('setting_id', 2)->first();
        $this->assertNotNull($newB);
        $this->assertEquals(200, $newB->sale_price);
        $this->assertEquals(0, $newB->average_purchase_price);
        $this->assertNull($newB->sale_tax_id);
        $this->assertNull($newB->purchase_tax_id);
    }

    public function test_invalid_partial_stale_payload_changes_no_rows()
    {
        $existing = ProductPrice::updateOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => 1],
            [
                'sale_price' => 100,
                'tier_1_price' => 90,
                'tier_2_price' => 80,
                'last_purchase_price' => 50,
                'average_purchase_price' => 45,
            ]
        );

        // Missing a setting
        $payloadPartial = [
            'prices' => [
                [
                    'setting_id' => 1,
                    'sale_price' => 110,
                    'tier_1_price' => 95,
                    'tier_2_price' => 85,
                    'last_purchase_price' => 55,
                    'version' => $existing->updated_at->format('Y-m-d H:i:s.u'),
                ]
            ]
        ];

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payloadPartial)
            ->assertSessionHas('error', "Submitted prices do not exactly match the current set of businesses. Please reload and try again.");

        $this->assertEquals(100, $existing->fresh()->sale_price);

        // Stale payload
        $payloadStale = [
            'prices' => [
                [
                    'setting_id' => 1,
                    'sale_price' => 110,
                    'tier_1_price' => 95,
                    'tier_2_price' => 85,
                    'last_purchase_price' => 55,
                    'version' => '2020-01-01 00:00:00.000000',
                ],
                [
                    'setting_id' => 2,
                    'sale_price' => 200,
                    'tier_1_price' => 190,
                    'tier_2_price' => 180,
                    'last_purchase_price' => 150,
                    'version' => null,
                ]
            ]
        ];

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payloadStale)
            ->assertSessionHas('error', "Price data for setting ID 1 has been updated by another user. Please refresh and try again.");

        $this->assertEquals(100, $existing->fresh()->sale_price);
        $this->assertNull(ProductPrice::where('product_id', $this->product->id)->where('setting_id', 2)->first());
    }

    public function test_concurrent_creation_conflict_rolls_back_the_complete_batch()
    {
        $existing = ProductPrice::updateOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => 1],
            [
                'sale_price' => 100,
                'tier_1_price' => 90,
                'tier_2_price' => 80,
                'last_purchase_price' => 50,
                'average_purchase_price' => 45,
            ]
        );

        // Another process created setting 2
        ProductPrice::updateOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => 2],
            [
                'sale_price' => 5,
                'tier_1_price' => 5,
                'tier_2_price' => 5,
                'last_purchase_price' => 5,
                'average_purchase_price' => 0,
            ]
        );

        // Our payload tries to create setting 2
        $payload = [
            'prices' => [
                [
                    'setting_id' => 1,
                    'sale_price' => 110,
                    'tier_1_price' => 95,
                    'tier_2_price' => 85,
                    'last_purchase_price' => 55,
                    'version' => $existing->updated_at->format('Y-m-d H:i:s.u'),
                ],
                [
                    'setting_id' => 2,
                    'sale_price' => 200,
                    'tier_1_price' => 190,
                    'tier_2_price' => 180,
                    'last_purchase_price' => 150,
                    'version' => null,
                ]
            ]
        ];

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payload)
            ->assertSessionHas('error', "Price data changed. Reload and try again.");

        $this->assertEquals(100, $existing->fresh()->sale_price); // setting 1 not updated
        $this->assertEquals(5, ProductPrice::where('product_id', $this->product->id)->where('setting_id', 2)->first()->sale_price); // setting 2 kept the other process's data
    }
}
