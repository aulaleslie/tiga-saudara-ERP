<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductMergeEvent;
use Modules\Product\Entities\ProductMergeAudit;
use InvalidArgumentException;
use Modules\Product\Services\ProductCanonicalizer;
use Modules\Product\Services\DTOs\ProductReferenceMigrationResult;
use Modules\Product\Services\DTOs\ProductReferenceInventory;
use RuntimeException;

class ProductMergeService
{
    /**
     * Get a read-only validation plan for retiring a group of products.
     * This method does not mutate state and can be used for preflight checks.
     *
     * @param Product $survivor The product to keep active
     * @param iterable<Product> $retiredProducts The products to retire
     * @return array Plan with 'survivor', 'retired', 'before_snapshot', 'unsupported_blocks'
     * @throws InvalidArgumentException
     */
    public function getPlanForRetirement(Product $survivor, iterable $retiredProducts): array
    {
        if ($survivor->merged_into_id !== null) {
            throw new InvalidArgumentException("Survivor product cannot be a retired product.");
        }

        $retiredArray = is_array($retiredProducts) ? $retiredProducts : iterator_to_array($retiredProducts);
        if (empty($retiredArray)) {
            throw new InvalidArgumentException("At least one retired product must be provided.");
        }

        $canonicalizer = app(ProductCanonicalizer::class);
        $canonicalKey = $canonicalizer->canonicalize($survivor->product_name)['canonical_key'];

        $plan = [
            'survivor_id' => $survivor->id,
            'survivor_name' => $survivor->product_name,
            'canonical_key' => $canonicalKey,
            'retired' => [],
            'before_snapshot' => [],
            'unsupported_blocks' => [],
        ];

        foreach ($retiredArray as $retiredItem) {
            if ($retiredItem->id === $survivor->id) {
                throw new InvalidArgumentException("Cannot self-merge a product (ID: {$retiredItem->id}).");
            }

            $retiredIdentity = $canonicalizer->canonicalize($retiredItem->product_name);
            if ($retiredIdentity['canonical_key'] !== $canonicalKey) {
                throw new InvalidArgumentException("Retired product ID {$retiredItem->id} canonical key ('{$retiredIdentity['canonical_key']}') does not match survivor canonical key ('{$canonicalKey}').");
            }

            if ($retiredItem->merged_into_id !== null) {
                throw new InvalidArgumentException("Product ID {$retiredItem->id} is already retired.");
            }

            $inventory = ProductReferenceInventory::forProduct($retiredItem);
            $plan['before_snapshot'][$retiredItem->id] = $inventory;
            $plan['retired'][] = [
                'id' => $retiredItem->id,
                'name' => $retiredItem->product_name,
            ];

            // Check for unsupported references that would block retirement
            $blockingReasons = ProductReferenceInventory::getSafetyBlockingReasons($retiredItem);
            if ($blockingReasons) {
                $plan['unsupported_blocks'][$retiredItem->id] = $blockingReasons;
            }
        }

        return $plan;
    }

