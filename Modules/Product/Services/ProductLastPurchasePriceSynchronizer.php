<?php

namespace Modules\Product\Services;

use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;

class ProductLastPurchasePriceSynchronizer
{
    /**
     * Synchronize last_purchase_price across all settings for a product.
     */
    public function syncLastPurchasePrice(int $productId, float|int|string $lastPurchasePrice): void
    {
        $settingIds = Setting::pluck('id');

        ProductPrice::seedForSettings(
            $productId,
            ['last_purchase_price' => $lastPurchasePrice],
            $settingIds
        );
    }
}
