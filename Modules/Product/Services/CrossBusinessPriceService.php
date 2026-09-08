<?php

namespace Modules\Product\Services;

use Modules\Setting\Entities\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\ProductUnitConversionPrice;
use Illuminate\Support\Carbon;
use Exception;

class CrossBusinessPriceService
{
    /**
     * Load prices for a product across all businesses (settings).
     * Defaults absent values to zero and includes version metadata.
     */
    public function loadPricesForProduct(Product $product): array
    {
        $settings = Setting::all();
        $existingPrices = ProductPrice::where('product_id', $product->id)
            ->get()
            ->keyBy('setting_id');

        $result = [];

        foreach ($settings as $setting) {
            $price = $existingPrices->get($setting->id);

            if ($price) {
                $result[] = [
                    'setting_id' => $setting->id,
                    'business_name' => $setting->company_name, // Assuming company_name exists, or whatever name field is used
                    'sale_price' => $price->sale_price,
                    'tier_1_price' => $price->tier_1_price,
                    'tier_2_price' => $price->tier_2_price,
                    'last_purchase_price' => $price->last_purchase_price,
                    'average_purchase_price' => $price->average_purchase_price,
                    'version' => $price->updated_at ? $price->updated_at->format('Y-m-d H:i:s.u') : null, // high precision version
                    'is_existing' => true,
                ];
            } else {
                $result[] = [
                    'setting_id' => $setting->id,
                    'business_name' => $setting->company_name,
                    'sale_price' => 0,
                    'tier_1_price' => 0,
                    'tier_2_price' => 0,
                    'last_purchase_price' => 0,
                    'average_purchase_price' => 0,
                    'version' => null,
                    'is_existing' => false,
                ];
            }
        }

        return $result;
    }

    /**
     * Load conversion headers and matrix for a product across all businesses.
     */
    public function loadConversionPricesForProduct(Product $product): array
    {
        $product->loadMissing(['baseUnit', 'conversions.unit', 'conversions.prices']);

        $settings = Setting::all();
        $conversions = $product->conversions;

        $headers = [];
        foreach ($conversions as $conversion) {
            $unitName = $conversion->unit?->name ?? $conversion->unit?->short_name ?? ('Unit ' . $conversion->unit_id);
            $factor = (float) $conversion->conversion_factor;
            $factorFormatted = (floor($factor) == $factor) ? (string) (int) $factor : rtrim(rtrim(number_format($factor, 4, '.', ''), '0'), '.');
            $baseUnitName = $product->baseUnit?->name ?? $product->baseUnit?->short_name ?? 'Unit';

            $headers[] = [
                'id' => $conversion->id,
                'unit_id' => $conversion->unit_id,
                'unit_name' => $unitName,
                'conversion_factor' => $factor,
                'conversion_factor_formatted' => $factorFormatted,
                'header_title' => "{$unitName} · {$factorFormatted} {$baseUnitName}",
            ];
        }

        $matrix = [];
        foreach ($settings as $setting) {
            $row = [
                'setting_id' => $setting->id,
                'business_name' => $setting->company_name,
                'conversions' => [],
            ];

            foreach ($conversions as $conversion) {
                $priceRow = $conversion->priceForSetting($setting->id);
                $isExisting = $priceRow !== null;
                $priceVal = $isExisting ? (float) $priceRow->price : null;
                $version = ($isExisting && $priceRow->updated_at) ? $priceRow->updated_at->format('Y-m-d H:i:s.u') : null;

                $row['conversions'][] = [
                    'conversion_id' => $conversion->id,
                    'price' => $priceVal,
                    'formatted_price' => $isExisting ? number_format($priceVal, 2, ',', '.') : '',
                    'canonical_price' => $isExisting ? number_format($priceVal, 2, '.', '') : '',
                    'is_existing' => $isExisting,
                    'version' => $version,
                ];
            }

            $matrix[] = $row;
        }

        return [
            'headers' => $headers,
            'matrix' => $matrix,
        ];
    }

