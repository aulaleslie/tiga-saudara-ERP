<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Modules\Product\Entities\Product;
use Modules\Product\Services\DTOs\ProductReferenceInventory;
use Modules\Product\Services\ProductMergeService;

class ProductReferenceInventoryTest extends TestCase
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

    public function test_inventory_keys_appear_identically_in_preflight_and_audit()
    {
        $product = $this->createProduct('Test Product', 'test product');

        $inventory = ProductReferenceInventory::forProduct($product);

        $supportedKeys = array_keys(ProductReferenceInventory::supportedRelations());
        $unsupportedKeys = array_keys(ProductReferenceInventory::unsupportedRelations());

        foreach ($supportedKeys as $key) {
            $this->assertArrayHasKey($key, $inventory);
        }

        foreach ($unsupportedKeys as $key) {
            $this->assertArrayHasKey($key, $inventory);
        }

        $inventoryKeys = array_keys($inventory);
        $expectedKeys = array_merge($supportedKeys, $unsupportedKeys);
        sort($expectedKeys);
        sort($inventoryKeys);
        $this->assertEquals($expectedKeys, $inventoryKeys);
    }

    public function test_supported_and_unsupported_are_disjoint()
    {
        $supported = array_keys(ProductReferenceInventory::supportedRelations());
        $unsupported = array_keys(ProductReferenceInventory::unsupportedRelations());

        $intersection = array_intersect($supported, $unsupported);
        $this->assertEmpty($intersection);
    }

    public function test_is_safe_for_retirement_works()
    {
        $product = $this->createProduct('Test Product');
        $this->assertTrue(ProductReferenceInventory::isSafeForRetirement($product));
    }

    public function test_safety_blocking_reasons_returns_null_when_safe()
    {
        $product = $this->createProduct('Safe Product');
        $reasons = ProductReferenceInventory::getSafetyBlockingReasons($product);
        $this->assertNull($reasons);
    }

    public function test_for_products_returns_keyed_by_id()
    {
        $product1 = $this->createProduct('P1');
        $product2 = $this->createProduct('P2');

        $inventories = ProductReferenceInventory::forProducts([$product1, $product2]);

        $this->assertArrayHasKey($product1->id, $inventories);
        $this->assertArrayHasKey($product2->id, $inventories);
        $this->assertIsArray($inventories[$product1->id]);
        $this->assertIsArray($inventories[$product2->id]);
    }

    public function test_unsupported_counts_only_includes_unsupported()
    {
        $product = $this->createProduct('Test');

        $unsupportedCounts = ProductReferenceInventory::unsupportedCountsForProduct($product);

        foreach (ProductReferenceInventory::unsupportedRelations() as $table => $column) {
            $this->assertArrayHasKey($table, $unsupportedCounts);
        }

        foreach (ProductReferenceInventory::supportedRelations() as $table => $column) {
            $this->assertArrayNotHasKey($table, $unsupportedCounts);
        }
    }

    public function test_preflight_plan_shows_retirement_safe()
    {
        $survivor = $this->createProduct('Survivor', 'survivor');
        $retired = $this->createProduct('Survivor', null);

        $service = app(ProductMergeService::class);
        $plan = $service->getPlanForRetirement($survivor, [$retired]);

        $this->assertEquals($survivor->id, $plan['survivor_id']);
        $this->assertNotEmpty($plan['retired']);
        $this->assertArrayHasKey($retired->id, $plan['before_snapshot']);
        $this->assertEmpty($plan['unsupported_blocks']);
    }

    public function test_preflight_rejects_merged_survivor()
    {
        $survivor = $this->createProduct('Survivor', 'survivor');
        $survivor->update(['merged_into_id' => 999]);

        $retired = $this->createProduct('Retired', 'retired');

        $service = app(ProductMergeService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->getPlanForRetirement($survivor, [$retired]);
    }

    public function test_preflight_rejects_mismatched_canonical_key()
    {
        $survivor = $this->createProduct('Survivor', 'survivor');
        $retired = $this->createProduct('Different', 'different');

        $service = app(ProductMergeService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match survivor');

        $service->getPlanForRetirement($survivor, [$retired]);
    }

    public function test_empty_callback_can_retire_product_with_no_references()
    {
        $survivor = $this->createProduct('Survivor', 'survivor');
        $retired = $this->createProduct('Survivor', null);

        $service = app(ProductMergeService::class);

        $emptyCallback = function ($survivor, $retired, $audit) {
            return new \Modules\Product\Services\DTOs\ProductReferenceMigrationResult(true, []);
        };

        $event = $service->executeInternalRetirementTransaction($survivor, [$retired], $emptyCallback);
        $this->assertNotNull($event->id);
        $this->assertEquals($survivor->id, $event->survivor_product_id);

        $retired->refresh();
        $this->assertEquals($survivor->id, $retired->merged_into_id);
    }

    public function test_migration_result_validation_prevents_incomplete_claims()
    {
        $result = new \Modules\Product\Services\DTOs\ProductReferenceMigrationResult(false, []);
        $this->assertFalse($result->isCompleted);
        $this->assertEquals([], $result->migratedCounts);

        $this->expectException(\InvalidArgumentException::class);
        new \Modules\Product\Services\DTOs\ProductReferenceMigrationResult(false, ['transactions' => 5]);
    }

    public function test_callback_must_report_completed_status()
    {
        $survivor = $this->createProduct('Survivor', 'survivor');
        $retired = $this->createProduct('Survivor', null);

        $service = app(ProductMergeService::class);

        // Incomplete callback
        $incompleteCallback = function ($survivor, $retired, $audit) {
            return new \Modules\Product\Services\DTOs\ProductReferenceMigrationResult(false, []);
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('incomplete result');

        $service->executeInternalRetirementTransaction($survivor, [$retired], $incompleteCallback);
    }

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
        $this->assertIsArray($audit->migrated_relations_snapshot);
        $this->assertIsArray($audit->actual_migrated_counts);
    }

    public function test_schema_validation_fails_closed_on_missing_table()
    {
        // This test verifies that schema validation works by checking a product
        // The inventory building should work for tables that exist
        $product = $this->createProduct('Test');

        // Should work fine for a product with no references
        $inventory = ProductReferenceInventory::forProduct($product);
        $this->assertIsArray($inventory);

        // But if a table doesn't exist, countReferences should fail
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        // Try to manually count a table we know doesn't have the product column
        ProductReferenceInventory::forProduct(new class extends Product {
            protected $table = 'nonexistent_table';
        });
    }
}
