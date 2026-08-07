<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Exceptions\AmbiguousProductResolutionException;
use Modules\Product\Services\ProductCanonicalizer;
use Modules\Product\Services\ProductResolver;
use Tests\TestCase;

class ProductResolverTest extends TestCase
{
    use RefreshDatabase;

    protected ProductResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ProductResolver(new ProductCanonicalizer());
    }

    public function test_resolve_existing_finds_by_canonical_name()
    {
        Product::create([
            'product_name' => 'Existing Product',
            'canonical_name' => 'existing product',
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

        $product = $this->resolver->resolveExisting(' *  ExisTing prOduct TP ');
        
        $this->assertNotNull($product);
        $this->assertEquals('existing product', $product->canonical_name);
    }

    public function test_resolve_existing_matches_legacy_product_without_canonical_name()
    {
        Product::create([
            'product_name' => 'Legacy   Product  Name',
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

        // canonicalKey will be "legacy product name"
        $product = $this->resolver->resolveExisting(' *  Legacy Product Name TP ');
        
        $this->assertNotNull($product);
        $this->assertEquals('Legacy   Product  Name', $product->product_name);
    }

    public function test_resolve_existing_throws_on_ambiguous_legacy_products()
    {
        Product::create([
            'product_name' => 'Legacy Dup',
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

        Product::create([
            'product_name' => 'Legacy   Dup',
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

        $this->expectException(AmbiguousProductResolutionException::class);
        $this->resolver->resolveExisting('Legacy Dup');
    }

    public function test_retired_legacy_null_duplicate_no_longer_makes_matching_ambiguous()
    {
        $survivor = Product::create([
            'product_name' => 'Legacy Dup',
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

        Product::create([
            'product_name' => 'Legacy   Dup',
            'setting_id' => 1,
            'unit_id' => 1,
            'base_unit_id' => 1,
            'product_cost' => 0,
            'product_price' => 0,
            'product_quantity' => 0,
            'stock_managed' => 1,
            'is_purchased' => 1,
            'is_sold' => 1,
            'merged_into_id' => $survivor->id,
        ]);

        // It should not throw an exception anymore because the retired product is excluded
        $product = $this->resolver->resolveExisting('Legacy Dup');
        
        $this->assertNotNull($product);
        $this->assertEquals($survivor->id, $product->id);
    }

    public function test_resolve_existing_returns_null_if_not_found()
    {
        $product = $this->resolver->resolveExisting('Unknown Product');
        $this->assertNull($product);
    }

    public function test_resolve_or_create_creates_new_product_with_canonical_name()
    {
        $product = $this->resolver->resolveOrCreate(' *  New Product TP ', function ($displayName, $canonicalKey) {
            return [
                'product_code' => 'NP-1',
                'setting_id' => 1,
                'unit_id' => 1,
                'base_unit_id' => 1,
                'product_cost' => 0,
                'product_price' => 0,
                'product_quantity' => 0,
                'stock_managed' => 1,
                'is_purchased' => 1,
                'is_sold' => 1,
            ];
        });

        $this->assertNotNull($product);
        $this->assertEquals('New Product', $product->product_name);
        $this->assertEquals('new product', $product->canonical_name);
        $this->assertEquals('NP-1', $product->product_code);
    }

    public function test_resolve_or_create_returns_existing_if_already_exists()
    {
        Product::create([
            'product_name' => 'Existing Product',
            'canonical_name' => 'existing product',
            'product_code' => 'EP-1',
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

        $product = $this->resolver->resolveOrCreate('  Existing ProdUCT  ', function () {
            return ['product_code' => 'SHOULD-NOT-USE'];
        });

        $this->assertEquals('EP-1', $product->product_code);
        $this->assertEquals(1, Product::where('canonical_name', 'existing product')->count());
    }

    public function test_resolve_or_create_handles_concurrent_creation()
    {
        // Use the real resolver
        $resolver = $this->resolver;

        $product = $resolver->resolveOrCreate('Concurrent Item', function ($displayName, $canonicalKey) {
            
            // This closure is called AFTER the initial lookup fails, 
            // but BEFORE the resolver attempts to Product::create()
            // We simulate a concurrent insert here by creating it first.
            Product::create([
                'product_name' => $displayName,
                'canonical_name' => $canonicalKey,
                'setting_id' => 1,
                'unit_id' => 1,
                'base_unit_id' => 1,
                'product_cost' => 0,
                'product_price' => 0,
                'product_quantity' => 0,
                'stock_managed' => 1,
                'is_purchased' => 1,
                'is_sold' => 1,
                'product_code' => 'WINNER'
            ]);

            // When the resolver gets this array and tries to create,
            // it will throw a QueryException for unique constraint violation on canonical_name
            return [
                'setting_id' => 1,
                'unit_id' => 1,
                'base_unit_id' => 1,
                'product_cost' => 0,
                'product_price' => 0,
                'product_quantity' => 0,
                'stock_managed' => 1,
                'is_purchased' => 1,
                'is_sold' => 1,
                'product_code' => 'LOSER'
            ];
        });

        $this->assertNotNull($product);
        $this->assertEquals('concurrent item', $product->canonical_name);
        $this->assertEquals('WINNER', $product->product_code);
    }
}
