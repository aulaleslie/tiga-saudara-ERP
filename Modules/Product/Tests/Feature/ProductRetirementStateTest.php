<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Tests\TestCase;

class ProductRetirementStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        
        \Illuminate\Support\Facades\DB::table('settings')->insert([
            'id' => 1,
            'company_name' => 'Test',
            'company_email' => 'test@test.com',
            'company_phone' => '123',
            'site_logo' => 'logo',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'test@test.com',
            'footer_text' => 'text',
            'company_address' => 'address'
        ]);
        
        \Illuminate\Support\Facades\DB::table('users')->insert([
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test' . uniqid() . '@example.com',
            'password' => 'password',
            'is_active' => 1,
        ]);
        
        \Illuminate\Support\Facades\DB::table('categories')->insert([
            'id' => 1,
            'category_code' => 'CAT1',
            'category_name' => 'Category 1',
            'created_by' => 1,
            'setting_id' => 1
        ]);
        
        \Illuminate\Support\Facades\DB::table('units')->insert([
            'id' => 1,
            'name' => 'Unit 1',
            'short_name' => 'U1',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => 1
        ]);
        
        \Illuminate\Support\Facades\DB::table('brands')->insert([
            'id' => 1,
            'name' => 'Brand 1',
            'created_by' => 1,
            'setting_id' => 1
        ]);
    }

    protected function createProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'setting_id' => 1,
            'product_name' => 'Test Product ' . uniqid(),
            'product_code' => 'TEST-' . uniqid(),
            'product_cost' => 5,
            'product_price' => 10,
            'product_quantity' => 10,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'product_note' => null,
            'category_id' => 1,
            'unit_id' => 1,
            'product_barcode_symbology' => 'CODE128',
            'product_stock_alert' => 0,
            'brand_id' => 1,
            'base_unit_id' => 1,
        ], $attributes));
    }

    public function test_it_filters_active_and_retired_products_via_scopes()
    {
        $activeProduct = $this->createProduct([
            'merged_into_id' => null,
            'merged_at' => null,
        ]);

        $survivorProduct = $this->createProduct([
            'merged_into_id' => null,
            'merged_at' => null,
        ]);

        $retiredProduct = $this->createProduct([
            'merged_into_id' => $survivorProduct->id,
            'merged_at' => now(),
        ]);

        $activeIds = Product::active()->pluck('id')->toArray();
        $this->assertContains($activeProduct->id, $activeIds);
        $this->assertContains($survivorProduct->id, $activeIds);
        $this->assertNotContains($retiredProduct->id, $activeIds);

        $retiredIds = Product::retired()->pluck('id')->toArray();
        $this->assertContains($retiredProduct->id, $retiredIds);
        $this->assertNotContains($activeProduct->id, $retiredIds);
        $this->assertNotContains($survivorProduct->id, $retiredIds);
    }

    public function test_merged_relationships()
    {
        $survivorProduct = $this->createProduct();

        $retiredProduct1 = $this->createProduct([
            'merged_into_id' => $survivorProduct->id,
            'merged_at' => now(),
        ]);

        $retiredProduct2 = $this->createProduct([
            'merged_into_id' => $survivorProduct->id,
            'merged_at' => now(),
        ]);

        // Check mergedInto
        $this->assertEquals($survivorProduct->id, $retiredProduct1->mergedInto->id);
        
        // Check mergedFrom
        $this->assertCount(2, $survivorProduct->mergedFrom);
        $mergedFromIds = $survivorProduct->mergedFrom->pluck('id')->toArray();
        $this->assertContains($retiredProduct1->id, $mergedFromIds);
        $this->assertContains($retiredProduct2->id, $mergedFromIds);
    }
}
