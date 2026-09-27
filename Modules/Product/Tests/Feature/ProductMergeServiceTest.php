<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductMergeEvent;
use Modules\Product\Entities\ProductMergeAudit;
use Modules\Product\Services\ProductMergeService;
use Tests\TestCase;

class ProductMergeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ProductMergeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductMergeService::class);
    }

    protected function createProduct(array $attributes = [])
    {
        return Product::create(array_merge([
            'product_name' => 'Test Product',
            'setting_id' => 1,
            'unit_id' => 1,
            'base_unit_id' => 1,
            'product_cost' => 0,
            'product_price' => 0,
            'product_quantity' => 0,
            'stock_managed' => 1,
            'is_purchased' => 1,
            'is_sold' => 1,
        ], $attributes));
    }

    protected function getValidCallback()
    {
        return function() {
            return new \Modules\Product\Services\DTOs\ProductReferenceMigrationResult(true);
        };
    }

    public function test_reconciliation_audit_group_links_correctly_to_survivor_and_retired_rows()
    {
        $survivor = $this->createProduct(['product_name' => 'Main Product']);
        $retired1 = $this->createProduct(['product_name' => ' Main Product ']); // same canonical key
        $retired2 = $this->createProduct(['product_name' => ' main  product ']); // same canonical key

        $event = $this->service->executeInternalRetirementTransaction($survivor, [$retired1, $retired2], $this->getValidCallback());

        $this->assertNotNull($event);
        $this->assertEquals($survivor->id, $event->survivor_product_id);
        $this->assertEquals('main product', $event->canonical_key);

        $audits = $event->mergeAudits()->get();
        $this->assertCount(2, $audits);

        $retiredIds = $audits->pluck('retired_product_id')->toArray();
        $this->assertContains($retired1->id, $retiredIds);
        $this->assertContains($retired2->id, $retiredIds);

        // Verify retired products updated
        $this->assertEquals($survivor->id, $retired1->fresh()->merged_into_id);
        $this->assertEquals($survivor->id, $retired2->fresh()->merged_into_id);
        $this->assertNull($retired1->fresh()->canonical_name);
        $this->assertNull($retired2->fresh()->canonical_name);
    }

    public function test_survivor_stale_canonical_key_is_updated_and_used()
    {
        $survivor = $this->createProduct(['product_name' => 'Main Product', 'canonical_name' => 'stale key']);
        $retired = $this->createProduct(['product_name' => 'Main Product']);

        $event = $this->service->executeInternalRetirementTransaction($survivor, [$retired], $this->getValidCallback());

        $this->assertEquals('main product', $event->canonical_key);
        $this->assertEquals('main product', $survivor->fresh()->canonical_name);
    }

    public function test_full_snapshot_contents_are_captured()
    {
        $survivor = $this->createProduct(['product_name' => 'Main Product']);
        $retired = $this->createProduct(['product_name' => 'Main Product']);

        $event = $this->service->executeInternalRetirementTransaction($survivor, [$retired], $this->getValidCallback());
        
        $audit = $event->mergeAudits()->first();
        $snapshot = $audit->migrated_relations_snapshot;
        
        $expectedKeys = [
            'transactions_count', 'purchase_details_count', 'sale_details_count',
            'sale_return_details_count', 'purchase_return_details_count', 'dispatch_details_count',
            'serial_numbers_count', 'product_stocks_count', 'bundles_count', 'bundled_in_count',
            'prices_count', 'quotation_details_count', 'transfer_products_count', 'adjusted_products_count',
            'barcode_identities_count', 'product_unit_conversions_count', 'import_rows_count'
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $snapshot);
        }
    }

    public function test_rejection_of_empty_or_noop_migration_callback()
    {
        $survivor = $this->createProduct(['product_name' => 'Main Product']);
        $retired = $this->createProduct(['product_name' => 'Main Product']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Migration callback must return a completed ProductReferenceMigrationResult");
        
        $this->service->executeInternalRetirementTransaction($survivor, [$retired], function() {});
    }

    public function test_invalid_self_merge_is_rejected()
    {
        $survivor = $this->createProduct(['product_name' => 'Main Product']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot self-merge");
        
        $this->service->executeInternalRetirementTransaction($survivor, [$survivor], $this->getValidCallback());
    }

    public function test_retired_survivor_is_rejected()
    {
        $realSurvivor = $this->createProduct(['product_name' => 'Real Survivor']);
        $retiredSurvivor = $this->createProduct(['product_name' => 'Retired Survivor']);
        
        $retiredSurvivor->merged_into_id = $realSurvivor->id;
        $retiredSurvivor->save();

        $anotherProduct = $this->createProduct(['product_name' => 'Retired Survivor']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Survivor product cannot be a retired product.");
        
        $this->service->executeInternalRetirementTransaction($retiredSurvivor, [$anotherProduct], $this->getValidCallback());
    }

    public function test_already_retired_inputs_are_rejected()
    {
        $survivor = $this->createProduct(['product_name' => 'Main Product']);
        $alreadyRetired = $this->createProduct(['product_name' => 'Main Product']);
        
        $alreadyRetired->merged_into_id = $survivor->id;
        $alreadyRetired->save();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("is already retired.");

        $this->service->executeInternalRetirementTransaction($survivor, [$alreadyRetired], $this->getValidCallback());
    }

    public function test_survivor_deletion_and_audited_product_deletion_are_rejected()
    {
        $survivor = $this->createProduct(['product_name' => 'Main Product']);
        $retired = $this->createProduct(['product_name' => 'Main Product']);

        $this->service->executeInternalRetirementTransaction($survivor, [$retired], $this->getValidCallback());

        // Attempt to delete survivor
        try {
            $survivor->delete();
            $this->fail('Expected QueryException when deleting survivor, but none was thrown.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Integrity constraint violation', $e->getMessage());
        }

        // Attempt to delete retired product
        try {
            $retired->delete();
            $this->fail('Expected QueryException when deleting retired product, but none was thrown.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Integrity constraint violation', $e->getMessage());
        }
    }

    public function test_rejects_products_with_different_canonical_identity()
    {
        $survivor = $this->createProduct(['product_name' => 'Main Product']);
        $different = $this->createProduct(['product_name' => 'Different Product']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("does not match survivor canonical key");

        $this->service->executeInternalRetirementTransaction($survivor, [$different], $this->getValidCallback());
    }

    public function test_empty_global_search_excludes_retired_products()
    {
        $activeProduct = $this->createProduct(['product_name' => 'Active Product']);
        $retiredProduct = $this->createProduct(['product_name' => 'Retired Product']);
        
        $retiredProduct->merged_into_id = $activeProduct->id;
        $retiredProduct->save();

        $results = Product::globalSearch('')->get();
        
        $this->assertTrue($results->contains('id', $activeProduct->id));
        $this->assertFalse($results->contains('id', $retiredProduct->id));
    }
}
