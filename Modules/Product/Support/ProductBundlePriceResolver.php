<?php

namespace Modules\Product\Support;

use Modules\Product\Entities\ProductPrice;

class ProductBundlePriceResolver
{
    /**
     * Resolve component ProductPrice sale_price for a target setting with active-setting fallback.
     * Returns null if neither target setting nor active setting has a sale price.
     *
     * @param int $productId
     * @param int $targetSettingId
     * @param int|null $activeSettingId
     * @return float|null
     */
    public function resolveComponentSalePrice(int $productId, int $targetSettingId, ?int $activeSettingId = null): ?float
    {
        $targetPrice = ProductPrice::query()
            ->where('product_id', $productId)
            ->where('setting_id', $targetSettingId)
            ->value('sale_price');

        if ($targetPrice !== null) {
            return (float) $targetPrice;
        }

        if ($activeSettingId !== null && $activeSettingId !== $targetSettingId) {
            $fallbackPrice = ProductPrice::query()
                ->where('product_id', $productId)
                ->where('setting_id', $activeSettingId)
                ->value('sale_price');

            if ($fallbackPrice !== null) {
                return (float) $fallbackPrice;
            }
        }

        return null;
    }
}
