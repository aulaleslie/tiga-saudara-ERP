<?php

namespace Modules\Purchase\Services;

class PurchaseCostHelper
{
    /**
     * Calculate unit DPP cost from line totals.
     * 
     * @param float|string $subTotal
     * @param float|string $productTaxAmount
     * @param float|string $productDiscountAmount
     * @param float|string $quantity
     * @return float
     */
    public static function calculateUnitCost($subTotal, $productTaxAmount, $productDiscountAmount, $quantity): float
    {
        $qty = (float) $quantity;
        if ($qty <= 0) {
            return 0.0;
        }

        // DPP = sub_total (already discounted) - tax
        // Do not subtract discount again; it's already applied to sub_total in cart/import flows
        $lineDpp = (float) $subTotal - (float) $productTaxAmount;

        return $lineDpp / $qty;
    }
}
