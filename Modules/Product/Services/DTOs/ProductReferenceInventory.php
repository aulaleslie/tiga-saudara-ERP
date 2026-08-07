<?php

namespace Modules\Product\Services\DTOs;

use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;

class ProductReferenceInventory
{
    // Supported: can be safely migrated to a survivor product
    private const SUPPORTED_RELATIONS = [
        'transactions' => 'product_id',
        'product_prices' => 'product_id',
        'product_stocks' => 'product_id',
        'purchase_details' => 'product_id',
        'sale_details' => 'product_id',
        'dispatch_details' => 'product_id',
        'sale_return_details' => 'product_id',
        'purchase_return_details' => 'product_id',
        'product_bundles' => 'parent_product_id',
        'product_bundle_items' => 'product_id',
        'product_unit_conversions' => 'product_id',
    ];

    // Unsupported: must be zero before retirement can proceed
    private const UNSUPPORTED_RELATIONS = [
        'product_serial_numbers' => 'product_id',
        'quotation_details' => 'product_id',
        'purchase_return_goods' => 'product_id',
        'sale_return_goods' => 'product_id',
        'sale_bundle_items' => 'product_id',
        'product_import_rows' => 'product_id',
        'barcode_identities' => 'product_id',
        'product_barcode_assignments' => 'product_id',
        'adjusted_products' => 'product_id',
        'transfer_products' => 'product_id',
        'pos_transaction_lines' => 'product_id',
        'pos_return_lines' => 'product_id',    // product_id and replacement_product_id
    ];

    /**
     * Get all supported relation definitions.
     */
    public static function supportedRelations(): array
    {
        return self::SUPPORTED_RELATIONS;
    }

    /**
     * Get all unsupported relation definitions.
     */
    public static function unsupportedRelations(): array
    {
        return self::UNSUPPORTED_RELATIONS;
    }

    /**
     * Build a complete inventory of reference counts for a product.
     *
     * @param Product $product
     * @return array Keys: relation_name, values: count
     * @throws \RuntimeException If a required table is missing from the schema
     */
    public static function forProduct(Product $product): array
    {
        $inventory = [];
        $missingTables = [];

        // Supported relations
        foreach (self::SUPPORTED_RELATIONS as $table => $column) {
            if (!\Illuminate\Support\Facades\Schema::hasTable($table)) {
                $missingTables[] = $table;
                continue;
            }
            $inventory[$table] = self::countReferences($table, $column, $product->id);
        }

        // Unsupported relations - handle pos_return_lines specially (dual columns)
        foreach (self::UNSUPPORTED_RELATIONS as $table => $column) {
            if (!\Illuminate\Support\Facades\Schema::hasTable($table)) {
                $missingTables[] = $table;
                continue;
            }

            if ($table === 'pos_return_lines') {
                // pos_return_lines references product_id and replacement_product_id
                $productIdCount = self::countReferences($table, 'product_id', $product->id);
                $replacementCount = self::countReferences($table, 'replacement_product_id', $product->id);
                $inventory[$table] = $productIdCount + $replacementCount;
            } else {
                $inventory[$table] = self::countReferences($table, $column, $product->id);
            }
        }

        // Fail closed: if any expected table is missing, we cannot safely assess for retirement
        if (!empty($missingTables)) {
            throw new \RuntimeException(
                "Cannot safely assess product for retirement: reference validation tables are missing: " .
                implode(', ', $missingTables)
            );
        }

        return $inventory;
    }

    /**
     * Build a complete inventory for multiple products.
     *
     * @param iterable<Product> $products
     * @return array Keyed by product ID
     */
    public static function forProducts(iterable $products): array
    {
        $result = [];
        foreach ($products as $product) {
            $result[$product->id] = self::forProduct($product);
        }
        return $result;
    }

    /**
     * Get only the unsupported reference counts for a product.
     * Used to validate that a group is safe for retirement.
     *
     * @param Product $product
     * @return array Keys: relation_name, values: count (only for unsupported)
     */
    public static function unsupportedCountsForProduct(Product $product): array
    {
        $inventory = self::forProduct($product);
        $unsupported = [];

        foreach (self::UNSUPPORTED_RELATIONS as $table => $column) {
            $unsupported[$table] = $inventory[$table] ?? 0;
        }

        return $unsupported;
    }

    /**
     * Check if a product is safe for retirement (all unsupported counts are zero).
     *
     * @param Product $product
     * @return bool
     */
    public static function isSafeForRetirement(Product $product): bool
    {
        $unsupportedCounts = self::unsupportedCountsForProduct($product);
        return array_sum($unsupportedCounts) === 0;
    }

    /**
     * Get a human-readable summary of why a product is not safe for retirement.
     *
     * @param Product $product
     * @return string|null Null if safe, otherwise a summary message
     */
    public static function getSafetyBlockingReasons(Product $product): ?string
    {
        $unsupportedCounts = self::unsupportedCountsForProduct($product);
        $blocked = array_filter($unsupportedCounts, fn($count) => $count > 0);

        if (empty($blocked)) {
            return null;
        }

        $details = [];
        foreach ($blocked as $table => $count) {
            $details[] = "{$table}: {$count}";
        }

        return "Cannot retire product due to unsupported references: " . implode(", ", $details);
    }

    private static function countReferences(string $table, string $column, int $productId): int
    {
        // Verify table and column exist - fail closed to prevent false "safe to retire" conclusions
        if (!\Illuminate\Support\Facades\Schema::hasTable($table)) {
            throw new \RuntimeException("Reference validation table '{$table}' does not exist. Cannot safely assess product for retirement.");
        }

        if (!\Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
            throw new \RuntimeException("Reference validation column '{$table}.{$column}' does not exist. Cannot safely assess product for retirement.");
        }

        return DB::table($table)->where($column, $productId)->count();
    }
}
