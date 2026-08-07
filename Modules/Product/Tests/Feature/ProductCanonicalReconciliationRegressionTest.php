<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductMergeEvent;
use Modules\Product\Services\ProductMergeService;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ProductCanonicalReconciliationRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::factory()->create();
    }

    private function createProduct(string $name, string $code): int
    {
        return DB::table('products')->insertGetId([
            'product_code' => $code,
            'product_name' => $name,
            'setting_id' => 1,
            'unit_id' => 1,
            'stock_managed' => 1,
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_price' => 0,
            'is_sold' => 1,
            'is_purchased' => 1,
            'purchase_price' => 0,
            'sale_price' => 0,
            'product_tax_type' => 1,
        ]);
    }

    /**
     * Test the regression case where ambiguous price-upload names
     * (case variants like 'LAPTOP' and 'laptop') are reconciled
     * and then resolve to exactly one product.
     */
    public function test_ambiguous_price_upload_names_resolve_to_one_product_after_reconciliation(): void
    {
        // Step 1: Create ambiguous products (case variants)
        $laptop1Id = $this->createProduct('LAPTOP', 'LAPTOP-001');
        $laptop2Id = $this->createProduct('laptop', 'LAPTOP-002');

        $laptop1 = Product::findOrFail($laptop1Id);
        $laptop2 = Product::findOrFail($laptop2Id);

        // Verify both are in database
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseHas('products', ['id' => $laptop1->id, 'product_name' => 'LAPTOP']);
        $this->assertDatabaseHas('products', ['id' => $laptop2->id, 'product_name' => 'laptop']);

        // Step 2: Verify they have the same canonical key
        $canonicalizer = app(\Modules\Product\Services\ProductCanonicalizer::class);
        $canonical1 = $canonicalizer->canonicalize($laptop1->product_name);
        $canonical2 = $canonicalizer->canonicalize($laptop2->product_name);
        $this->assertEquals($canonical1['canonical_key'], $canonical2['canonical_key']);

        // Step 3: Run the merge service to reconcile them
        $mergeService = app(ProductMergeService::class);

        $event = $mergeService->executeInternalRetirementTransaction(
            $laptop1,  // survivor
            [$laptop2],  // retired
            function (Product $survivor, Product $retired, $audit) {
                return new \Modules\Product\Services\DTOs\ProductReferenceMigrationResult(
                    isCompleted: true,
                    migratedCounts: [
                        'transactions' => 0,
                        'product_prices' => 0,
                        'product_stocks' => 0,
                        'purchase_details' => 0,
                        'sale_details' => 0,
                        'dispatch_details' => 0,
                        'sale_return_details' => 0,
                        'purchase_return_details' => 0,
                        'product_bundles' => 0,
                        'product_bundle_items' => 0,
                        'product_unit_conversions' => 0,
                    ]
                );
            },
            null
        );

        $this->assertInstanceOf(ProductMergeEvent::class, $event);
        $this->assertTrue($event->exists);

        // Step 4: Verify the survivor now has the canonical key
        $survivor = Product::find($laptop1->id);
        $this->assertNotNull($survivor->canonical_name);
        $this->assertEquals($survivor->canonical_name, $canonical1['canonical_key']);

        // Step 5: Verify the retired product is marked as merged
        $retired = Product::find($laptop2->id);
        $this->assertEquals($retired->merged_into_id, $laptop1->id);
        $this->assertNull($retired->canonical_name);

        // Step 6: Verify resolveExisting now finds exactly one product
        $resolver = app(\Modules\Product\Services\ProductResolver::class);
        $resolved = $resolver->resolveExisting('LAPTOP');

        // Should resolve to exactly the survivor
        $this->assertNotNull($resolved);
        $this->assertEquals($resolved->id, $laptop1->id);

        // Step 7: Verify the retired product no longer matches when looking up
        // The resolver should still find the survivor when looking up the retired product's original name
        $resolvedByLowercase = $resolver->resolveExisting('laptop');
        $this->assertNotNull($resolvedByLowercase);
        $this->assertEquals($resolvedByLowercase->id, $laptop1->id);
    }

    /**
     * Test that after reconciliation, multiple case variants
     * all resolve to the same product.
     */
    public function test_multiple_case_variants_resolve_to_survivor_after_merge(): void
    {
        // Create three case variants
        $originalId = $this->createProduct('Keyboard', 'KB-001');
        $variant1Id = $this->createProduct('KEYBOARD', 'KB-002');
        $variant2Id = $this->createProduct('keyboard', 'KB-003');

        $original = Product::findOrFail($originalId);
        $variant1 = Product::findOrFail($variant1Id);
        $variant2 = Product::findOrFail($variant2Id);

        $this->assertDatabaseCount('products', 3);

        // Reconcile them all to the original
        $mergeService = app(ProductMergeService::class);

        $event = $mergeService->executeInternalRetirementTransaction(
            $original,
            [$variant1, $variant2],
            function (Product $survivor, Product $retired, $audit) {
                return new \Modules\Product\Services\DTOs\ProductReferenceMigrationResult(
                    isCompleted: true,
                    migratedCounts: array_fill_keys(
                        array_keys(\Modules\Product\Services\DTOs\ProductReferenceInventory::supportedRelations()),
                        0
                    )
                );
            },
            null
        );

        $this->assertTrue($event->exists);

        // Verify all three case variants resolve to the original
        $resolver = app(\Modules\Product\Services\ProductResolver::class);

        foreach (['Keyboard', 'KEYBOARD', 'keyboard'] as $name) {
            $resolved = $resolver->resolveExisting($name);
            $this->assertNotNull($resolved, "Failed to resolve '$name'");
            $this->assertEquals($resolved->id, $original->id, "Resolved '$name' to wrong product");
        }
    }
}
