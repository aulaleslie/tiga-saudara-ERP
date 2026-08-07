<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductReferenceMigration;
use Modules\Product\Services\DTOs\ProductReferenceInventory;
use Modules\Product\Services\DTOs\ProductReferenceMigrationResult;

class ProductReferenceMigrator
{
    /**
     * Optional callback that fires after each table migration completes.
     * Useful for testing mid-migration failures.
     *
     * @var callable|null
     */
    private $onTableMigratedCallback = null;

    public function setOnTableMigratedCallback(?callable $callback): void
    {
        $this->onTableMigratedCallback = $callback;
    }

    /**
     * Migrate all references from a retired product to a survivor product.
     *
     * @param Product $survivor
     * @param Product $retired
     * @param object $audit The audit record to associate migrations with
     * @return ProductReferenceMigrationResult
     */
    public function migrateReferences(Product $survivor, Product $retired, $audit): ProductReferenceMigrationResult
    {
        $migratedCounts = [];
        $supportedRelations = ProductReferenceInventory::supportedRelations();

        foreach ($supportedRelations as $table => $column) {
            $migratedCount = 0;
            $primaryKey = $this->getPrimaryKey($table);

            DB::table($table)
                ->where($column, $retired->id)
                ->orderBy($primaryKey)
                ->chunkById(500, function ($rows) use ($table, $column, $retired, $survivor, $audit, &$migratedCount, $primaryKey) {
                    foreach ($rows as $row) {
                        // Update the row
                        DB::table($table)
                            ->where($primaryKey, $row->id)
                            ->update([$column => $survivor->id]);

                        // Create audit record for this row
                        ProductReferenceMigration::create([
                            'audit_id' => $audit->id,
                            'table_name' => $table,
                            'row_id' => $row->id,
                            'old_product_id' => $retired->id,
                            'new_product_id' => $survivor->id,
                        ]);

                        $migratedCount++;
                    }
                });

            $migratedCounts[$table] = $migratedCount;

            // Fire callback if provided (for testing failures)
            if ($this->onTableMigratedCallback) {
                call_user_func($this->onTableMigratedCallback, $table, $migratedCount, $survivor, $retired);
            }
        }

        return new ProductReferenceMigrationResult(
            isCompleted: true,
            migratedCounts: $migratedCounts
        );
    }

    private function getPrimaryKey(string $table): string
    {
        // Map of table names to their primary keys
        $primaryKeys = [
            'transactions' => 'id',
            'product_prices' => 'id',
            'product_stocks' => 'id',
            'purchase_details' => 'id',
            'sale_details' => 'id',
            'dispatch_details' => 'id',
            'sale_return_details' => 'id',
            'purchase_return_details' => 'id',
            'product_bundles' => 'id',
            'product_bundle_items' => 'id',
            'product_unit_conversions' => 'id',
        ];

        return $primaryKeys[$table] ?? 'id';
    }
}
