<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Tests\TestCase;

class ProductModelCasingTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_preserves_casing_for_product_name_and_canonical_name()
    {
        $product = Product::create([
            'product_name' => 'Mixed Casing Product',
            'canonical_name' => 'mixed casing product',
            'product_code' => 'MC-1',
            'setting_id' => 1,
            'unit_id' => 1,
            'base_unit_id' => 1,
            'product_cost' => 0,
            'product_price' => 0,
            'product_quantity' => 0,
            'stock_managed' => 1,
            'is_purchased' => 1,
            'is_sold' => 1,
        ]);

        $this->assertEquals('Mixed Casing Product', $product->product_name);
        $this->assertEquals('mixed casing product', $product->canonical_name);
        
        // Ensure other string fields are still uppercased by BaseModel
        $this->assertEquals('MC-1', $product->product_code);
    }
}
