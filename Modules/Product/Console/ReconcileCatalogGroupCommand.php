<?php

namespace Modules\Product\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Services\ProductMergeService;
use Modules\Product\Services\ProductReferenceMigrator;
use Modules\Product\Services\DTOs\ProductReferenceInventory;
use App\Models\User;

class ReconcileCatalogGroupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'product:reconcile-catalog-group
        {--survivor-id= : The ID of the product to keep active}
        {--retired-ids= : Comma-separated list of product IDs to retire}
        {--operator-id= : The ID of the user performing this operation}
        {--confirm : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile a duplicate catalog group by selecting a survivor and retiring redundant products.';

    /**
     * Execute the console command.
     */
    public function handle(ProductMergeService $mergeService, ProductReferenceMigrator $migrator)
    {
        $survivorId = $this->option('survivor-id');
        $retiredIdsStr = $this->option('retired-ids');
        $operatorId = $this->option('operator-id');
        $confirm = $this->option('confirm');

        // Validate required options
        if (!$survivorId || !$retiredIdsStr || !$operatorId) {
            $this->error('All of --survivor-id, --retired-ids, and --operator-id are required.');
            return 1;
        }

        // Validate operator exists
        $operator = User::find($operatorId);
        if (!$operator) {
            $this->error("Operator user with ID {$operatorId} not found.");
            return 1;
        }

        $retiredIds = array_map('intval', array_filter(array_map('trim', explode(',', $retiredIdsStr))));

        if (empty($retiredIds)) {
            $this->error('At least one retired product ID must be provided.');
            return 1;
        }

        $survivor = Product::find($survivorId);
        if (!$survivor) {
            $this->error("Survivor product with ID {$survivorId} not found.");
            return 1;
        }

        $retired = Product::whereIn('id', $retiredIds)->get();
        if ($retired->count() !== count($retiredIds)) {
            $missing = array_diff($retiredIds, $retired->pluck('id')->toArray());
            $this->error('The following retired product IDs were not found: ' . implode(', ', $missing));
            return 1;
        }

        try {
            // Step 1: Get validation plan
            $plan = $mergeService->getPlanForRetirement($survivor, $retired);

            // Display plan
            $this->displayPlan($plan);

            // Step 2: Check for blocking issues
            if (!empty($plan['unsupported_blocks'])) {
                $this->error('This group cannot be reconciled due to unsupported reference blocking reasons:');
                foreach ($plan['unsupported_blocks'] as $productId => $reasons) {
                    $this->error("  Product {$productId}: {$reasons}");
                }
                return 1;
            }

            // Step 3: Build concrete conflict plan
            $conflictPlan = $this->buildConflictPlan($survivor, $retired);
            if (!$conflictPlan['valid']) {
                $this->error('This group cannot be reconciled due to semantic conflicts:');
                foreach ($conflictPlan['conflicts'] as $conflict) {
                    $this->error("  " . $conflict);
                }
                return 1;
            }

            // Step 4: Ask for confirmation
            if (!$confirm && !$this->confirm('Proceed with reconciliation?')) {
                $this->info('Cancelled.');
                return 0;
            }

            // Step 5: Execute reconciliation
            $this->info('Executing reconciliation...');

            $event = $mergeService->executeInternalRetirementTransaction(
                $survivor,
                $retired,
                function (Product $survivorProd, Product $retiredProd, $audit) use ($migrator) {
                    return $migrator->migrateReferences($survivorProd, $retiredProd, $audit);
                },
                $operatorId
            );

            $this->info("✓ Reconciliation completed successfully. Event ID: {$event->id}");

            // Display results
            $this->displayResults($event);

            return 0;
        } catch (\InvalidArgumentException $e) {
            $this->error('Validation error: ' . $e->getMessage());
            return 1;
        } catch (\RuntimeException $e) {
            $this->error('Reconciliation failed: ' . $e->getMessage());
            return 1;
        } catch (\Exception $e) {
            $this->error('Unexpected error: ' . $e->getMessage());
            if ($this->option('verbose')) {
                $this->line($e->getTraceAsString());
            }
            return 1;
        }
    }

    private function buildConflictPlan(Product $survivor, $retiredProducts): array
    {
        $conflicts = [];
        $retiredArray = is_array($retiredProducts) ? $retiredProducts : iterator_to_array($retiredProducts);

        // Build the set of all products in the group: [survivor_id, ...retired_ids]
        $groupProductIds = array_merge([$survivor->id], array_map(fn($r) => $r->id, $retiredArray));

        // === Price Conflict Preflight: Check entire group for setting_id collisions ===
        $groupPrices = DB::table('product_prices')
            ->whereIn('product_id', $groupProductIds)
            ->select('id', 'product_id', 'setting_id', 'sale_price', 'tier_1_price', 'tier_2_price', 'last_purchase_price', 'average_purchase_price')
            ->get()
            ->groupBy('setting_id');

        foreach ($groupPrices as $settingId => $pricesForSetting) {
            if ($pricesForSetting->count() > 1) {
                // Multiple products in this group have prices for the same setting
                foreach ($pricesForSetting as $priceRow) {
                    $priceFields = implode(', ', array_filter([
                        $priceRow->sale_price !== null ? "sale_price={$priceRow->sale_price}" : null,
                        $priceRow->tier_1_price !== null ? "tier_1_price={$priceRow->tier_1_price}" : null,
                        $priceRow->tier_2_price !== null ? "tier_2_price={$priceRow->tier_2_price}" : null,
                        $priceRow->last_purchase_price !== null ? "last_purchase_price={$priceRow->last_purchase_price}" : null,
                        $priceRow->average_purchase_price !== null ? "average_purchase_price={$priceRow->average_purchase_price}" : null,
                    ]));
                    $conflicts[] = "Price collision in setting {$settingId}: product {$priceRow->product_id} " .
                        "(price row ID: {$priceRow->id}, fields: {$priceFields})";
                }
            }
        }

        // === Bundle Semantic Conflict Preflight: Check entire group ===
        // For each bundle item whose product_id is in the group (retired or survivor),
        // determine the effective_product_id after repointing and group by (bundle_id, effective_product_id).
        // Reject if any group has more than one bundle item.

        $retiredIds = array_map(fn($r) => $r->id, $retiredArray);
        $retiredIdSet = array_flip($retiredIds);

        $groupBundleItems = DB::table('product_bundle_items')
            ->whereIn('product_id', $groupProductIds)
            ->select('id', 'bundle_id', 'product_id')
            ->get();

        // Group by (bundle_id, effective_product_id)
        $bundleGroups = [];
        foreach ($groupBundleItems as $item) {
            $effectiveProductId = isset($retiredIdSet[$item->product_id]) ? $survivor->id : $item->product_id;
            $groupKey = "{$item->bundle_id}:{$effectiveProductId}";

            if (!isset($bundleGroups[$groupKey])) {
                $bundleGroups[$groupKey] = [];
            }
            $bundleGroups[$groupKey][] = $item;
        }

        foreach ($bundleGroups as $groupKey => $items) {
            if (count($items) > 1) {
                // Multiple bundle items for the same (bundle_id, effective_product_id)
                [$bundleId, $effectiveProductId] = explode(':', $groupKey);
                foreach ($items as $item) {
                    $conflicts[] = "Bundle semantic conflict: bundle ID {$bundleId} would have duplicate " .
                        "effective product {$effectiveProductId} (bundle-item ID: {$item->id}, original product {$item->product_id})";
                }
            }
        }

        return [
            'valid' => empty($conflicts),
            'conflicts' => $conflicts,
        ];
    }


    private function displayPlan(array $plan): void
    {
        $this->info('Reconciliation Plan');
        $this->line('==================');
        $this->line("Canonical Key: {$plan['canonical_key']}");
        $this->line("Survivor: ID={$plan['survivor_id']} Name='{$plan['survivor_name']}'");
        $this->line('');
        $this->info('Retired Products:');

        foreach ($plan['retired'] as $item) {
            $this->line("  - ID={$item['id']} Name='{$item['name']}'");
        }

        $this->line('');
        $this->info('Reference Inventory (Before Migration):');

        foreach ($plan['before_snapshot'] as $productId => $inventory) {
            $this->line("  Product ID {$productId}:");

            $supportedCounts = [];
            foreach (ProductReferenceInventory::supportedRelations() as $table => $column) {
                if (!empty($inventory[$table])) {
                    $supportedCounts[$table] = $inventory[$table];
                }
            }

            if ($supportedCounts) {
                foreach ($supportedCounts as $table => $count) {
                    $this->line("    - {$table}: {$count}");
                }
            } else {
                $this->line('    - (no references)');
            }
        }
    }

    private function displayResults($event): void
    {
        $this->line('');
        $this->info('Results:');
        $this->line('--------');

        foreach ($event->mergeAudits as $audit) {
            $this->line("Retired Product ID {$audit->retired_product_id}:");

            if ($audit->actual_migrated_counts) {
                foreach ($audit->actual_migrated_counts as $table => $count) {
                    if ($count > 0) {
                        $this->line("  - {$table}: migrated {$count} reference(s)");
                    }
                }
            }

            if (!$audit->actual_migrated_counts || !array_filter($audit->actual_migrated_counts)) {
                $this->line('  - (no references migrated)');
            }
        }
    }
}
