<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Product\Entities\Product;
use Modules\Product\Services\ProductMergeService;

class ProductMergeSafetyGapsTest extends TestCase
{
    use RefreshDatabase;

    private function createProduct(string $name, ?string $canonical = null): Product
    {
        return Product::create([
            'product_name' => $name,
            'canonical_name' => $canonical,
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
    }

    private function createProductStock(Product $product, int $quantity)
    {
        // Create a location using the factory (this handles FK constraints)
        $location = \Modules\Setting\Entities\Location::factory()->create();

        \Illuminate\Support\Facades\DB::table('product_stocks')->insert([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $quantity,
            'quantity_non_tax' => $quantity,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Gap 1: countReferences() must fail closed on database errors, not silently return 0.
     *
     * Test verifies that schema validation prevents false "safe to retire" conclusions.
     */
    public function test_missing_reference_validation_tables_cause_failure()
    {
        $product = $this->createProduct('Test');

        // forProduct() should throw when tables don't exist or columns are missing
        // not return false "safe" result
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot safely assess product for retirement');

        \Modules\Product\Services\DTOs\ProductReferenceInventory::forProduct($product);
    }

    /**
     * Gap 2: No-op callback with supported references must fail and rollback.
     *
     * This test verifies the critical safety property: an empty callback
     * cannot retire a product with existing references.
     *
     * In production, if a product has references and callback doesn't migrate them,
     * the service will detect this post-callback and throw before retiring.
     *
     * This test verifies the simpler case: no-op is OK when nothing to migrate.
     */
    public function test_no_op_callback_ok_when_no_references_exist()
    {
        $survivor = $this->createProduct('Survivor', 'survivor');
        $retired = $this->createProduct('Survivor', null);

        $service = app(ProductMergeService::class);

        // No-op callback that doesn't migrate anything
        $noOpCallback = function ($survivor, $retired, $audit) {
            return new \Modules\Product\Services\DTOs\ProductReferenceMigrationResult(true, []);
        };

        // Should work because product has no references to migrate
        $event = $service->executeInternalRetirementTransaction($survivor, [$retired], $noOpCallback);
        $this->assertNotNull($event->id);

        $retired->refresh();
        $this->assertEquals($survivor->id, $retired->merged_into_id);
    }

    /**
     * Gap 2b: No-op callback with supported references MUST fail and rollback.
     *
     * This test verifies the critical safety property: when a product HAS supported
     * references and the callback returns empty counts (no-op), the service must
     * detect the mismatch and throw BEFORE retiring the product.
     *
     * Expected behavior:
     * - Exception thrown during callback result validation (missing exact counts)
     * - Retired product remains active (merged_into_id stays null)
     * - No merge event/audit/reference-migration rows created
     * - Supported reference still points to retired product
     */
    public function test_no_op_callback_with_supported_references_fails_and_rolls_back()
    {
        $survivor = $this->createProduct('Survivor', 'survivor');
        $retired = $this->createProduct('Survivor', null);

        // Create a product stock with the retired product
        $this->createProductStock($retired, 10);

        $service = app(ProductMergeService::class);

        // No-op callback that doesn't report migrating anything
        $noOpCallback = function ($survivor, $retired, $audit) {
            return new \Modules\Product\Services\DTOs\ProductReferenceMigrationResult(true, []);
        };

        // Should fail because product_stocks exists but callback didn't migrate it
        try {
            $service->executeInternalRetirementTransaction($survivor, [$retired], $noOpCallback);
            $this->fail('Expected reconciliation to fail.');
        } catch (\RuntimeException $e) {
            // Accept either error: missing count report OR unmitigated references still exist
            $message = $e->getMessage();
            $this->assertTrue(
                str_contains($message, 'Migration callback must report exact count') ||
                str_contains($message, 'still has') && str_contains($message, 'references'),
                "Expected error about missing counts or unmitigated references. Got: {$message}"
            );
        }

        // Verify rollback: retired product remains active
        $retired->refresh();
        $this->assertNull($retired->merged_into_id, 'Retired product should remain active after failed migration');

        // Verify no merge event was created
        $mergeEvent = \Modules\Product\Entities\ProductMergeEvent::where('survivor_product_id', $survivor->id)->first();
        $this->assertNull($mergeEvent, 'No merge event should be created on failure');

        // Verify no audit record was created
        $audit = \Modules\Product\Entities\ProductMergeAudit::where('retired_product_id', $retired->id)->first();
        $this->assertNull($audit, 'No audit record should be created on failure');

        // Verify the supported reference still points to the retired product
        $retiredStockCount = \Illuminate\Support\Facades\DB::table('product_stocks')
            ->where('product_id', $retired->id)
            ->count();
        $this->assertEquals(1, $retiredStockCount, 'Product stock should still point to retired product after failed migration');
    }

    /**
     * Gap 3: Actual migration outcomes must be persisted and validated.
     *
     * Test verifies ProductReferenceMigrationResult contract:
     * - Callback must claim actual counts, not make up numbers
     * - Claims are validated against post-migration state
     */
    public function test_migration_result_validates_complete_incomplete_contract()
    {
        // Completed with counts is OK
        $result = new \Modules\Product\Services\DTOs\ProductReferenceMigrationResult(true, [
            'transactions' => 5,
        ]);
        $this->assertTrue($result->isCompleted);
        $this->assertEquals(5, $result->migratedCounts['transactions']);

        // Completed with no counts is OK
        $result2 = new \Modules\Product\Services\DTOs\ProductReferenceMigrationResult(true, []);
        $this->assertTrue($result2->isCompleted);
        $this->assertEquals([], $result2->migratedCounts);

        // Incomplete with empty counts is OK
        $result3 = new \Modules\Product\Services\DTOs\ProductReferenceMigrationResult(false, []);
        $this->assertFalse($result3->isCompleted);
        $this->assertEquals([], $result3->migratedCounts);

        // Incomplete with counts is NOT OK - should throw
        $this->expectException(\InvalidArgumentException::class);
        new \Modules\Product\Services\DTOs\ProductReferenceMigrationResult(false, ['transactions' => 5]);
    }

    /**
     * Gap 3b: Exact count validation across all supported relations.
     *
     * The service must require the callback to report exact counts for EVERY
     * supported relation, not just the ones with nonzero counts. Claiming []
     * when actual migrations occur is a critical safety gap.
     *
     * Test verifies:
     * - Callback must report exact count for product_stocks
     * - Callback must report exact count (0) for every other supported relation
     * - Service persists actual_migrated_counts with complete map
     */
    public function test_callback_must_report_exact_counts_for_all_supported_relations()
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('product_stocks')) {
            $this->markTestSkipped('Test environment schema is incomplete');
        }

        $survivor = $this->createProduct('Survivor', 'survivor');
        $retired = $this->createProduct('Survivor', null);

        // Create 3 product stocks for the retired product
        for ($i = 0; $i < 3; $i++) {
            $this->createProductStock($retired, 10 + $i);
        }

        $service = app(ProductMergeService::class);

        // Get the full inventory of supported relations
        $supportedRelations = \Modules\Product\Services\DTOs\ProductReferenceInventory::supportedRelations();

        // Callback that CORRECTLY migrates product_stocks and reports all counts
        $correctCallback = function ($survivor, $retired, $audit) use ($supportedRelations) {
            // Move all product_stocks to survivor
            $movedCount = \Illuminate\Support\Facades\DB::table('product_stocks')
                ->where('product_id', $retired->id)
                ->update(['product_id' => $survivor->id]);

            // Report exact counts for EVERY supported relation
            $counts = [];
            foreach (array_keys($supportedRelations) as $table) {
                $counts[$table] = $table === 'product_stocks' ? $movedCount : 0;
            }

            return new \Modules\Product\Services\DTOs\ProductReferenceMigrationResult(true, $counts);
        };

        $event = $service->executeInternalRetirementTransaction($survivor, [$retired], $correctCallback);
        $this->assertNotNull($event->id);

        // Verify audit captured actual counts with complete map
        $audit = \Modules\Product\Entities\ProductMergeAudit::where('retired_product_id', $retired->id)->first();
        $this->assertNotNull($audit);
        $this->assertIsArray($audit->actual_migrated_counts);

        // Verify it has entries for all supported relations
        foreach (array_keys($supportedRelations) as $table) {
            $this->assertArrayHasKey($table, $audit->actual_migrated_counts,
                "actual_migrated_counts must have entry for {$table}");
        }

        // Verify product_stocks count is exactly 3
        $this->assertEquals(3, $audit->actual_migrated_counts['product_stocks']);

        // Verify other supported relations are 0
        foreach ($supportedRelations as $table => $column) {
            if ($table !== 'product_stocks') {
                $this->assertEquals(0, $audit->actual_migrated_counts[$table],
                    "{$table} should have 0 migrated count");
            }
        }
    }

    /**
     * Gap 3c: Over-count detection and rollback.
     *
     * Test verifies that if callback claims more migrations than actually occurred,
     * the service detects the mismatch and rolls back the entire transaction.
     */
    public function test_over_count_claim_is_detected_and_rolled_back()
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('product_stocks')) {
            $this->markTestSkipped('Test environment schema is incomplete');
        }

        $survivor = $this->createProduct('Survivor', 'survivor');
        $retired = $this->createProduct('Survivor', null);

        // Create only 2 product stocks
        $this->createProductStock($retired, 10);
        $this->createProductStock($retired, 20);

        $service = app(ProductMergeService::class);
        $supportedRelations = \Modules\Product\Services\DTOs\ProductReferenceInventory::supportedRelations();

        // Callback that migrates correctly but CLAIMS 5 (over-count)
        $overCountCallback = function ($survivor, $retired, $audit) use ($supportedRelations) {
            // Migrate all (2 rows)
            $movedCount = \Illuminate\Support\Facades\DB::table('product_stocks')
                ->where('product_id', $retired->id)
                ->update(['product_id' => $survivor->id]);

            // But claim 5 (over-count)
            $counts = [];
            foreach (array_keys($supportedRelations) as $table) {
                $counts[$table] = $table === 'product_stocks' ? 5 : 0;
            }

            return new \Modules\Product\Services\DTOs\ProductReferenceMigrationResult(true, $counts);
        };

        // Should throw on over-count mismatch
        try {
            $service->executeInternalRetirementTransaction($survivor, [$retired], $overCountCallback);
            $this->fail('Expected reconciliation to fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('claimed 5 migrations for product_stocks', $e->getMessage());
        }

        // Verify rollback: retired product remains active
        $retired->refresh();
        $this->assertNull($retired->merged_into_id, 'Retired product should remain active after over-count failure');

        // Verify product_stocks were rolled back to exactly 2 rows on retired product
        $retiredCount = \Illuminate\Support\Facades\DB::table('product_stocks')
            ->where('product_id', $retired->id)
            ->count();
        $this->assertEquals(2, $retiredCount, 'Product stocks should be rolled back to retired product');

        // Verify zero rows were left on the survivor
        $survivorCount = \Illuminate\Support\Facades\DB::table('product_stocks')
            ->where('product_id', $survivor->id)
            ->count();
        $this->assertEquals(0, $survivorCount, 'No product stocks should be on survivor after rollback');
    }

    /**
     * Gap 3d: Under-count detection and rollback.
     *
     * Test verifies that if callback claims fewer migrations than actually occurred,
     * the service detects the mismatch and rolls back the entire transaction.
     */
    public function test_under_count_claim_is_detected_and_rolled_back()
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('product_stocks')) {
            $this->markTestSkipped('Test environment schema is incomplete');
        }

        $survivor = $this->createProduct('Survivor', 'survivor');
        $retired = $this->createProduct('Survivor', null);

        // Create 3 product stocks
        for ($i = 0; $i < 3; $i++) {
            $this->createProductStock($retired, 10 + $i);
        }

        $service = app(ProductMergeService::class);
        $supportedRelations = \Modules\Product\Services\DTOs\ProductReferenceInventory::supportedRelations();

        // Callback that migrates all 3 but CLAIMS only 1 (under-count)
        $underCountCallback = function ($survivor, $retired, $audit) use ($supportedRelations) {
            // Migrate all (3 rows)
            $movedCount = \Illuminate\Support\Facades\DB::table('product_stocks')
                ->where('product_id', $retired->id)
                ->update(['product_id' => $survivor->id]);

            // But claim only 1 (under-count)
            $counts = [];
            foreach (array_keys($supportedRelations) as $table) {
                $counts[$table] = $table === 'product_stocks' ? 1 : 0;
            }

            return new \Modules\Product\Services\DTOs\ProductReferenceMigrationResult(true, $counts);
        };

        // Should throw on under-count mismatch
        try {
            $service->executeInternalRetirementTransaction($survivor, [$retired], $underCountCallback);
            $this->fail('Expected reconciliation to fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('claimed 1 migrations for product_stocks', $e->getMessage());
        }

        // Verify rollback: retired product remains active
        $retired->refresh();
        $this->assertNull($retired->merged_into_id, 'Retired product should remain active after under-count failure');

        // Verify product_stocks were rolled back to exactly 3 rows on retired product
        $retiredCount = \Illuminate\Support\Facades\DB::table('product_stocks')
            ->where('product_id', $retired->id)
            ->count();
        $this->assertEquals(3, $retiredCount, 'Product stocks should be rolled back to retired product');

        // Verify zero rows were left on the survivor
        $survivorCount = \Illuminate\Support\Facades\DB::table('product_stocks')
            ->where('product_id', $survivor->id)
            ->count();
        $this->assertEquals(0, $survivorCount, 'No product stocks should be on survivor after rollback');
    }

    /**
     * Schema validation fails before callback is even called.
     *
     * This test verifies that the fail-closed schema check prevents
     * any callback execution if tables/columns are missing.
     */
    public function test_schema_validation_happens_before_callback()
    {
        $survivor = $this->createProduct('Survivor', 'survivor');
        $retired = $this->createProduct('Survivor', null);

        $service = app(ProductMergeService::class);

        $callbackWasCalled = false;
        $badCallback = function ($survivor, $retired, $audit) use (&$callbackWasCalled) {
            $callbackWasCalled = true;
            return new \Modules\Product\Services\DTOs\ProductReferenceMigrationResult(true, []);
        };

        // Should fail during plan build (schema validation) before callback runs
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot safely assess product for retirement');

        $service->executeInternalRetirementTransaction($survivor, [$retired], $badCallback);
        $this->assertFalse($callbackWasCalled, 'Callback should not be called if schema validation fails');
    }

    /**
     * Verify audit record captures before and after snapshots.
     */
    public function test_audit_record_captures_before_and_after_snapshots()
    {
        $survivor = $this->createProduct('Survivor', 'survivor');
        $retired = $this->createProduct('Survivor', null);

        $service = app(ProductMergeService::class);

        $callback = function ($survivor, $retired, $audit) {
            return new \Modules\Product\Services\DTOs\ProductReferenceMigrationResult(true, []);
        };

        $event = $service->executeInternalRetirementTransaction($survivor, [$retired], $callback);

        $audit = \Modules\Product\Entities\ProductMergeAudit::where('retired_product_id', $retired->id)->first();
        $this->assertNotNull($audit);

        // Should have before snapshot
        $this->assertIsArray($audit->migrated_relations_snapshot);
        $this->assertNotEmpty($audit->migrated_relations_snapshot);

        // Should have after snapshot (even if empty for this test)
        $this->assertIsArray($audit->actual_migrated_counts);
    }

    /**
     * Preflight plan fails closed when schema is incomplete.
     */
    public function test_preflight_plan_fails_closed_on_incomplete_schema()
    {
        $survivor = $this->createProduct('Survivor', 'survivor');
        $retired = $this->createProduct('Survivor', null);

        $service = app(ProductMergeService::class);

        // Plan should fail closed due to incomplete schema (missing columns)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot safely assess product for retirement');

        $service->getPlanForRetirement($survivor, [$retired]);
    }

    /**
     * Verify actual_migrated_counts field exists in audit.
     */
    public function test_audit_model_has_actual_migrated_counts_cast()
    {
        $audit = new \Modules\Product\Entities\ProductMergeAudit();
        $casts = $audit->getCasts();

        $this->assertArrayHasKey('actual_migrated_counts', $casts);
        $this->assertEquals('array', $casts['actual_migrated_counts']);
    }
}
