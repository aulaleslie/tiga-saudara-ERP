<?php

namespace Modules\Pos\Services;

use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

/**
 * Single owner-bucket-aware stock mutation used by every POS Return
 * replacement dispatch path (same-owner and cross-owner).
 *
 * Bucket selection derives from the owner of the location where the physical
 * movement happens (`locations.setting_id` -> `settings.is_pkp`). POS terminal
 * configuration, detail tax metadata and the deprecated `products.setting_id`
 * column are never consulted for this decision.
 */
class PosReturnReplacementStockMutator
{
    public const BUCKET_TAX = 'quantity_tax';
    public const BUCKET_NON_TAX = 'quantity_non_tax';

    /**
     * Resolve the good-stock bucket column owned by the given location.
     */
    public function resolveBucketForLocation(int $locationId): string
    {
        $location = Location::query()->find($locationId);

        if (! $location) {
            throw new \RuntimeException("Lokasi #{$locationId} tidak ditemukan untuk menentukan kepemilikan stok.");
        }

        return $this->resolveBucketForSetting((int) $location->setting_id);
    }

    /**
     * Resolve the good-stock bucket column owned by the given setting.
     */
    public function resolveBucketForSetting(int $settingId): string
    {
        $isPkp = (bool) Setting::query()->whereKey($settingId)->value('is_pkp');

        return $isPkp ? self::BUCKET_TAX : self::BUCKET_NON_TAX;
    }

    /**
     * Resolve the owner setting id of a location.
     */
    public function resolveOwnerSettingId(int $locationId): int
    {
        $settingId = Location::query()->whereKey($locationId)->value('setting_id');

        if (! $settingId) {
            throw new \RuntimeException("Pemilik lokasi #{$locationId} tidak dapat ditentukan.");
        }

        return (int) $settingId;
    }

    /**
     * Decrement the replacement owner's correct good-stock bucket, recompute
     * aggregate values from all four condition/tax buckets, maintain the global
     * product quantity, and record the outbound ledger row.
     *
     * @return array{bucket: string, setting_id: int, location_id: int}
     */
    public function dispatchReplacement(
        int $productId,
        int $locationId,
        int $quantity,
        ?int $actorId,
        string $reason,
        ?int $settingId = null
    ): array {
        // Lock the product row before reading its quantity: concurrent
        // movements at other locations would otherwise lose this update.
        $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();

        // Ledger ownership must be the same owner whose bucket is decremented,
        // or stock moves under one owner while the ledger names another.
        $locationOwnerSettingId = $this->resolveOwnerSettingId($locationId);

        if ($settingId !== null && (int) $settingId !== $locationOwnerSettingId) {
            throw new \RuntimeException(sprintf(
                'Setting replacement (#%d) tidak cocok dengan pemilik lokasi #%d (#%d).',
                (int) $settingId,
                $locationId,
                $locationOwnerSettingId
            ));
        }

        $ownerSettingId = $locationOwnerSettingId;

        $bucket = $this->resolveBucketForSetting($ownerSettingId);

        $productStock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if (! $productStock) {
            throw new \RuntimeException("Stok tidak ditemukan untuk produk {$product->product_name} di lokasi replacement owner.");
        }

        $bucketBefore = (int) ($productStock->{$bucket} ?? 0);

        if ($bucketBefore < $quantity) {
            throw new \RuntimeException('Stok produk pengganti tidak mencukupi di lokasi sumber asli retur.');
        }

        $previousQuantity = (int) $product->product_quantity;
        $previousQuantityAtLocation = (int) $productStock->quantity;

        $productStock->{$bucket} = $bucketBefore - $quantity;

        $productStock->broken_quantity = (int) ($productStock->broken_quantity_non_tax ?? 0)
            + (int) ($productStock->broken_quantity_tax ?? 0);

        $productStock->quantity = (int) ($productStock->quantity_non_tax ?? 0)
            + (int) ($productStock->quantity_tax ?? 0)
            + (int) ($productStock->broken_quantity_non_tax ?? 0)
            + (int) ($productStock->broken_quantity_tax ?? 0);

        $productStock->save();

        $product->product_quantity = $previousQuantity - $quantity;
        $product->save();

        $isTaxBucket = $bucket === self::BUCKET_TAX;

        Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $ownerSettingId,
            'quantity' => -$quantity,
            'current_quantity' => (int) $product->product_quantity,
            'broken_quantity' => (int) ($productStock->broken_quantity ?? 0),
            'location_id' => $locationId,
            'user_id' => $actorId,
            'reason' => $reason,
            'type' => 'DISPATCH_RETURN',
            'previous_quantity' => $previousQuantity,
            'after_quantity' => (int) $product->product_quantity,
            'previous_quantity_at_location' => $previousQuantityAtLocation,
            'after_quantity_at_location' => (int) ($productStock->quantity ?? 0),
            'quantity_non_tax' => $isTaxBucket ? 0 : $quantity,
            'quantity_tax' => $isTaxBucket ? $quantity : 0,
            'broken_quantity_non_tax' => (int) ($productStock->broken_quantity_non_tax ?? 0),
            'broken_quantity_tax' => (int) ($productStock->broken_quantity_tax ?? 0),
        ]);

        return [
            'bucket' => $bucket,
            'setting_id' => $ownerSettingId,
            'location_id' => $locationId,
        ];
    }
}