    /**
     * Build and sign the loaded state snapshot for conversion pricing.
     */
    public function generateConversionSnapshot(Product $product): array
    {
        $product->loadMissing(['baseUnit', 'conversions.unit', 'conversions.prices']);

        $settings = Setting::all()->sortBy('id');
        $conversions = $product->conversions->sortBy('id');

        $businessIds = $settings->pluck('id')->all();
        $conversionStructure = [];
        $pricesState = [];

        foreach ($conversions as $conversion) {
            $conversionStructure[] = [
                'id' => $conversion->id,
                'unit_id' => $conversion->unit_id,
                'base_unit_id' => $conversion->base_unit_id,
                'conversion_factor' => (string) (float) $conversion->conversion_factor,
            ];

            foreach ($settings as $setting) {
                $priceRow = $conversion->priceForSetting($setting->id);
                $pricesState[] = [
                    'conversion_id' => $conversion->id,
                    'setting_id' => $setting->id,
                    'exists' => $priceRow !== null,
                    'price' => $priceRow !== null ? number_format((float) $priceRow->price, 2, '.', '') : null,
                    'version' => ($priceRow && $priceRow->updated_at) ? $priceRow->updated_at->format('Y-m-d H:i:s.u') : null,
                ];
            }
        }

        $snapshotData = [
            'product_id' => $product->id,
            'base_unit_id' => $product->base_unit_id,
            'business_ids' => $businessIds,
            'conversions' => $conversionStructure,
            'prices' => $pricesState,
        ];

        $encodedPayload = json_encode($snapshotData);
        $key = (string) config('app.key');
        $signature = hash_hmac('sha256', $encodedPayload, $key);

        return [
            'data' => base64_encode($encodedPayload),
            'signature' => $signature,
        ];
    }

    /**
     * Verify the signed snapshot integrity and product binding.
     */
    public function verifySnapshotIntegrity(Product $product, ?string $encodedData, ?string $signature): ?array
    {
        if (empty($encodedData) || empty($signature)) {
            return null;
        }

        $json = base64_decode($encodedData, true);
        if ($json === false) {
            return null;
        }

        $key = (string) config('app.key');
        $expectedSignature = hash_hmac('sha256', $json, $key);

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || ($data['product_id'] ?? null) !== $product->id) {
            return null;
        }

