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
            ->assertRedirect(route('products.cross-business-prices.edit', $this->product))
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

    public function test_all_or_nothing_validation_failure_for_negative_prices()
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
                    'sale_price' => -200, // Invalid negative price
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
            ->assertSessionHasErrors(['prices.1.sale_price']);

        // Check all-or-nothing (setting 1 not updated, setting 2 not created)
        $this->assertEquals(100, $existing->fresh()->sale_price);
        $this->assertNull(ProductPrice::where('product_id', $this->product->id)->where('setting_id', 2)->first());
    }

    public function test_conversion_matrix_renders_headers_and_empty_state()
    {
        // 1. Without conversions: shows empty state message
        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->get(route('products.cross-business-prices.edit', $this->product));

        $response->assertOk();
        $response->assertSee('Harga Satuan Dasar — UNIT TEST', false);
        $response->assertSee('Harga Satuan Konversi', false);
        $response->assertSee('Produk ini belum memiliki konversi unit', false);

        // 2. With conversion: shows unit, factor, and column headers
        $boxUnit = \Modules\Setting\Entities\Unit::firstOrCreate(['name' => 'KOTAK', 'short_name' => 'KTK']);
        $conv = \Modules\Product\Entities\ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $this->product->base_unit_id,
            'conversion_factor' => 12,
            'barcode' => 'BOX-12',
        ]);

        \Modules\Product\Entities\ProductUnitConversionPrice::create([
            'product_unit_conversion_id' => $conv->id,
            'setting_id' => 1,
            'price' => 22500.50,
            'sales_enabled' => true,
            'purchase_enabled' => true,
        ]);

        $response2 = $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->get(route('products.cross-business-prices.edit', $this->product));

        $response2->assertOk();
        $response2->assertSee('KOTAK · 12 UNIT TEST (Rp)', false);
        $response2->assertSee('value="22.500,50"', false);
        $response2->assertSee('Belum diatur', false); // setting 2 has no conversion price row
    }

    public function test_combined_save_persists_base_and_conversion_prices_independently()
    {
        $boxUnit = \Modules\Setting\Entities\Unit::firstOrCreate(['name' => 'KOTAK', 'short_name' => 'KTK']);
        $conv = \Modules\Product\Entities\ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $this->product->base_unit_id,
            'conversion_factor' => 12,
            'barcode' => 'BOX-12',
        ]);

        $price1 = \Modules\Product\Entities\ProductUnitConversionPrice::create([
            'product_unit_conversion_id' => $conv->id,
            'setting_id' => 1,
            'price' => 22500,
            'sales_enabled' => false,
            'purchase_enabled' => true,
        ]);

        $price2 = \Modules\Product\Entities\ProductUnitConversionPrice::create([
            'product_unit_conversion_id' => $conv->id,
            'setting_id' => 2,
            'price' => 30000,
            'sales_enabled' => true,
            'purchase_enabled' => false,
        ]);

        $snapshot = app(\Modules\Product\Services\CrossBusinessPriceService::class)->generateConversionSnapshot($this->product);

        $payload = [
            'prices' => [
                [
                    'setting_id' => 1,
                    'sale_price' => 100,
                    'tier_1_price' => 90,
                    'tier_2_price' => 80,
                    'last_purchase_price' => 50,
                    'version' => null,
                ],
                [
                    'setting_id' => 2,
                    'sale_price' => 200,
                    'tier_1_price' => 190,
                    'tier_2_price' => 180,
                    'last_purchase_price' => 150,
                    'version' => null,
                ]
            ],
            'conversions' => [
                [
                    'setting_id' => 1,
                    'conversion_id' => $conv->id,
                    'price' => '25000.50', // updated for setting 1
                    'version' => $price1->updated_at->format('Y-m-d H:i:s.u'),
                ],
                [
                    'setting_id' => 2,
                    'conversion_id' => $conv->id,
                    'price' => '30000.00', // unchanged for setting 2
                    'version' => $price2->updated_at->format('Y-m-d H:i:s.u'),
                ]
            ],
            'conversion_snapshot' => $snapshot['data'],
            'conversion_snapshot_signature' => $snapshot['signature'],
        ];

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payload)
            ->assertRedirect(route('products.cross-business-prices.edit', $this->product))
            ->assertSessionHas('success');

        $price1Fresh = $price1->fresh();
        $this->assertEquals(25000.50, (float) $price1Fresh->price);
        $this->assertFalse($price1Fresh->sales_enabled); // Preserved
        $this->assertTrue($price1Fresh->purchase_enabled); // Preserved

        $price2Fresh = $price2->fresh();
        $this->assertEquals(30000.00, (float) $price2Fresh->price);
        $this->assertTrue($price2Fresh->sales_enabled); // Preserved
        $this->assertFalse($price2Fresh->purchase_enabled); // Preserved
    }

    public function test_missing_conversion_cell_semantics_blank_vs_zero()
    {
        $boxUnit = \Modules\Setting\Entities\Unit::firstOrCreate(['name' => 'KOTAK', 'short_name' => 'KTK']);
        $conv = \Modules\Product\Entities\ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $this->product->base_unit_id,
            'conversion_factor' => 12,
        ]);

        $snapshot = app(\Modules\Product\Services\CrossBusinessPriceService::class)->generateConversionSnapshot($this->product);

        // Setting 1 gets explicit 0.00 (should create row), Setting 2 left blank/null (should remain missing)
        $payload = [
            'prices' => [
                ['setting_id' => 1, 'sale_price' => 10, 'tier_1_price' => 10, 'tier_2_price' => 10, 'last_purchase_price' => 10, 'version' => null],
                ['setting_id' => 2, 'sale_price' => 20, 'tier_1_price' => 20, 'tier_2_price' => 20, 'last_purchase_price' => 20, 'version' => null],
            ],
            'conversions' => [
                ['setting_id' => 1, 'conversion_id' => $conv->id, 'price' => '0.00', 'version' => null],
                ['setting_id' => 2, 'conversion_id' => $conv->id, 'price' => '', 'version' => null],
            ],
            'conversion_snapshot' => $snapshot['data'],
            'conversion_snapshot_signature' => $snapshot['signature'],
        ];

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payload)
            ->assertSessionHas('success');

        $row1 = \Modules\Product\Entities\ProductUnitConversionPrice::where('product_unit_conversion_id', $conv->id)->where('setting_id', 1)->first();
        $this->assertNotNull($row1);
        $this->assertEquals(0.00, (float) $row1->price);
        $this->assertTrue($row1->sales_enabled);
        $this->assertTrue($row1->purchase_enabled);

        $row2 = \Modules\Product\Entities\ProductUnitConversionPrice::where('product_unit_conversion_id', $conv->id)->where('setting_id', 2)->first();
        $this->assertNull($row2); // Left absent!
    }

    public function test_stale_snapshot_or_tampered_evidence_is_rejected()
    {
        $boxUnit = \Modules\Setting\Entities\Unit::firstOrCreate(['name' => 'KOTAK', 'short_name' => 'KTK']);
        $conv = \Modules\Product\Entities\ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $this->product->base_unit_id,
            'conversion_factor' => 12,
        ]);

        $snapshot = app(\Modules\Product\Services\CrossBusinessPriceService::class)->generateConversionSnapshot($this->product);

        // 1. Tampered signature
        $payloadTampered = [
            'prices' => [
                ['setting_id' => 1, 'sale_price' => 10, 'tier_1_price' => 10, 'tier_2_price' => 10, 'last_purchase_price' => 10, 'version' => null],
                ['setting_id' => 2, 'sale_price' => 20, 'tier_1_price' => 20, 'tier_2_price' => 20, 'last_purchase_price' => 20, 'version' => null],
            ],
            'conversions' => [
                ['setting_id' => 1, 'conversion_id' => $conv->id, 'price' => '100', 'version' => null],
                ['setting_id' => 2, 'conversion_id' => $conv->id, 'price' => '200', 'version' => null],
            ],
            'conversion_snapshot' => $snapshot['data'],
            'conversion_snapshot_signature' => 'invalid-tampered-signature',
        ];

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payloadTampered)
            ->assertSessionHas('error', "Bukti data konversi telah diubah atau tidak valid. Silakan muat ulang halaman.");

        // 2. Stale factor (factor changed from 12 to 24 PCS after page load)
        $conv->update(['conversion_factor' => 24]);

        $payloadStale = [
            'prices' => [
                ['setting_id' => 1, 'sale_price' => 10, 'tier_1_price' => 10, 'tier_2_price' => 10, 'last_purchase_price' => 10, 'version' => null],
                ['setting_id' => 2, 'sale_price' => 20, 'tier_1_price' => 20, 'tier_2_price' => 20, 'last_purchase_price' => 20, 'version' => null],
            ],
            'conversions' => [
                ['setting_id' => 1, 'conversion_id' => $conv->id, 'price' => '100', 'version' => null],
                ['setting_id' => 2, 'conversion_id' => $conv->id, 'price' => '200', 'version' => null],
            ],
            'conversion_snapshot' => $snapshot['data'],
            'conversion_snapshot_signature' => $snapshot['signature'],
        ];

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payloadStale)
            ->assertSessionHas('error', "Faktor atau unit konversi telah diperbarui oleh pengguna lain. Silakan muat ulang halaman.");
    }

    public function test_product_without_conversions_can_save_prices()
    {
        // Ensure product has no conversions
        \Modules\Product\Entities\ProductUnitConversion::where('product_id', $this->product->id)->delete();

        $snapshot = app(\Modules\Product\Services\CrossBusinessPriceService::class)->generateConversionSnapshot($this->product);

        $payload = [
            'prices' => [
                [
                    'setting_id' => 1,
                    'sale_price' => 125000,
                    'tier_1_price' => 120000,
                    'tier_2_price' => 115000,
                    'last_purchase_price' => 90000,
                    'version' => null,
                ],
                [
                    'setting_id' => 2,
                    'sale_price' => 135000,
                    'tier_1_price' => 130000,
                    'tier_2_price' => 125000,
                    'last_purchase_price' => 95000,
                    'version' => null,
                ],
            ],
            'conversions' => [],
            'conversion_snapshot' => $snapshot['data'],
            'conversion_snapshot_signature' => $snapshot['signature'],
        ];

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payload)
            ->assertRedirect(route('products.cross-business-prices.edit', $this->product))
            ->assertSessionHas('success');

        $price1 = ProductPrice::where('product_id', $this->product->id)->where('setting_id', 1)->first();
        $this->assertNotNull($price1);
        $this->assertEquals(125000, (float) $price1->sale_price);

        $price2 = ProductPrice::where('product_id', $this->product->id)->where('setting_id', 2)->first();
        $this->assertNotNull($price2);
        $this->assertEquals(135000, (float) $price2->sale_price);
    }

    public function test_bundle_matrix_loads_grouped_bundles_and_preserves_missing_copy_cells()
    {
        $groupUuid1 = (string) \Illuminate\Support\Str::uuid();
        $groupUuid2 = (string) \Illuminate\Support\Str::uuid();

        // Bundle in group 1 for Setting 1
        $bundle1A = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $this->product->id,
            'setting_id' => 1,
            'replica_group_uuid' => $groupUuid1,
            'name' => 'Paket A',
            'bundle_sale_price' => 50000,
            'is_active' => true,
        ]);

        // Bundle in group 1 for Setting 2 (has different name to test differing-name detection)
        $bundle1B = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $this->product->id,
            'setting_id' => 2,
            'replica_group_uuid' => $groupUuid1,
            'name' => 'Paket A Bisnis B',
            'bundle_sale_price' => 55000,
            'is_active' => true,
        ]);

        // Bundle in group 2 for Setting 1 only (Setting 2 missing!)
        $bundle2A = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $this->product->id,
            'setting_id' => 1,
            'replica_group_uuid' => $groupUuid2,
            'name' => 'Paket B Inactive',
            'bundle_sale_price' => 75000,
            'is_active' => false,
        ]);

        // Unrelated product bundle should be excluded
        $otherProduct = app(\Modules\Product\Services\ProductCreator::class)->create([
            'product_name' => 'Other Product',
            'product_code' => 'OTHER-001',
            'base_unit_id' => $this->product->base_unit_id,
        ]);
        \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $otherProduct->id,
            'setting_id' => 1,
            'replica_group_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Paket Other',
            'bundle_sale_price' => 99000,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->get(route('products.cross-business-prices.edit', $this->product));

        $response->assertOk();
        $bundlesData = $response->original->getData()['bundlesData'];

        $this->assertCount(2, $bundlesData['headers']);

        // Header 1 has different names across businesses
        $header1 = collect($bundlesData['headers'])->firstWhere('replica_group_uuid', $groupUuid1);
        $this->assertNotNull($header1);
        $this->assertTrue($header1['has_different_names']);

        // Check matrix rows
        $this->assertCount(2, $bundlesData['matrix']);
        $row1 = collect($bundlesData['matrix'])->firstWhere('setting_id', 1);
        $row2 = collect($bundlesData['matrix'])->firstWhere('setting_id', 2);

        // Setting 1 has both bundles existing
        $cell1A = collect($row1['bundles'])->firstWhere('replica_group_uuid', $groupUuid1);
        $this->assertTrue($cell1A['is_existing']);
        $this->assertEquals(50000, $cell1A['price']);

        $cell2A = collect($row1['bundles'])->firstWhere('replica_group_uuid', $groupUuid2);
        $this->assertTrue($cell2A['is_existing']);
        $this->assertEquals(75000, $cell2A['price']);
        $this->assertFalse($cell2A['is_active']); // inactive bundle

        // Setting 2 has group 1 existing, group 2 missing
        $cell1B = collect($row2['bundles'])->firstWhere('replica_group_uuid', $groupUuid1);
        $this->assertTrue($cell1B['is_existing']);
        $this->assertEquals(55000, $cell1B['price']);

        $cell2B = collect($row2['bundles'])->firstWhere('replica_group_uuid', $groupUuid2);
        $this->assertFalse($cell2B['is_existing']);
        $this->assertNull($cell2B['bundle_id']);
    }

    public function test_combined_save_persists_bundle_prices_and_emits_changed_only_feed_events()
    {
        $groupUuid = (string) \Illuminate\Support\Str::uuid();

        $bundleA = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $this->product->id,
            'setting_id' => 1,
            'replica_group_uuid' => $groupUuid,
            'name' => 'Paket Combo',
            'description' => 'Original description',
            'bundle_sale_price' => 40000,
            'is_active' => true,
        ]);

        $bundleB = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $this->product->id,
            'setting_id' => 2,
            'replica_group_uuid' => $groupUuid,
            'name' => 'Paket Combo',
            'description' => 'Original description B',
            'bundle_sale_price' => 45000,
            'is_active' => true,
        ]);

        $service = app(\Modules\Product\Services\CrossBusinessPriceService::class);
        $convSnapshot = $service->generateConversionSnapshot($this->product);
        $bundleSnapshot = $service->generateBundleSnapshot($this->product);

        // Initialize base prices for setting 1 and 2 with the same values so base price is unchanged in this test
        $p1 = ProductPrice::create(['product_id' => $this->product->id, 'setting_id' => 1, 'sale_price' => 100, 'tier_1_price' => 90, 'tier_2_price' => 80, 'last_purchase_price' => 50, 'average_purchase_price' => 0]);
        $p2 = ProductPrice::create(['product_id' => $this->product->id, 'setting_id' => 2, 'sale_price' => 110, 'tier_1_price' => 95, 'tier_2_price' => 85, 'last_purchase_price' => 55, 'average_purchase_price' => 0]);

        $payload = [
            'prices' => [
                ['setting_id' => 1, 'sale_price' => 120, 'tier_1_price' => 90, 'tier_2_price' => 80, 'last_purchase_price' => 50, 'version' => $p1->updated_at->format('Y-m-d H:i:s.u')],
                ['setting_id' => 2, 'sale_price' => 110, 'tier_1_price' => 95, 'tier_2_price' => 85, 'last_purchase_price' => 55, 'version' => $p2->updated_at->format('Y-m-d H:i:s.u')],
            ],
            'conversions' => [],
            'conversion_snapshot' => $convSnapshot['data'],
            'conversion_snapshot_signature' => $convSnapshot['signature'],
            'bundles' => [
                [
                    'bundle_id' => $bundleA->id,
                    'setting_id' => 1,
                    'replica_group_uuid' => $groupUuid,
                    'bundle_sale_price' => 42000, // Changed from 40000 -> 42000
                    'version' => $bundleA->updated_at->format('Y-m-d H:i:s.u'),
                ],
                [
                    'bundle_id' => $bundleB->id,
                    'setting_id' => 2,
                    'replica_group_uuid' => $groupUuid,
                    'bundle_sale_price' => 45000, // UNCHANGED (45000 == 45000)
                    'version' => $bundleB->updated_at->format('Y-m-d H:i:s.u'),
                ],
            ],
            'bundle_snapshot' => $bundleSnapshot['data'],
            'bundle_snapshot_signature' => $bundleSnapshot['signature'],
        ];

        $bundleAInitialUpdatedAt = $bundleA->updated_at;
        $bundleBInitialUpdatedAt = $bundleB->updated_at;

        // Sleep briefly to ensure any timestamp change would be distinct
        usleep(100000);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payload);

        if ($response->getSession()->has('error')) {
            $this->fail("PUT failed with session error: " . $response->getSession()->get('error'));
        }
        if ($response->getSession()->has('errors')) {
            $this->fail("PUT failed with validation errors: " . json_encode($response->getSession()->get('errors')->all()));
        }

        $response->assertRedirect(route('products.cross-business-prices.edit', $this->product))
            ->assertSessionHas('success');

        $bundleA->refresh();
        $bundleB->refresh();

        $this->assertEquals(42000, (float) $bundleA->bundle_sale_price);
        $this->assertEquals('ORIGINAL DESCRIPTION', $bundleA->description); // Preserved metadata (BaseModel uppercased)!
        $this->assertEquals(45000, (float) $bundleB->bundle_sale_price);

        // Unchanged bundle must NOT have its timestamp advanced!
        $this->assertEquals(
            $bundleBInitialUpdatedAt->format('Y-m-d H:i:s.u'),
            $bundleB->updated_at->format('Y-m-d H:i:s.u'),
            "Unchanged bundle timestamp should not be updated"
        );

        // Verify Price Feed Events
        $productEvent = \Modules\Product\Entities\ProductPriceFeedEvent::where('event_type', \Modules\Product\Entities\ProductPriceFeedEvent::TYPE_PRODUCT_PRICE_UPDATED)
            ->latest('id')
            ->first();
        $this->assertNotNull($productEvent);
        $this->assertNotEmpty($productEvent->operation_uuid);

        $bundleEvent = \Modules\Product\Entities\ProductPriceFeedEvent::where('event_type', \Modules\Product\Entities\ProductPriceFeedEvent::TYPE_BUNDLE_PRICE_UPDATED)
            ->latest('id')
            ->first();
        $this->assertNotNull($bundleEvent);
        $this->assertNotEmpty($bundleEvent->operation_uuid);

        // Compare product-price and bundle-price events and assert identical operation_uuid values
        $this->assertEquals(
            $productEvent->operation_uuid,
            $bundleEvent->operation_uuid,
            'Product price event and bundle price event in the same combined save must share identical operation_uuid'
        );

        $snapshots = $bundleEvent->snapshots;

        // Exactly 1 snapshot recorded because bundle B was unchanged!
        $this->assertCount(1, $snapshots);
        $this->assertEquals(1, $snapshots[0]->setting_id);
        $this->assertEquals(['bundle_sale_price' => '40000.00'], $snapshots[0]->before_snapshot);
        $this->assertEquals(['bundle_sale_price' => '42000.00'], $snapshots[0]->after_snapshot);
    }

    public function test_stale_bundle_price_or_structure_is_rejected()
    {
        $groupUuid = (string) \Illuminate\Support\Str::uuid();

        $bundleA = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $this->product->id,
            'setting_id' => 1,
            'replica_group_uuid' => $groupUuid,
            'name' => 'Paket Stale',
            'bundle_sale_price' => 40000,
            'is_active' => true,
        ]);

        $service = app(\Modules\Product\Services\CrossBusinessPriceService::class);
        $convSnapshot = $service->generateConversionSnapshot($this->product);
        $bundleSnapshot = $service->generateBundleSnapshot($this->product);

        // Price modified concurrently in DB
        $bundleA->update(['bundle_sale_price' => 48000]);

        $payload = [
            'prices' => [
                ['setting_id' => 1, 'sale_price' => 100, 'tier_1_price' => 90, 'tier_2_price' => 80, 'last_purchase_price' => 50, 'version' => null],
                ['setting_id' => 2, 'sale_price' => 110, 'tier_1_price' => 95, 'tier_2_price' => 85, 'last_purchase_price' => 55, 'version' => null],
            ],
            'conversions' => [],
            'conversion_snapshot' => $convSnapshot['data'],
            'conversion_snapshot_signature' => $convSnapshot['signature'],
            'bundles' => [
                [
                    'bundle_id' => $bundleA->id,
                    'setting_id' => 1,
                    'replica_group_uuid' => $groupUuid,
                    'bundle_sale_price' => 50000,
                    'version' => '2020-01-01 00:00:00.000000', // Stale version
                ],
            ],
            'bundle_snapshot' => $bundleSnapshot['data'],
            'bundle_snapshot_signature' => $bundleSnapshot['signature'],
        ];

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payload)
            ->assertSessionHas('error', "Harga paket telah diperbarui oleh pengguna lain. Silakan muat ulang halaman.");
    }

    public function test_forged_or_unavailable_bundle_cell_submission_is_rejected()
    {
        $groupUuid = (string) \Illuminate\Support\Str::uuid();

        $bundleA = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $this->product->id,
            'setting_id' => 1,
            'replica_group_uuid' => $groupUuid,
            'name' => 'Paket Solo',
            'bundle_sale_price' => 40000,
            'is_active' => true,
        ]);

        $service = app(\Modules\Product\Services\CrossBusinessPriceService::class);
        $convSnapshot = $service->generateConversionSnapshot($this->product);
        $bundleSnapshot = $service->generateBundleSnapshot($this->product);

        // Payload fabricates a bundle entry for Setting 2 which does not exist
        $payload = [
            'prices' => [
                ['setting_id' => 1, 'sale_price' => 100, 'tier_1_price' => 90, 'tier_2_price' => 80, 'last_purchase_price' => 50, 'version' => null],
                ['setting_id' => 2, 'sale_price' => 110, 'tier_1_price' => 95, 'tier_2_price' => 85, 'last_purchase_price' => 55, 'version' => null],
            ],
            'conversions' => [],
            'conversion_snapshot' => $convSnapshot['data'],
            'conversion_snapshot_signature' => $convSnapshot['signature'],
            'bundles' => [
                [
                    'bundle_id' => $bundleA->id,
                    'setting_id' => 1,
                    'replica_group_uuid' => $groupUuid,
                    'bundle_sale_price' => 40000,
                    'version' => $bundleA->updated_at->format('Y-m-d H:i:s.u'),
                ],
                [
                    'bundle_id' => 999999, // Fabricated bundle ID
                    'setting_id' => 2,
                    'replica_group_uuid' => $groupUuid,
                    'bundle_sale_price' => 40000,
                    'version' => null,
                ],
            ],
            'bundle_snapshot' => $bundleSnapshot['data'],
            'bundle_snapshot_signature' => $bundleSnapshot['signature'],
        ];

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payload)
            ->assertSessionHasErrors(['bundles.1.bundle_id']);
    }

    public function test_duplicate_submitted_bundle_or_setting_group_is_rejected()
    {
        $groupUuid = (string) \Illuminate\Support\Str::uuid();

        $bundleA = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $this->product->id,
            'setting_id' => 1,
            'replica_group_uuid' => $groupUuid,
            'name' => 'Paket Duplikat',
            'bundle_sale_price' => 40000,
            'is_active' => true,
        ]);

        $service = app(\Modules\Product\Services\CrossBusinessPriceService::class);
        $convSnapshot = $service->generateConversionSnapshot($this->product);
        $bundleSnapshot = $service->generateBundleSnapshot($this->product);

        $payload = [
            'prices' => [
                ['setting_id' => 1, 'sale_price' => 100, 'tier_1_price' => 90, 'tier_2_price' => 80, 'last_purchase_price' => 50, 'version' => null],
                ['setting_id' => 2, 'sale_price' => 110, 'tier_1_price' => 95, 'tier_2_price' => 85, 'last_purchase_price' => 55, 'version' => null],
            ],
            'conversions' => [],
            'conversion_snapshot' => $convSnapshot['data'],
            'conversion_snapshot_signature' => $convSnapshot['signature'],
            'bundles' => [
                [
                    'bundle_id' => $bundleA->id,
                    'setting_id' => 1,
                    'replica_group_uuid' => $groupUuid,
                    'bundle_sale_price' => 40000,
                    'version' => $bundleA->updated_at->format('Y-m-d H:i:s.u'),
                ],
                [
                    'bundle_id' => $bundleA->id, // DUPLICATE bundle ID
                    'setting_id' => 1,
                    'replica_group_uuid' => $groupUuid,
                    'bundle_sale_price' => 45000,
                    'version' => $bundleA->updated_at->format('Y-m-d H:i:s.u'),
                ],
            ],
            'bundle_snapshot' => $bundleSnapshot['data'],
            'bundle_snapshot_signature' => $bundleSnapshot['signature'],
        ];

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payload)
            ->assertSessionHasErrors(['bundles.1.bundle_id', 'bundles.1.replica_group_uuid']);
    }

    public function test_missing_submitted_existing_bundle_cell_is_rejected()
    {
        $groupUuid = (string) \Illuminate\Support\Str::uuid();

        $bundleA = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $this->product->id,
            'setting_id' => 1,
            'replica_group_uuid' => $groupUuid,
            'name' => 'Paket A',
            'bundle_sale_price' => 40000,
            'is_active' => true,
        ]);

        $bundleB = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $this->product->id,
            'setting_id' => 2,
            'replica_group_uuid' => $groupUuid,
            'name' => 'Paket B',
            'bundle_sale_price' => 45000,
            'is_active' => true,
        ]);

        $service = app(\Modules\Product\Services\CrossBusinessPriceService::class);
        $convSnapshot = $service->generateConversionSnapshot($this->product);
        $bundleSnapshot = $service->generateBundleSnapshot($this->product);

        // Submitting only bundle A while bundle B is omitted from the submission
        $payload = [
            'prices' => [
                ['setting_id' => 1, 'sale_price' => 100, 'tier_1_price' => 90, 'tier_2_price' => 80, 'last_purchase_price' => 50, 'version' => null],
                ['setting_id' => 2, 'sale_price' => 110, 'tier_1_price' => 95, 'tier_2_price' => 85, 'last_purchase_price' => 55, 'version' => null],
            ],
            'conversions' => [],
            'conversion_snapshot' => $convSnapshot['data'],
            'conversion_snapshot_signature' => $convSnapshot['signature'],
            'bundles' => [
                [
                    'bundle_id' => $bundleA->id,
                    'setting_id' => 1,
                    'replica_group_uuid' => $groupUuid,
                    'bundle_sale_price' => 42000,
                    'version' => $bundleA->updated_at->format('Y-m-d H:i:s.u'),
                ],
            ],
            'bundle_snapshot' => $bundleSnapshot['data'],
            'bundle_snapshot_signature' => $bundleSnapshot['signature'],
        ];

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payload)
            ->assertSessionHas('error', "Data harga paket yang dikirimkan tidak lengkap atau berlebih.");
    }

    public function test_tampered_bundle_snapshot_is_rejected()
    {
        $groupUuid = (string) \Illuminate\Support\Str::uuid();

        $bundleA = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $this->product->id,
            'setting_id' => 1,
            'replica_group_uuid' => $groupUuid,
            'name' => 'Paket Tamper',
            'bundle_sale_price' => 40000,
            'is_active' => true,
        ]);

        $service = app(\Modules\Product\Services\CrossBusinessPriceService::class);
        $convSnapshot = $service->generateConversionSnapshot($this->product);
        $bundleSnapshot = $service->generateBundleSnapshot($this->product);

        // Tamper snapshot data
        $tamperedData = base64_encode(json_encode(['product_id' => 99999]));

        $payload = [
            'prices' => [
                ['setting_id' => 1, 'sale_price' => 100, 'tier_1_price' => 90, 'tier_2_price' => 80, 'last_purchase_price' => 50, 'version' => null],
                ['setting_id' => 2, 'sale_price' => 110, 'tier_1_price' => 95, 'tier_2_price' => 85, 'last_purchase_price' => 55, 'version' => null],
            ],
            'conversions' => [],
            'conversion_snapshot' => $convSnapshot['data'],
            'conversion_snapshot_signature' => $convSnapshot['signature'],
            'bundles' => [
                [
                    'bundle_id' => $bundleA->id,
                    'setting_id' => 1,
                    'replica_group_uuid' => $groupUuid,
                    'bundle_sale_price' => 42000,
                    'version' => $bundleA->updated_at->format('Y-m-d H:i:s.u'),
                ],
            ],
            'bundle_snapshot' => $tamperedData,
            'bundle_snapshot_signature' => $bundleSnapshot['signature'],
        ];

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payload)
            ->assertSessionHas('error', "Bukti data harga paket telah diubah atau tidak valid. Silakan muat ulang halaman.");
    }

    public function test_atomic_rollback_across_base_conversion_and_bundle_on_bundle_failure()
    {
        $boxUnit = \Modules\Setting\Entities\Unit::firstOrCreate(['name' => 'KOTAK', 'short_name' => 'KTK']);
        $conv = \Modules\Product\Entities\ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $this->product->base_unit_id,
            'conversion_factor' => 12,
        ]);
        $convPrice1 = \Modules\Product\Entities\ProductUnitConversionPrice::create([
            'product_unit_conversion_id' => $conv->id,
            'setting_id' => 1,
            'price' => 20000,
        ]);

        $groupUuid = (string) \Illuminate\Support\Str::uuid();
        $bundleA = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $this->product->id,
            'setting_id' => 1,
            'replica_group_uuid' => $groupUuid,
            'name' => 'Paket Rollback',
            'bundle_sale_price' => 40000,
            'is_active' => true,
        ]);

        $service = app(\Modules\Product\Services\CrossBusinessPriceService::class);
        $convSnapshot = $service->generateConversionSnapshot($this->product);
        $bundleSnapshot = $service->generateBundleSnapshot($this->product);

        // Initial base price row for setting 1
        $basePrice1 = \Modules\Product\Entities\ProductPrice::create([
            'product_id' => $this->product->id,
            'setting_id' => 1,
            'sale_price' => 1000,
            'tier_1_price' => 900,
            'tier_2_price' => 800,
            'last_purchase_price' => 500,
        ]);

        // Mock ProductPriceFeedRecorder to throw an exception during recording (after conversion, bundle, and base updates have executed)
        $mockRecorder = $this->mock(\Modules\Product\Services\ProductPriceFeedRecorder::class);
        $mockRecorder->shouldReceive('record')
            ->andThrow(new \Exception("Simulated Feed Recorder Failure"));

        // Payload updates conversion price (20000->99000), bundle price (40000->88000), and base price (1000->5000)
        $payload = [
            'prices' => [
                ['setting_id' => 1, 'sale_price' => 5000, 'tier_1_price' => 4500, 'tier_2_price' => 4000, 'last_purchase_price' => 2500, 'version' => $basePrice1->updated_at->format('Y-m-d H:i:s.u')],
                ['setting_id' => 2, 'sale_price' => 110, 'tier_1_price' => 95, 'tier_2_price' => 85, 'last_purchase_price' => 55, 'version' => null],
            ],
            'conversions' => [
                ['setting_id' => 1, 'conversion_id' => $conv->id, 'price' => '99000.00', 'version' => $convPrice1->updated_at->format('Y-m-d H:i:s.u')],
                ['setting_id' => 2, 'conversion_id' => $conv->id, 'price' => '', 'version' => null],
            ],
            'conversion_snapshot' => $convSnapshot['data'],
            'conversion_snapshot_signature' => $convSnapshot['signature'],
            'bundles' => [
                [
                    'bundle_id' => $bundleA->id,
                    'setting_id' => 1,
                    'replica_group_uuid' => $groupUuid,
                    'bundle_sale_price' => 88000,
                    'version' => $bundleA->updated_at->format('Y-m-d H:i:s.u'),
                ],
            ],
            'bundle_snapshot' => $bundleSnapshot['data'],
            'bundle_snapshot_signature' => $bundleSnapshot['signature'],
        ];

        $initialFeedCount = \Modules\Product\Entities\ProductPriceFeedEvent::count();

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payload)
            ->assertSessionHas('error', "Simulated Feed Recorder Failure");

        // Verify base price was rolled back
        $this->assertEquals(1000, (float) $basePrice1->fresh()->sale_price);

        // Verify conversion price was rolled back
        $this->assertEquals(20000, (float) $convPrice1->fresh()->price);

        // Verify bundle price was rolled back
        $this->assertEquals(40000, (float) $bundleA->fresh()->bundle_sale_price);

        // Verify no feed event was recorded
        $this->assertEquals($initialFeedCount, \Modules\Product\Entities\ProductPriceFeedEvent::count());
    }

    public function test_explicit_zero_bundle_price_versus_missing_copy_semantics()
    {
        $groupUuid = (string) \Illuminate\Support\Str::uuid();

        // Bundle A in Setting 1 has price 0 (explicit zero)
        $bundleA = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $this->product->id,
            'setting_id' => 1,
            'replica_group_uuid' => $groupUuid,
            'name' => 'Paket Promo Gratis',
            'bundle_sale_price' => 0.00,
            'is_active' => true,
        ]);

        // Setting 2 is missing (no bundle copy exists)
        $service = app(\Modules\Product\Services\CrossBusinessPriceService::class);
        $bundlesData = $service->loadBundlePricesForProduct($this->product);

        $row1 = collect($bundlesData['matrix'])->firstWhere('setting_id', 1);
        $row2 = collect($bundlesData['matrix'])->firstWhere('setting_id', 2);

        $cell1 = collect($row1['bundles'])->firstWhere('replica_group_uuid', $groupUuid);
        $cell2 = collect($row2['bundles'])->firstWhere('replica_group_uuid', $groupUuid);

        // Cell 1 exists with price 0
        $this->assertTrue($cell1['is_existing']);
        $this->assertEquals(0, $cell1['price']);
        $this->assertNotNull($cell1['bundle_id']);

        // Cell 2 is missing
        $this->assertFalse($cell2['is_existing']);
        $this->assertNull($cell2['price']);
        $this->assertNull($cell2['bundle_id']);

        // Save explicitly maintaining 0
        $convSnapshot = $service->generateConversionSnapshot($this->product);
        $bundleSnapshot = $service->generateBundleSnapshot($this->product);

        $payload = [
            'prices' => [
                ['setting_id' => 1, 'sale_price' => 100, 'tier_1_price' => 90, 'tier_2_price' => 80, 'last_purchase_price' => 50, 'version' => null],
                ['setting_id' => 2, 'sale_price' => 110, 'tier_1_price' => 95, 'tier_2_price' => 85, 'last_purchase_price' => 55, 'version' => null],
            ],
            'conversions' => [],
            'conversion_snapshot' => $convSnapshot['data'],
            'conversion_snapshot_signature' => $convSnapshot['signature'],
            'bundles' => [
                [
                    'bundle_id' => $bundleA->id,
                    'setting_id' => 1,
                    'replica_group_uuid' => $groupUuid,
                    'bundle_sale_price' => 0,
                    'version' => $bundleA->updated_at->format('Y-m-d H:i:s.u'),
                ],
            ],
            'bundle_snapshot' => $bundleSnapshot['data'],
            'bundle_snapshot_signature' => $bundleSnapshot['signature'],
        ];

        $this->actingAs($this->user)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), $payload)
            ->assertRedirect(route('products.cross-business-prices.edit', $this->product))
            ->assertSessionHas('success');

        $this->assertEquals(0, (float) $bundleA->fresh()->bundle_sale_price);

        // Setting 2 must STILL have no bundle copy created
        $this->assertNull(\Modules\Product\Entities\ProductBundle::where('parent_product_id', $this->product->id)->where('setting_id', 2)->first());
    }

    public function test_null_or_empty_replica_group_uuid_bundles_are_excluded()
    {
        // Null replica_group_uuid bundle
        \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $this->product->id,
            'setting_id' => 1,
            'replica_group_uuid' => null,
            'name' => 'Paket Null Lineage',
            'bundle_sale_price' => 30000,
            'is_active' => true,
        ]);

        // Empty string replica_group_uuid bundle
        \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $this->product->id,
            'setting_id' => 1,
            'replica_group_uuid' => '',
            'name' => 'Paket Empty Lineage',
            'bundle_sale_price' => 35000,
            'is_active' => true,
        ]);

        $service = app(\Modules\Product\Services\CrossBusinessPriceService::class);
        $bundlesData = $service->loadBundlePricesForProduct($this->product);

        $this->assertEmpty($bundlesData['headers']);
        $this->assertEmpty($bundlesData['matrix']);
    }
}

