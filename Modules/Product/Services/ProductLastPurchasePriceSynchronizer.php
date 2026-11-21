<?php

namespace Modules\Product\Services;

use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;

class ProductLastPurchasePriceSynchronizer
{
    /** Synchronize the last purchase price only for the purchasing business. */
    public function syncLastPurchasePrice(int $productId, int $settingId, float|int|string $lastPurchasePrice): void
    {
        $product = Product::find($productId);
        if (! $product) {
            return;
        }

        $previous = ProductPrice::where('product_id', $productId)
            ->where('setting_id', $settingId)
            ->first();
        $before = (float) ($previous?->last_purchase_price ?? 0);
        $after = (float) $lastPurchasePrice;

        ProductPrice::upsertFor([
            'product_id' => $productId,
            'setting_id' => $settingId,
            'last_purchase_price' => $lastPurchasePrice,
        ]);

        app(ProductPriceFeedRecorder::class)->record(
            \Modules\Product\Entities\ProductPriceFeedEvent::TYPE_PRODUCT_PRICE_UPDATED,
            \Modules\Product\Entities\ProductPriceFeedEvent::SUBJECT_PRODUCT,
            $product->id,
            $product->product_name,
            $product->product_code,
            [[
                'setting_id' => $settingId,
                'before' => ['last_purchase_price' => $before],
                'after' => ['last_purchase_price' => $after],
            ]],
            \Modules\Product\Entities\ProductPriceFeedEvent::SOURCE_PURCHASE_SYNC,
            null,
            (string) \Illuminate\Support\Str::uuid()
        );
    }
}
