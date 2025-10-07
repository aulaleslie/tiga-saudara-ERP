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
     * Fetch all bundles that belong to the given product, eager loading items & products.
     */
    public static function forProduct(int $productId): Collection
    {
        return ProductBundle::with('items.product')
            ->where('parent_product_id', $productId)
            ->get();
    }
}

