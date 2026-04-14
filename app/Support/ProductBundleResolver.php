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
     * Keyed by "{productId}:{settingId}"
     */
    private static array $cache = [];

    /**
     * Fetch all bundles that belong to the given product and setting, eager loading items & products.
     */
    public static function forProduct(int $productId, int $settingId): Collection
    {
        $cacheKey = "{$productId}:{$settingId}";

        if (!isset(self::$cache[$cacheKey])) {
            self::$cache[$cacheKey] = ProductBundle::with('items.product')
                ->where('parent_product_id', $productId)
                ->where('setting_id', $settingId)
                ->get();
        }

        return self::$cache[$cacheKey];
    }

    /**
     * Batch-fetch bundles for multiple products at once (reduces N+1).
     * Returns a map of productId => Collection of bundles.
     */
    public static function forProducts(array $productIds, int $settingId): array
    {
        $productIds = array_filter(array_unique($productIds), fn($id) => $id > 0);

        if (empty($productIds)) {
            return [];
        }

        // Find which IDs are not yet cached for this setting
        $uncachedIds = array_filter($productIds, fn($id) => !isset(self::$cache["{$id}:{$settingId}"]));

        if (!empty($uncachedIds)) {
            // Batch fetch all bundles for uncached products in this setting
            $bundles = ProductBundle::with('items.product')
                ->whereIn('parent_product_id', $uncachedIds)
                ->where('setting_id', $settingId)
                ->get()
                ->groupBy('parent_product_id');

            // Populate cache - including empty collections for products without bundles
            foreach ($uncachedIds as $id) {
                self::$cache["{$id}:{$settingId}"] = $bundles->get($id, collect());
            }
        }

        // Return requested products from cache
        $result = [];
        foreach ($productIds as $id) {
            $result[$id] = self::$cache["{$id}:{$settingId}"] ?? collect();
        }

        return $result;
    }

    /**
     * Check if a product's bundles are sellable (have items).
     * Uses cache to avoid repeated queries.
     */
    public static function isSellable(int $productId, int $settingId): bool
    {
        $bundles = self::forProduct($productId, $settingId);

        if ($bundles->isEmpty()) {
            return true; // No bundles = sellable as regular product
        }

        return $bundles->contains(fn($bundle) => $bundle->items && $bundle->items->isNotEmpty());
    }

    /**
     * Batch check sellability for multiple products.
     * Returns map of productId => bool.
     */
    public static function areSellable(array $productIds, int $settingId): array
    {
        // Pre-fetch all bundles in one query
        self::forProducts($productIds, $settingId);

        $result = [];
        foreach ($productIds as $id) {
            $result[$id] = self::isSellable($id, $settingId);
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