    /**
     * Internal method to execute the retirement of duplicate products and wrap the reference migration.
     * MUST ONLY be called within the Task 3.3 migration process.
     *
     * Requires this exact order:
     * 1. Lock survivor and all retired rows.
     * 2. Canonicalize display names and verify one exact identity group.
     * 3. Build the shared full snapshot.
     * 4. Reject if any unsupported count is nonzero.
     * 5. Repoint every supported reference and write one product_reference_migrations audit row per migrated record.
     * 6. Independently verify all supported counts on every retired product are now zero.
     * 7. Create immutable event/audit records containing the before snapshot and actual migrated counts.
     * 8. Retire rows and assign the survivor's canonical key.
     * 9. Commit; any failure rolls back the entire group.
     *
     * @param Product $survivor The product to keep active
     * @param iterable<Product> $retiredProducts The products to retire
     * @param callable $migrationCallback The callback that executes reference migrations. Signature: function(Product $survivor, Product $retired, ProductMergeAudit $audit): ProductReferenceMigrationResult
     * @param int|null $userId The user performing the action
     * @return ProductMergeEvent
     * @throws RuntimeException When unsupported references exist or migration fails
     */
    public function executeInternalRetirementTransaction(Product $survivor, iterable $retiredProducts, callable $migrationCallback, ?int $userId = null): ProductMergeEvent
    {
        return DB::transaction(function () use ($survivor, $retiredProducts, $migrationCallback, $userId) {
            // Step 1: Lock survivor for update first
            $survivor = Product::where('id', $survivor->id)->lockForUpdate()->firstOrFail();

            if ($survivor->merged_into_id !== null) {
                throw new InvalidArgumentException("Survivor product cannot be a retired product.");
            }

            // Step 2: Canonicalize and verify exact identity group
            $canonicalizer = app(ProductCanonicalizer::class);
            $canonicalKey = $canonicalizer->canonicalize($survivor->product_name)['canonical_key'];

            if ($survivor->canonical_name !== $canonicalKey) {
                $survivor->canonical_name = $canonicalKey;
                $survivor->save();
            }

            $retiredArray = is_array($retiredProducts) ? $retiredProducts : iterator_to_array($retiredProducts);
            if (empty($retiredArray)) {
                throw new InvalidArgumentException("At least one retired product must be provided.");
            }

            // Step 3: Build the full snapshot and validate before any mutations
            $beforeSnapshots = [];
            foreach ($retiredArray as $retiredItem) {
                if ($retiredItem->id === $survivor->id) {
                    throw new InvalidArgumentException("Cannot self-merge a product (ID: {$retiredItem->id}).");
                }

                // Lock retired for update
                $retired = Product::where('id', $retiredItem->id)->lockForUpdate()->firstOrFail();

                if ($retired->merged_into_id !== null) {
                    throw new InvalidArgumentException("Product ID {$retired->id} is already retired.");
                }

                $retiredIdentity = $canonicalizer->canonicalize($retired->product_name);
                if ($retiredIdentity['canonical_key'] !== $canonicalKey) {
                    throw new InvalidArgumentException("Retired product ID {$retired->id} canonical key ('{$retiredIdentity['canonical_key']}') does not match survivor canonical key ('{$canonicalKey}').");
                }

                $beforeSnapshots[$retired->id] = ProductReferenceInventory::forProduct($retired);
            }

            // Step 4: Reject if any unsupported count is nonzero
            foreach ($retiredArray as $retiredItem) {
                $blockingReasons = ProductReferenceInventory::getSafetyBlockingReasons($retiredItem);
                if ($blockingReasons) {
                    throw new RuntimeException($blockingReasons);
                }
            }

            // Create the merge event
            $event = ProductMergeEvent::create([
                'survivor_product_id' => $survivor->id,
                'canonical_key' => $canonicalKey,
                'created_by' => $userId,
            ]);

            // Steps 5-8: Process each retired product
            foreach ($retiredArray as $retiredItem) {
                $retired = Product::where('id', $retiredItem->id)->lockForUpdate()->firstOrFail();

                $beforeSnapshot = $beforeSnapshots[$retired->id];
                $audit = ProductMergeAudit::create([
                    'merge_event_id' => $event->id,
                    'retired_product_id' => $retired->id,
                    'migrated_relations_snapshot' => $beforeSnapshot,
                ]);

                // Step 5: Call the migration callback to repoint every supported reference
                $result = $migrationCallback($survivor, $retired, $audit);

                if (!$result instanceof ProductReferenceMigrationResult) {
                    throw new RuntimeException("Migration callback must return a ProductReferenceMigrationResult instance.");
                }

                if (!$result->isCompleted) {
                    throw new RuntimeException("Migration callback returned incomplete result. Cannot retire product {$retired->id}.");
                }

                // Step 6: Independently verify all supported counts on the retired product are now zero
                $afterInventory = ProductReferenceInventory::forProduct($retired);
                $actualMigratedCounts = [];

                foreach (ProductReferenceInventory::supportedRelations() as $table => $column) {
                    $beforeCount = $beforeSnapshot[$table] ?? 0;
                    $afterCount = $afterInventory[$table] ?? 0;
                    $migratedCount = $beforeCount - $afterCount;

                    if ($migratedCount < 0) {
                        throw new RuntimeException(
                            "Product {$retired->id} reference count for {$table} increased after migration: " .
                            "was {$beforeCount}, now {$afterCount}. References must not be created during migration."
                        );
                    }

                    if ($afterCount > 0) {
                        throw new RuntimeException(
                            "After migration, retired product {$retired->id} still has {$afterCount} references in {$table}. " .
                            "Migration callback did not fully migrate all supported references."
                        );
                    }

                    $actualMigratedCounts[$table] = $migratedCount;
                }

                // Validate callback's claimed counts match reality
                // First, check that callback claimed counts for every supported relation
                foreach (ProductReferenceInventory::supportedRelations() as $table => $column) {
                    if (!isset($result->migratedCounts[$table])) {
                        throw new RuntimeException(
                            "Migration callback must report exact count for all supported relations. " .
                            "Missing count for '{$table}' on product {$retired->id}. " .
                            "Callback must claim count (including 0) for: " . implode(', ', array_keys(ProductReferenceInventory::supportedRelations()))
                        );
                    }
                }

                // Check for extra tables claimed by callback (not in supported relations)
                foreach ($result->migratedCounts as $table => $claimedCount) {
                    if (!isset(ProductReferenceInventory::supportedRelations()[$table])) {
                        throw new RuntimeException(
                            "Migration callback claimed migration for unsupported table '{$table}' on product {$retired->id}."
                        );
                    }
                }

                // Now verify each claimed count matches the actual migrated count
                foreach ($result->migratedCounts as $table => $claimedCount) {
                    if ($actualMigratedCounts[$table] !== $claimedCount) {
                        throw new RuntimeException(
                            "Migration callback claimed {$claimedCount} migrations for {$table} on product {$retired->id}, " .
                            "but actual count is {$actualMigratedCounts[$table]}."
                        );
                    }
                }

                // Re-check unsupported references immediately before retirement using locked rows
                $blockingReasons = ProductReferenceInventory::getSafetyBlockingReasons($retired);
                if ($blockingReasons) {
                    throw new RuntimeException(
                        "Unsupported references detected on product {$retired->id} immediately before retirement: {$blockingReasons}"
                    );
                }

                // Step 7: Persist the actual migrated counts in the audit record
                $audit->update([
                    'actual_migrated_counts' => $actualMigratedCounts,
                ]);

                // Step 8: Retire the product
                $retired->update([
                    'merged_into_id' => $survivor->id,
                    'merged_at' => now(),
                    'canonical_name' => null,
                ]);
            }

            // Step 9: Transaction commit happens automatically at the end of DB::transaction()
            return $event;
        });
    }
}
