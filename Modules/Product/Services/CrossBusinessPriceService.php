<?php

namespace Modules\Product\Services;

use Modules\Setting\Entities\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\ProductUnitConversionPrice;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductPriceFeedEvent;
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
     * Load bundle headers and matrix for a product across all businesses.
     */
    public function loadBundlePricesForProduct(Product $product): array
    {
        $settings = Setting::all()->sortBy('id');

        $bundles = ProductBundle::where('parent_product_id', $product->id)
            ->whereNotNull('replica_group_uuid')
            ->where('replica_group_uuid', '!=', '')
            ->get();

        if ($bundles->isEmpty()) {
            return [
                'headers' => [],
                'matrix' => [],
            ];
        }

        $grouped = $bundles->groupBy('replica_group_uuid')->sortKeys();

        $headers = [];
        foreach ($grouped as $uuid => $groupBundles) {
            // Representative name: first non-empty bundle name sorted by ID
            $sortedBundles = $groupBundles->sortBy('id');
            $primaryBundle = $sortedBundles->first();
            $representativeName = $primaryBundle->name ?? 'Paket ' . substr($uuid, 0, 8);

            // Check if names differ across copies
            $distinctNames = $groupBundles->pluck('name')->filter()->unique();
            $hasDifferentNames = $distinctNames->count() > 1;

            $headers[] = [
                'replica_group_uuid' => $uuid,
                'representative_name' => $representativeName,
                'has_different_names' => $hasDifferentNames,
            ];
        }

        $matrix = [];
        foreach ($settings as $setting) {
            $row = [
                'setting_id' => $setting->id,
                'business_name' => $setting->company_name,
                'bundles' => [],
            ];

            foreach ($headers as $header) {
                $uuid = $header['replica_group_uuid'];
                $groupBundles = $grouped->get($uuid) ?? collect();
                $bundleForSetting = $groupBundles->firstWhere('setting_id', $setting->id);

                if ($bundleForSetting) {
                    $priceVal = (float) $bundleForSetting->bundle_sale_price;
                    $version = $bundleForSetting->updated_at ? $bundleForSetting->updated_at->format('Y-m-d H:i:s.u') : null;

                    $row['bundles'][] = [
                        'replica_group_uuid' => $uuid,
                        'bundle_id' => $bundleForSetting->id,
                        'bundle_name' => $bundleForSetting->name,
                        'is_active' => (bool) $bundleForSetting->is_active,
                        'price' => $priceVal,
                        'formatted_price' => number_format($priceVal, 2, ',', '.'),
                        'canonical_price' => number_format($priceVal, 2, '.', ''),
                        'is_existing' => true,
                        'version' => $version,
                    ];
                } else {
                    $row['bundles'][] = [
                        'replica_group_uuid' => $uuid,
                        'bundle_id' => null,
                        'bundle_name' => null,
                        'is_active' => false,
                        'price' => null,
                        'formatted_price' => '',
                        'canonical_price' => '',
                        'is_existing' => false,
                        'version' => null,
                    ];
                }
            }

            $matrix[] = $row;
        }

        return [
            'headers' => $headers,
            'matrix' => $matrix,
        ];
    }

    /**
     * Build and sign the loaded state snapshot for bundle pricing.
     */
    public function generateBundleSnapshot(Product $product): array
    {
        $settings = Setting::all()->sortBy('id');
        $businessIds = $settings->pluck('id')->all();

        $bundles = ProductBundle::where('parent_product_id', $product->id)
            ->whereNotNull('replica_group_uuid')
            ->where('replica_group_uuid', '!=', '')
            ->orderBy('id')
            ->get();

        $groups = $bundles->pluck('replica_group_uuid')->unique()->sort()->values()->all();

        $bundleCells = [];
        foreach ($bundles as $bundle) {
            $bundleCells[] = [
                'bundle_id' => $bundle->id,
                'setting_id' => (int) $bundle->setting_id,
                'replica_group_uuid' => (string) $bundle->replica_group_uuid,
                'price' => number_format((float) $bundle->bundle_sale_price, 2, '.', ''),
                'version' => $bundle->updated_at ? $bundle->updated_at->format('Y-m-d H:i:s.u') : null,
            ];
        }

        $snapshotData = [
            'product_id' => $product->id,
            'business_ids' => $businessIds,
            'replica_groups' => $groups,
            'bundle_cells' => $bundleCells,
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
     * Save base, conversion, and bundle prices across all businesses atomically with optimistic locking.
     */
    public function savePricesForProduct(
        Product $product,
        array $pricesData,
        ?array $conversionsData = null,
        ?string $snapshotData = null,
        ?string $snapshotSignature = null,
        ?array $bundlesData = null,
        ?string $bundleSnapshotData = null,
        ?string $bundleSnapshotSignature = null
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
            $bundlesData,
            $bundleSnapshotData,
            $bundleSnapshotSignature,
            $allSettings
        ) {
            // Consistent product-row lock acquired before reading/mutating conversions, bundles, or prices
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

            // --- Bundle Pricing Validation & Persistence ---
            $currentBundles = ProductBundle::where('parent_product_id', $product->id)
                ->whereNotNull('replica_group_uuid')
                ->where('replica_group_uuid', '!=', '')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $hasGroupedBundles = $currentBundles->isNotEmpty();

            $bundleFeedSnapshotsByGroup = [];

            if ($hasGroupedBundles || !empty($bundlesData) || !empty($bundleSnapshotData)) {
                if (empty($bundleSnapshotData) || empty($bundleSnapshotSignature)) {
                    throw new Exception("Snapshot bukti data harga paket tidak valid atau hilang. Silakan muat ulang halaman.");
                }

                $verifiedBundleSnapshot = $this->verifySnapshotIntegrity($product, $bundleSnapshotData, $bundleSnapshotSignature);
                if (!$verifiedBundleSnapshot) {
                    throw new Exception("Bukti data harga paket telah diubah atau tidak valid. Silakan muat ulang halaman.");
                }

                // Verify business_ids in snapshot match current business IDs
                $snapBusinessIds = $verifiedBundleSnapshot['business_ids'] ?? [];
                sort($snapBusinessIds);
                $currBusinessIds = $allSettings;
                sort($currBusinessIds);
                if ($snapBusinessIds !== $currBusinessIds) {
                    throw new Exception("Daftar bisnis telah berubah. Silakan muat ulang halaman.");
                }

                // Verify snapshot replica_groups match current DB replica groups
                $snapGroups = $verifiedBundleSnapshot['replica_groups'] ?? [];
                sort($snapGroups);
                $currGroups = $currentBundles->pluck('replica_group_uuid')->unique()->sort()->values()->all();
                if ($snapGroups !== $currGroups) {
                    throw new Exception("Struktur grup paket produk telah berubah. Silakan muat ulang halaman.");
                }

                // Verify snapshot bundle cells match current DB bundles exactly
                $snapBundleCells = collect($verifiedBundleSnapshot['bundle_cells'] ?? [])->keyBy('bundle_id');
                if ($snapBundleCells->count() !== $currentBundles->count()) {
                    throw new Exception("Struktur paket produk telah berubah. Silakan muat ulang halaman.");
                }

                foreach ($currentBundles as $bId => $currBundle) {
                    $snapCell = $snapBundleCells->get($bId);
                    if (!$snapCell) {
                        throw new Exception("Struktur paket produk telah berubah. Silakan muat ulang halaman.");
                    }

                    if (
                        (int) $snapCell['setting_id'] !== (int) $currBundle->setting_id ||
                        (string) $snapCell['replica_group_uuid'] !== (string) $currBundle->replica_group_uuid
                    ) {
                        throw new Exception("Struktur grup atau bisnis paket telah diubah oleh pengguna lain. Silakan muat ulang halaman.");
                    }

                    $currentPriceVal = number_format((float) $currBundle->bundle_sale_price, 2, '.', '');
                    $currentVersion = $currBundle->updated_at ? $currBundle->updated_at->format('Y-m-d H:i:s.u') : null;

                    if ($currentVersion !== ($snapCell['version'] ?? null) || $currentPriceVal !== ($snapCell['price'] ?? null)) {
                        throw new Exception("Harga paket telah diperbarui oleh pengguna lain. Silakan muat ulang halaman.");
                    }
                }

                // Validate submitted bundlesData matrix completeness and ownership
                $submittedBundles = $bundlesData ?? [];

                if ($currentBundles->isNotEmpty()) {
                    if (count($submittedBundles) !== $currentBundles->count()) {
                        throw new Exception("Data harga paket yang dikirimkan tidak lengkap atau berlebih.");
                    }

                    $submittedBundleIds = [];
                    $submittedCellsByKey = [];

                    foreach ($submittedBundles as $bCell) {
                        $bundleId = (int) ($bCell['bundle_id'] ?? 0);
                        $settingId = (int) ($bCell['setting_id'] ?? 0);
                        $uuid = (string) ($bCell['replica_group_uuid'] ?? '');
                        $submittedPrice = $bCell['bundle_sale_price'] ?? null;
                        $submittedVersion = $bCell['version'] ?? null;

                        if (!$bundleId || !isset($currentBundles[$bundleId])) {
                            throw new Exception("Identitas paket ID {$bundleId} tidak valid atau bukan milik produk ini.");
                        }

                        if (in_array($bundleId, $submittedBundleIds, true)) {
                            throw new Exception("Duplikat data paket ID {$bundleId} terdeteksi.");
                        }
                        $submittedBundleIds[] = $bundleId;

                        $currBundle = $currentBundles[$bundleId];

                        if ((int) $currBundle->setting_id !== $settingId) {
                            throw new Exception("Bisnis untuk paket ID {$bundleId} tidak sesuai.");
                        }

                        if ((string) $currBundle->replica_group_uuid !== $uuid) {
                            throw new Exception("Grup replika untuk paket ID {$bundleId} tidak sesuai.");
                        }

                        if ((int) $currBundle->parent_product_id !== (int) $product->id) {
                            throw new Exception("Paket ID {$bundleId} bukan milik produk ini.");
                        }

                        $currVersion = $currBundle->updated_at ? $currBundle->updated_at->format('Y-m-d H:i:s.u') : null;
                        if ($submittedVersion !== $currVersion) {
                            throw new Exception("Data harga paket telah diperbarui oleh pengguna lain. Silakan muat ulang halaman.");
                        }

                        if ($submittedPrice === null || $submittedPrice === '' || !is_numeric($submittedPrice) || (float) $submittedPrice < 0) {
                            throw new Exception("Harga jual paket harus berupa angka positif.");
                        }

                        $beforePrice = (float) $currBundle->bundle_sale_price;
                        $newPrice = (float) $submittedPrice;

                        $beforePriceFormatted = number_format($beforePrice, 2, '.', '');
                        $newPriceFormatted = number_format($newPrice, 2, '.', '');

                        // Only save if price has actually changed to avoid dirtying timestamps
                        if ($beforePriceFormatted !== $newPriceFormatted) {
                            $currBundle->bundle_sale_price = $newPrice;
                            $currBundle->save();
                        }

                        // Group snapshots by replica group UUID for feed recording
                        if (!isset($bundleFeedSnapshotsByGroup[$uuid])) {
                            $bundleFeedSnapshotsByGroup[$uuid] = [
                                'representative_bundle' => $currBundle,
                                'snapshots' => [],
                            ];
                        }

                        $bundleFeedSnapshotsByGroup[$uuid]['snapshots'][] = [
                            'setting_id' => $settingId,
                            'before' => ['bundle_sale_price' => $beforePrice],
                            'after' => ['bundle_sale_price' => $newPrice],
                        ];
                    }
                } else {
                    if (!empty($submittedBundles)) {
                        throw new Exception("Produk ini tidak memiliki grup paket.");
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

            // Record bundle price feed events per replica group sharing the same operation UUID
            foreach ($bundleFeedSnapshotsByGroup as $uuid => $groupFeedData) {
                $representativeBundle = $groupFeedData['representative_bundle'];
                $parentProductName = $product->product_name;
                $parentProductCode = $product->product_code;
                $bundleName = $representativeBundle->name;
                $subjectName = $parentProductName ? "{$parentProductName} — {$bundleName}" : $bundleName;

                app(ProductPriceFeedRecorder::class)->record(
                    \Modules\Product\Entities\ProductPriceFeedEvent::TYPE_BUNDLE_PRICE_UPDATED,
                    \Modules\Product\Entities\ProductPriceFeedEvent::SUBJECT_BUNDLE,
                    $representativeBundle->id,
                    $subjectName,
                    $parentProductCode,
                    $groupFeedData['snapshots'],
                    \Modules\Product\Entities\ProductPriceFeedEvent::SOURCE_MANUAL,
                    null,
                    $operationUuid
                );
            }
        });
    }
}