        return $data;
    }

    /**
     * Save base and conversion prices across all businesses atomically with optimistic locking.
     */
    public function savePricesForProduct(
        Product $product,
        array $pricesData,
        ?array $conversionsData = null,
        ?string $snapshotData = null,
        ?string $snapshotSignature = null
    ): void {
        $allSettings = Setting::pluck('id')->all();
        $submittedSettings = array_column($pricesData, 'setting_id');
        
        // Check exact match (no missing, no extra)
        if (count(array_diff($allSettings, $submittedSettings)) > 0 || count(array_diff($submittedSettings, $allSettings)) > 0) {
            throw new Exception("Submitted prices do not exactly match the current set of businesses. Please reload and try again.");
        }

        DB::transaction(function () use (
            $product,
            $pricesData,
            $conversionsData,
            $snapshotData,
            $snapshotSignature,
            $allSettings
        ) {
            // Consistent product-row lock acquired before reading/mutating conversions or prices
            $lockedProduct = Product::where('id', $product->id)->lockForUpdate()->first();
            if (!$lockedProduct) {
                throw new Exception("Produk tidak ditemukan.");
            }

            // If conversion snapshot or conversionsData are provided, or product has conversions, validate conversion state
            $currentConversions = ProductUnitConversion::where('product_id', $product->id)
                ->with('prices')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $hasConversions = $currentConversions->isNotEmpty();

            if ($hasConversions || !empty($conversionsData) || !empty($snapshotData)) {
                if (empty($snapshotData) || empty($snapshotSignature)) {
                    throw new Exception("Snapshot bukti data konversi tidak valid atau hilang. Silakan muat ulang halaman.");
                }

                $verifiedSnapshot = $this->verifySnapshotIntegrity($product, $snapshotData, $snapshotSignature);
                if (!$verifiedSnapshot) {
                    throw new Exception("Bukti data konversi telah diubah atau tidak valid. Silakan muat ulang halaman.");
                }

                // Verify base_unit_id has not changed
                if ((int) ($verifiedSnapshot['base_unit_id'] ?? 0) !== (int) ($lockedProduct->base_unit_id ?? 0)) {
                    throw new Exception("Struktur satuan dasar produk telah diperbarui. Silakan muat ulang halaman.");
                }

                // Verify business_ids in snapshot match current business IDs
                $snapBusinessIds = $verifiedSnapshot['business_ids'] ?? [];
                sort($snapBusinessIds);
                $currBusinessIds = $allSettings;
                sort($currBusinessIds);
                if ($snapBusinessIds !== $currBusinessIds) {
                    throw new Exception("Daftar bisnis telah berubah. Silakan muat ulang halaman.");
                }

                // Verify snapshot conversion structure matches current DB
                $snapConversions = collect($verifiedSnapshot['conversions'] ?? [])->keyBy('id');
                if ($snapConversions->count() !== $currentConversions->count()) {
                    throw new Exception("Struktur konversi produk telah berubah. Silakan muat ulang halaman.");
                }

                foreach ($currentConversions as $cId => $currConv) {
                    $snapConv = $snapConversions->get($cId);
                    if (!$snapConv) {
                        throw new Exception("Struktur konversi produk telah berubah. Silakan muat ulang halaman.");
                    }
                    if (
                        (int) $snapConv['unit_id'] !== (int) $currConv->unit_id ||
                        (int) $snapConv['base_unit_id'] !== (int) $currConv->base_unit_id ||
                        (string) (float) $snapConv['conversion_factor'] !== (string) (float) $currConv->conversion_factor
                    ) {
                        throw new Exception("Faktor atau unit konversi telah diperbarui oleh pengguna lain. Silakan muat ulang halaman.");
                    }
                }

                // Verify snapshot conversion prices match current DB prices
                $snapPrices = collect($verifiedSnapshot['prices'] ?? [])->groupBy('conversion_id');
                foreach ($currentConversions as $cId => $currConv) {
                    $snapConvPrices = ($snapPrices->get($cId) ?? collect())->keyBy('setting_id');

                    foreach ($allSettings as $sId) {
                        $currentPriceRow = ProductUnitConversionPrice::where('product_unit_conversion_id', $cId)
                            ->where('setting_id', $sId)
                            ->lockForUpdate()
                            ->first();

                        $snapPrice = $snapConvPrices->get($sId);
                        $snapExists = $snapPrice['exists'] ?? false;
                        $snapPriceVal = $snapPrice['price'] ?? null;
                        $snapVersion = $snapPrice['version'] ?? null;

                        if ($currentPriceRow) {
                            if (!$snapExists) {
                                throw new Exception("Harga konversi telah dibuat oleh pengguna lain. Silakan muat ulang halaman.");
                            }
                            $currentPriceVal = number_format((float) $currentPriceRow->price, 2, '.', '');
                            $currentVersion = $currentPriceRow->updated_at ? $currentPriceRow->updated_at->format('Y-m-d H:i:s.u') : null;

                            if ($currentVersion !== $snapVersion || $currentPriceVal !== $snapPriceVal) {
                                throw new Exception("Harga konversi telah diperbarui oleh pengguna lain. Silakan muat ulang halaman.");
                            }
                        } else {
                            if ($snapExists) {
                                throw new Exception("Harga konversi telah dihapus oleh pengguna lain. Silakan muat ulang halaman.");
                            }
                        }
                    }
                }

                // Validate submitted conversionsData matrix completeness and ownership
                $submittedConversions = $conversionsData ?? [];

                if ($currentConversions->isNotEmpty()) {
                    $submittedConvBySetting = collect($submittedConversions)->groupBy('setting_id');

                    if ($submittedConvBySetting->count() !== count($allSettings)) {
                        throw new Exception("Data harga konversi tidak lengkap untuk seluruh bisnis.");
                    }

                    foreach ($allSettings as $sId) {
                        $settingCells = ($submittedConvBySetting->get($sId) ?? collect())->keyBy('conversion_id');
                        if ($settingCells->count() !== $currentConversions->count()) {
                            throw new Exception("Data harga konversi tidak lengkap untuk bisnis ID {$sId}.");
                        }

                        foreach ($currentConversions as $cId => $currConv) {
                            if (!$settingCells->has($cId)) {
                                throw new Exception("Data konversi ID {$cId} hilang untuk bisnis ID {$sId}.");
                            }
                        }
                    }

                    // Process conversion updates / creations
                    // Snapshot indexed for checking originally missing
                    $snapPricesByConvSetting = collect($verifiedSnapshot['prices'] ?? [])
                        ->keyBy(fn ($item) => $item['conversion_id'] . '_' . $item['setting_id']);

                    foreach ($submittedConversions as $cell) {
                        $cId = (int) $cell['conversion_id'];
                        $sId = (int) $cell['setting_id'];
                        $submittedPrice = $cell['price'] ?? null;

                        $snapItem = $snapPricesByConvSetting->get($cId . '_' . $sId);
                        $wasExisting = $snapItem['exists'] ?? false;

                        $existingConvPrice = ProductUnitConversionPrice::where('product_unit_conversion_id', $cId)
                            ->where('setting_id', $sId)
                            ->lockForUpdate()
                            ->first();

                        if ($existingConvPrice) {
                            // Existing row: must have numeric price (cannot be null/blank)
                            if ($submittedPrice === null || $submittedPrice === '') {
                                throw new Exception("Harga konversi yang sudah ada tidak boleh dikosongkan.");
                            }

                            // Update only conversion price value, preserve sales_enabled & purchase_enabled flags
                            $existingConvPrice->price = (float) $submittedPrice;
                            $existingConvPrice->save();
                        } else {
                            // Originally missing row:
                            // If blank or null, leave untouched (do not create row)
                            if ($submittedPrice === null || $submittedPrice === '') {
                                continue;
                            }

                            // If explicitly configured (including 0), create with existing defaults
                            ProductUnitConversionPrice::create([
                                'product_unit_conversion_id' => $cId,
                                'setting_id' => $sId,
                                'price' => (float) $submittedPrice,
                                'sales_enabled' => true,
                                'purchase_enabled' => true,
                            ]);
                        }
                    }
                } else {
                    if (!empty($submittedConversions)) {
                        throw new Exception("Produk ini tidak memiliki konversi unit.");
                    }
                }
            }

            // Save base prices
            $snapshots = [];
            $operationUuid = (string) \Illuminate\Support\Str::uuid();

            foreach ($pricesData as $data) {
                $settingId = $data['setting_id'];
                $existingPrice = ProductPrice::where('product_id', $product->id)
                    ->where('setting_id', $settingId)
                    ->lockForUpdate() // For preventing race conditions on create
                    ->first();

                if ($existingPrice) {
                    // Check version for optimistic locking
                    $submittedVersion = $data['version'] ?? null;
                    $currentVersion = $existingPrice->updated_at ? $existingPrice->updated_at->format('Y-m-d H:i:s.u') : null;

                    if ($submittedVersion !== $currentVersion) {
                        if (empty($submittedVersion)) {
                            throw new Exception("Price data changed. Reload and try again.");
                        }
                        throw new Exception("Price data for setting ID {$settingId} has been updated by another user. Please refresh and try again.");
                    }

                    $beforeSnapshot = [
                        'sale_price' => (float) $existingPrice->sale_price,
                        'tier_1_price' => (float) $existingPrice->tier_1_price,
                        'tier_2_price' => (float) $existingPrice->tier_2_price,
                        'last_purchase_price' => (float) $existingPrice->last_purchase_price,
                    ];

                    $existingPrice->update([
                        'sale_price' => $data['sale_price'],
                        'tier_1_price' => $data['tier_1_price'],
                        'tier_2_price' => $data['tier_2_price'],
                        'last_purchase_price' => $data['last_purchase_price'],
                        // average_purchase_price and tax IDs are NOT updated
                    ]);

                    $afterSnapshot = [
                        'sale_price' => (float) $data['sale_price'],
                        'tier_1_price' => (float) $data['tier_1_price'],
                        'tier_2_price' => (float) $data['tier_2_price'],
                        'last_purchase_price' => (float) $data['last_purchase_price'],
                    ];

                    $snapshots[] = [
                        'setting_id' => $settingId,
                        'before' => $beforeSnapshot,
                        'after' => $afterSnapshot,
                    ];
                } else {
                    if (!empty($data['version'])) {
                        // The user submitted a version for a row that doesn't exist? Stale state.
                        throw new Exception("Price data for setting ID {$settingId} is out of sync. Please refresh and try again.");
                    }

                    try {
                        ProductPrice::create([
                            'product_id' => $product->id,
                            'setting_id' => $settingId,
                            'sale_price' => $data['sale_price'],
                            'tier_1_price' => $data['tier_1_price'],
                            'tier_2_price' => $data['tier_2_price'],
                            'last_purchase_price' => $data['last_purchase_price'],
                            'average_purchase_price' => 0,
                            'sale_tax_id' => null,
                            'purchase_tax_id' => null,
                        ]);

                        $snapshots[] = [
                            'setting_id' => $settingId,
                            'before' => ['sale_price' => 0.0, 'tier_1_price' => 0.0, 'tier_2_price' => 0.0, 'last_purchase_price' => 0.0],
                            'after' => [
                                'sale_price' => (float) $data['sale_price'],
                                'tier_1_price' => (float) $data['tier_1_price'],
                                'tier_2_price' => (float) $data['tier_2_price'],
                                'last_purchase_price' => (float) $data['last_purchase_price'],
                            ],
                        ];
                    } catch (QueryException $e) {
                        // Convert unique (product_id, setting_id) constraint violation to user-facing conflict
                        if ($e->errorInfo[1] == 1062 || $e->getCode() == 23000) {
                            throw new Exception("Price data changed. Reload and try again.");
                        }
                        throw new Exception("An error occurred while saving price data. Please try again.");
                    }
                }
            }

            app(ProductPriceFeedRecorder::class)->record(
                \Modules\Product\Entities\ProductPriceFeedEvent::TYPE_PRODUCT_PRICE_UPDATED,
                \Modules\Product\Entities\ProductPriceFeedEvent::SUBJECT_PRODUCT,
                $product->id,
                $product->product_name,
                $product->product_code,
                $snapshots,
                \Modules\Product\Entities\ProductPriceFeedEvent::SOURCE_MANUAL,
                null,
                $operationUuid
            );
        });
    }
}
