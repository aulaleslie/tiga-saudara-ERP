<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Modules\Product\Entities\ProductBundle;

/**
 * Small helper responsible for hydrating bundle information for a product.
 */
class ProductBundleResolver
{
    /**
     * Per-request cache for bundle data.
     */
    private static array $cache = [];

    /**
     * Fetch all bundles that belong to the given product, eager loading items & products.
     */
    public static function forProduct(int $productId): Collection
    {
        if (!isset(self::$cache[$productId])) {
            self::$cache[$productId] = ProductBundle::with('items.product')
                ->where('parent_product_id', $productId)
                ->get();
        }

        return self::$cache[$productId];
    }

    /**
     * Batch-fetch bundles for multiple products at once (reduces N+1).
     * Returns a map of productId => Collection of bundles.
     */
    public static function forProducts(array $productIds): array
    {
        $productIds = array_filter(array_unique($productIds), fn($id) => $id > 0);

        if (empty($productIds)) {
            return [];
        }

        // Find which IDs are not yet cached
        $uncachedIds = array_filter($productIds, fn($id) => !isset(self::$cache[$id]));

        if (!empty($uncachedIds)) {
            // Batch fetch all bundles for uncached products
            $bundles = ProductBundle::with('items.product')
                ->whereIn('parent_product_id', $uncachedIds)
                ->get()
                ->groupBy('parent_product_id');

            // Populate cache - including empty collections for products without bundles
            foreach ($uncachedIds as $id) {
                self::$cache[$id] = $bundles->get($id, collect());
            }
        }

        // Return requested products from cache
        $result = [];
        foreach ($productIds as $id) {
            $result[$id] = self::$cache[$id] ?? collect();
        }

        return $result;
    }

    /**
     * Check if a product's bundles are sellable (have items).
     * Uses cache to avoid repeated queries.
     */
    public static function isSellable(int $productId): bool
    {
        $bundles = self::forProduct($productId);

        if ($bundles->isEmpty()) {
            return true; // No bundles = sellable as regular product
        }

        return $bundles->contains(fn($bundle) => $bundle->items && $bundle->items->isNotEmpty());
    }

    /**
     * Batch check sellability for multiple products.
     * Returns map of productId => bool.
     */
    public static function areSellable(array $productIds): array
    {
        // Pre-fetch all bundles in one query
        self::forProducts($productIds);

        $result = [];
        foreach ($productIds as $id) {
            $result[$id] = self::isSellable($id);
        }

        return $result;
    }

    /**
     * Clear the cache (useful for testing or long-running processes).
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}

