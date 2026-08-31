<?php

namespace Modules\Product\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Setting\Entities\Tax;
use Throwable;

class SerialConversionExecutionService
{
    public function __construct(
        protected SerialConversionEligibilityService $eligibilityService,
        protected SerialConversionPoolAggregator $poolAggregator,
        protected SerialConversionValidationService $validationService
    ) {}

    /**
     * Execute the conversion of existing stock to serialized in one atomic transaction.
     */
    public function executeConversion(Product $product, array $expectedPools, array $scannedSerialsPayload): array
    {
        try {
            return DB::transaction(function () use ($product, $expectedPools, $scannedSerialsPayload) {
                // 1. Lock product first (idempotency boundary)
                $lockedProduct = Product::where('id', $product->id)->lockForUpdate()->first();

                if (! $lockedProduct) {
                    return [
                        'success' => false,
                        'message' => 'Produk tidak ditemukan.',
                    ];
                }

                // If already converted, return idempotent success/info
                if ($lockedProduct->serial_number_required) {
                    return [
                        'success' => true,
                        'already_converted' => true,
                        'message' => 'Produk ini sudah dikonversi dan telah melacak nomor seri.',
                    ];
                }

                // 2. Lock all stock rows in deterministic order (by location_id ASC)
                $lockedStocks = ProductStock::where('product_id', $lockedProduct->id)
                    ->with(['location.setting'])
                    ->orderBy('location_id', 'asc')
                    ->lockForUpdate()
                    ->get();

                // 3. Re-run authoritative eligibility check
                $eligibility = $this->eligibilityService->checkEligibility($lockedProduct);
                if (! $eligibility->isEligible) {
                    return [
                        'success' => false,
                        'message' => 'Konversi diblokir: ' . implode(' ', $eligibility->blockingReasons),
                    ];
                }

                // 4. Recompute pools authoritatively and compare with expectedPools (stock-drift check)
                $currentPools = $this->poolAggregator->aggregate($lockedStocks);

                if (! $this->poolsMatch($currentPools, $expectedPools)) {
                    return [
                        'success' => false,
                        'stock_drift' => true,
                        'message' => 'Terjadi perubahan jumlah stok selama proses scanning. Silakan muat ulang halaman dan ulangi scanning.',
                    ];
                }

                // 5. Flatten and validate scanned serials
                $allScannedSerials = [];
                $poolAllocations = []; // [setting_id => ['normal_non_tax' => [...], 'normal_tax' => [...], 'broken_non_tax' => [...], 'broken_tax' => [...]]]

                // Require exact set of owners matching authoritative $currentPools (no missing, no extra)
                $scannedSettingIds = array_map('intval', array_keys($scannedSerialsPayload));
                $currentSettingIds = array_map('intval', array_keys($currentPools));

                sort($scannedSettingIds);
                sort($currentSettingIds);

                if ($scannedSettingIds !== $currentSettingIds) {
                    return [
                        'success' => false,
                        'message' => 'Daftar cabang/owner pada data pemindaian tidak sesuai dengan data stok produk saat ini.',
                    ];
                }

                foreach ($currentPools as $settingId => $ownerData) {
                    $settingId = (int) $settingId;
                    $settingPools = $scannedSerialsPayload[$settingId] ?? ($scannedSerialsPayload[(string) $settingId] ?? null);

                    if (! is_array($settingPools)) {
                        return [
                            'success' => false,
                            'message' => "Data pemindaian untuk cabang #{$settingId} tidak ditemukan di dalam request.",
                        ];
                    }

                    foreach (['normal_non_tax', 'normal_tax', 'broken_non_tax', 'broken_tax'] as $poolKey) {
                        $rawPool = $settingPools[$poolKey] ?? [];
                        if (! is_array($rawPool)) {
                            return [
                                'success' => false,
                                'message' => "Data pemindaian untuk pool {$poolKey} pada cabang #{$settingId} harus berupa daftar nomor seri.",
                            ];
                        }

                        $expectedQty = (int) ($currentPools[$settingId]['pools'][$poolKey] ?? 0);
                        if (count($rawPool) !== $expectedQty) {
                            return [
                                'success' => false,
                                'message' => "Jumlah serial number untuk pool {$poolKey} pada cabang #{$settingId} belum lengkap (diperlukan {$expectedQty}, diisi " . count($rawPool) . ').',
                            ];
                        }

                        $trimmedSerialsInPool = [];
                        foreach ($rawPool as $rawSerial) {
                            if (! is_string($rawSerial)) {
                                return [
                                    'success' => false,
                                    'message' => 'Terdapat nomor seri tidak valid di dalam data yang dikirimkan.',
                                ];
                            }

                            $s = trim($rawSerial);
                            if ($s === '') {
                                return [
                                    'success' => false,
                                    'message' => 'Terdapat nomor seri kosong di dalam data yang dikirimkan.',
                                ];
                            }
                            if (mb_strlen($s) > 255) {
                                return [
                                    'success' => false,
                                    'message' => "Nomor seri '{$s}' melebihi batas 255 karakter.",
                                ];
                            }

                            $trimmedSerialsInPool[] = $s;
                            $allScannedSerials[] = $s;
                        }

                        $poolAllocations[$settingId][$poolKey] = $trimmedSerialsInPool;
                    }
                }

                // Check page-wide uniqueness in payload
                if (count($allScannedSerials) !== count(array_unique($allScannedSerials))) {
                    return [
                        'success' => false,
                        'message' => 'Terdapat duplikasi nomor seri di dalam daftar serial yang dikirimkan.',
                    ];
                }

                // Check DB-wide uniqueness
                $existingInDb = ProductSerialNumber::whereIn('serial_number', $allScannedSerials)->pluck('serial_number')->toArray();
                if (! empty($existingInDb)) {
                    return [
                        'success' => false,
                        'message' => 'Beberapa nomor seri sudah terdaftar di database: ' . implode(', ', $existingInDb),
                    ];
                }

                // Resolve default tax for PPN pools
                $hasPpnSerials = false;
                foreach ($poolAllocations as $sId => $pools) {
                    if (! empty($pools['normal_tax']) || ! empty($pools['broken_tax'])) {
                        $hasPpnSerials = true;
                        break;
                    }
                }

                $defaultTaxId = null;
                if ($hasPpnSerials) {
                    $defaultTax = Tax::where('is_active', true)->where('is_default', true)->first();
                    if (! $defaultTax) {
                        return [
                            'success' => false,
                            'message' => 'Pajak standar (default tax) tidak ditemukan atau tidak aktif untuk alokasi stok PPN.',
                        ];
                    }
                    $defaultTaxId = $defaultTax->id;
                }

                // 6. Allocate scanned serials deterministically to original locations
                $userId = auth()->id();

                foreach ($poolAllocations as $settingId => $pools) {
                    // Get stocks for this setting sorted deterministically by location_id ASC
                    $settingStocks = $lockedStocks->filter(fn ($st) => $st->location?->setting_id === $settingId)->sortBy('location_id');

                    foreach (['normal_non_tax', 'normal_tax', 'broken_non_tax', 'broken_tax'] as $poolKey) {
                        $serialsToAllocate = $pools[$poolKey];
                        $isTax = in_array($poolKey, ['normal_tax', 'broken_tax'], true);
                        $isBroken = str_starts_with($poolKey, 'broken_');
                        $taxId = $isTax ? $defaultTaxId : null;
                        $status = $isBroken ? ProductSerialNumber::STATUS_BROKEN : ProductSerialNumber::STATUS_ACTIVE;

                        foreach ($settingStocks as $stock) {
                            if (empty($serialsToAllocate)) {
                                break;
                            }

                            $capacity = match ($poolKey) {
                                'normal_non_tax' => (int) round((float) $stock->quantity_non_tax),
                                'normal_tax' => (int) round((float) $stock->quantity_tax),
                                'broken_non_tax' => (int) round((float) $stock->broken_quantity_non_tax),
                                'broken_tax' => (int) round((float) $stock->broken_quantity_tax),
                            };

                            if ($capacity <= 0) {
                                continue;
                            }

                            $chunk = array_splice($serialsToAllocate, 0, $capacity);

                            foreach ($chunk as $sn) {
                                $createdSerial = ProductSerialNumber::create([
                                    'product_id' => $lockedProduct->id,
                                    'location_id' => $stock->location_id,
                                    'serial_number' => $sn,
                                    'tax_id' => $taxId,
                                    'status' => $status,
                                    'is_broken' => $isBroken,
                                    'is_in_return_process' => false,
                                ]);

                                SerialNumberHistory::create([
                                    'product_serial_number_id' => $createdSerial->id,
                                    'event_type' => 'STOCK_CONVERSION',
                                    'location_id' => $stock->location_id,
                                    'reference_type' => Product::class,
                                    'reference_id' => $lockedProduct->id,
                                    'user_id' => $userId,
                                    'note' => 'Konversi stok awal ke produk ber-serial number.',
                                ]);
                            }
                        }

                        if (! empty($serialsToAllocate)) {
                            throw new Exception("Gagal mengalokasikan serial number untuk pool {$poolKey} pada cabang #{$settingId}: kapasitas lokasi tidak mencukupi.");
                        }
                    }
                }

                // 7. Enable serial_number_required LAST
                $lockedProduct->update([
                    'serial_number_required' => true,
                ]);

                return [
                    'success' => true,
                    'message' => 'Berhasil mengonversi seluruh stok produk menjadi produk ber-serial number.',
                ];
            });
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Gagal melakukan konversi stok produk ID {$product->id} ke serial number: {$e->getMessage()}", [
                'product_id' => $product->id,
                'user_id' => auth()->id(),
                'exception' => $e,
            ]);

            return [
                'success' => false,
                'message' => 'Terjadi kesalahan sistem saat mengonversi stok produk. Silakan coba lagi atau hubungi administrator.',
            ];
        }
    }

    protected function poolsMatch(array $currentPools, array $expectedPools): bool
    {
        $currentKeys = array_map('intval', array_keys($currentPools));
        $expectedKeys = array_map('intval', array_keys($expectedPools));

        sort($currentKeys);
        sort($expectedKeys);

        if ($currentKeys !== $expectedKeys) {
            return false;
        }

        foreach ($currentPools as $settingId => $currentData) {
            $expectedData = $expectedPools[$settingId] ?? ($expectedPools[(string) $settingId] ?? null);
            if (! $expectedData) {
                return false;
            }

            foreach (['normal_non_tax', 'normal_tax', 'broken_non_tax', 'broken_tax'] as $poolKey) {
                $curQty = (int) ($currentData['pools'][$poolKey] ?? 0);
                $expQty = (int) ($expectedData['pools'][$poolKey] ?? 0);

                if ($curQty !== $expQty) {
                    return false;
                }
            }
        }

        return true;
    }
}
