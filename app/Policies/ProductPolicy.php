<?php

namespace App\Policies;

use App\Models\User;
use Modules\Product\Entities\Product;

class ProductPolicy
{
    /**
     * Can the user preview or execute UOM normalization for this product?
     */
    public function uomNormalize(User $user, Product $product): bool
    {
        if ($product->stock_managed !== true) {
            return false;
        }

        if ($product->merged_into_id !== null) {
            return false;
        }

        // Super Admin bypass
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        return $user->hasPermissionTo('purchases.received.uom-normalize');
    }
}
