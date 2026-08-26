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
        $product = \Modules\Product\Entities\Product::find($productId);
        if (! $product) {
            return;
        }

        $settingIds = Setting::pluck('id');
        $existingPrices = ProductPrice::where('product_id', $productId)->get()->keyBy('setting_id');
        $operationUuid = (string) \Illuminate\Support\Str::uuid();

        ProductPrice::seedForSettings(
            $productId,
            ['last_purchase_price' => $lastPurchasePrice],
            $settingIds
        );

        $snapshots = [];
        $newPriceVal = (float) $lastPurchasePrice;

        foreach ($settingIds as $sId) {
            $prev = $existingPrices->get($sId);
            $beforeVal = $prev ? (float) $prev->last_purchase_price : 0.0;

            $snapshots[] = [
                'setting_id' => $sId,
                'before' => ['last_purchase_price' => $beforeVal],
                'after' => ['last_purchase_price' => $newPriceVal],
            ];
        }

        app(ProductPriceFeedRecorder::class)->record(
            \Modules\Product\Entities\ProductPriceFeedEvent::TYPE_PRODUCT_PRICE_UPDATED,
            \Modules\Product\Entities\ProductPriceFeedEvent::SUBJECT_PRODUCT,
            $product->id,
            $product->product_name,
            $product->product_code,
            $snapshots,
            \Modules\Product\Entities\ProductPriceFeedEvent::SOURCE_PURCHASE_SYNC,
            null,
            $operationUuid
        );
    }
}
