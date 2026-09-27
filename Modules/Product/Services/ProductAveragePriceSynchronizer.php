<?php

namespace Modules\Product\Services;

use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;

class ProductAveragePriceSynchronizer
{
    /**
     * Synchronize average_purchase_price across all settings for a product.
     */
    public function syncAveragePurchasePrice(int $productId, $averagePrice): void
    {
        $settingIds = Setting::pluck('id');
        
        ProductPrice::seedForSettings(
            $productId, 
            ['average_purchase_price' => $averagePrice], 
            $settingIds
        );
    }
}
