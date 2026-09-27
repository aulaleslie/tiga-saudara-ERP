<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Services\BundleLifecycle\ProductBundleLifecycleEvaluator;

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
     * Fetch all eligible bundles that belong to the given product and setting, eager loading items & products.
     */
    public static function forProduct(int $productId, int $settingId, bool $fresh = false): Collection
    {
        $cacheKey = "{$productId}:{$settingId}";

        if ($fresh || !isset(self::$cache[$cacheKey])) {
            $bundles = ProductBundle::with('items.product')
                ->where('parent_product_id', $productId)
                ->where('setting_id', $settingId)
                ->get();

            $evaluator = app(ProductBundleLifecycleEvaluator::class);
            $eligible = $bundles->filter(function ($bundle) use ($evaluator, $settingId, $productId) {
                return $evaluator->evaluateForSelection($bundle, $settingId, $productId)->isEligible;
            })->values();

            self::$cache[$cacheKey] = $eligible;
        }

        return self::$cache[$cacheKey];
    }

    /**
     * Batch-fetch eligible bundles for multiple products at once (reduces N+1).
     * Returns a map of productId => Collection of bundles.
     */
    public static function forProducts(array $productIds, int $settingId, bool $fresh = false): array
    {
        $productIds = array_filter(array_unique($productIds), fn($id) => $id > 0);

        if (empty($productIds)) {
            return [];
        }

        $evaluator = app(ProductBundleLifecycleEvaluator::class);

        // Find which IDs are not yet cached for this setting
        $uncachedIds = $fresh
            ? $productIds
            : array_filter($productIds, fn($id) => !isset(self::$cache["{$id}:{$settingId}"]));

        if (!empty($uncachedIds)) {
            // Batch fetch all bundles for uncached products in this setting
            $bundles = ProductBundle::with('items.product')
                ->whereIn('parent_product_id', $uncachedIds)
                ->where('setting_id', $settingId)
                ->get()
                ->groupBy('parent_product_id');

            // Populate cache - filtering for eligibility
            foreach ($uncachedIds as $id) {
                $productBundles = $bundles->get($id, collect());
                $eligible = $productBundles->filter(function ($bundle) use ($evaluator, $settingId, $id) {
                    return $evaluator->evaluateForSelection($bundle, $settingId, $id)->isEligible;
                })->values();

                self::$cache["{$id}:{$settingId}"] = $eligible;
            }
        }

        $result = [];
        foreach ($productIds as $id) {
            $result[$id] = self::$cache["{$id}:{$settingId}"] ?? collect();
        }

        return $result;
    }

    /**
     * Check if a product has any valid bundles that can be sold.
     *
     * @param int $productId
     * @param int $settingId
     * @param bool $fresh
     * @return bool
     */
    public static function isSellable(int $productId, int $settingId, bool $fresh = false): bool
    {
        return self::forProduct($productId, $settingId, $fresh)->isNotEmpty();
    }

    /**
     * Batch check if multiple products have valid bundles.
     *
     * @param array<int> $productIds
     * @param int $settingId
     * @param bool $fresh
     * @return array<int, bool>
     */
    public static function areSellable(array $productIds, int $settingId, bool $fresh = false): array
    {
        // Pre-fetch all bundles in one query
        self::forProducts($productIds, $settingId, $fresh);

        $result = [];
        foreach ($productIds as $id) {
            $result[$id] = self::isSellable($id, $settingId, $fresh);
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
