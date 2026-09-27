<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Services\ProductCanonicalizer;
use Modules\Product\Services\ProductResolver;
use Modules\Product\Exceptions\AmbiguousProductResolutionException;
use Tests\TestCase;

class ProductMigrationBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_leaves_both_collision_members_null()
    {
        $migration = require database_path('migrations/2026_08_07_193407_add_canonical_name_to_products_table.php');
        
        // Temporarily rollback this specific migration to simulate pre-migration state
        $migration->down();

        // Insert legacy formatting-equivalent products (no canonical_name column exists now)
        DB::table('products')->insert([
            [
                'product_name' => 'Item X',
                'setting_id' => 1,
                'unit_id' => 1,
                'base_unit_id' => 1,
                'product_cost' => 0,
                'product_price' => 0,
                'product_quantity' => 0,
                'stock_managed' => 1,
                'is_purchased' => 1,
                'is_sold' => 1,
            ],
            [
                'product_name' => 'Item   X',
                'setting_id' => 1,
                'unit_id' => 1,
                'base_unit_id' => 1,
                'product_cost' => 0,
                'product_price' => 0,
                'product_quantity' => 0,
                'stock_managed' => 1,
                'is_purchased' => 1,
                'is_sold' => 1,
            ]
        ]);

        // Run the migration up
        $migration->up();

        // Verify both are null
        $products = Product::whereIn('product_name', ['Item X', 'Item   X'])->get();
        $this->assertCount(2, $products);
        foreach ($products as $p) {
            $this->assertNull($p->canonical_name);
        }

        // Verify resolver reports ambiguity and does not select a survivor
        $resolver = new ProductResolver(new ProductCanonicalizer());
        
        $this->expectException(AmbiguousProductResolutionException::class);
        $resolver->resolveExisting('Item X');
    }

    public function test_resolver_reports_ambiguity_when_one_has_key_and_other_is_null()
    {
        // Simulate a state where one product has the key and another is NULL (e.g. manual tampering or partial backfill)
        Product::create([
            'product_name' => 'Product Y',
            'canonical_name' => 'product y',
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
            'product_name' => 'Product   Y',
            'canonical_name' => null, // NULL collision member
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

        $resolver = new ProductResolver(new ProductCanonicalizer());
        
        // The resolver MUST combine canonical matches with NULL legacy matches and throw
        $this->expectException(AmbiguousProductResolutionException::class);
        $resolver->resolveOrCreate('Product Y', function() {
            return [];
        });
    }
}
