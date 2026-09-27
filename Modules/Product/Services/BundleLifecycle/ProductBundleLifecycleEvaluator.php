<?php

namespace Modules\Product\Services\BundleLifecycle;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;

class ProductBundleLifecycleEvaluator
{
    /**
     * Get the current date in application business timezone.
     */
    public static function currentBusinessDate(): string
    {
        $timezone = config('app.timezone', 'Asia/Jakarta');
        return Carbon::now($timezone)->toDateString();
    }

    /**
     * Evaluate bundle eligibility for new selection (POS search/scan/add, Sales cart add).
     *
     * @param int|ProductBundle|null $bundle
     * @param int $settingId
     * @param int|null $expectedParentProductId
     * @param string|null $businessDate YYYY-MM-DD in business timezone (defaults to current)
     * @return BundleLifecycleEvaluationResult
     */
    public function evaluateForSelection(
        int|ProductBundle|null $bundle,
        int $settingId,
        ?int $expectedParentProductId = null,
        ?string $businessDate = null
    ): BundleLifecycleEvaluationResult {
        if ($bundle === null || (is_numeric($bundle) && (int) $bundle <= 0)) {
            return BundleLifecycleEvaluationResult::ineligible(
                [BundleLifecycleReason::DEFINITION_DELETED],
                [['type' => 'bundle', 'bundle_id' => is_numeric($bundle) ? (int)$bundle : null, 'reason' => BundleLifecycleReason::DEFINITION_DELETED, 'message' => BundleLifecycleReason::label(BundleLifecycleReason::DEFINITION_DELETED)]]
            );
        }

        if (is_numeric($bundle)) {
            $bundleModel = ProductBundle::with(['items.product'])->find((int) $bundle);
        } else {
            $bundleModel = $bundle;
            if (!$bundleModel->relationLoaded('items') || ($bundleModel->items->isNotEmpty() && !$bundleModel->items->first()->relationLoaded('product'))) {
                $bundleModel->loadMissing('items.product');
            }
        }

        if (! $bundleModel) {
            return BundleLifecycleEvaluationResult::ineligible(
                [BundleLifecycleReason::DEFINITION_DELETED],
                [['type' => 'bundle', 'bundle_id' => is_numeric($bundle) ? (int)$bundle : null, 'reason' => BundleLifecycleReason::DEFINITION_DELETED, 'message' => BundleLifecycleReason::label(BundleLifecycleReason::DEFINITION_DELETED)]]
            );
        }

        $reasons = [];
        $warnings = [];

        // 1. Setting check
        if ((int) $bundleModel->setting_id !== (int) $settingId) {
            $reasons[] = BundleLifecycleReason::SETTING_MISMATCH;
            $warnings[] = [
                'type' => 'bundle',
                'bundle_id' => $bundleModel->id,
                'bundle_name' => $bundleModel->name,
                'reason' => BundleLifecycleReason::SETTING_MISMATCH,
                'message' => BundleLifecycleReason::label(BundleLifecycleReason::SETTING_MISMATCH),
            ];
        }

        // Parent product check if expected
        if ($expectedParentProductId !== null && (int) $bundleModel->parent_product_id !== (int) $expectedParentProductId) {
            $reasons[] = BundleLifecycleReason::SETTING_MISMATCH;
            $warnings[] = [
                'type' => 'bundle',
                'bundle_id' => $bundleModel->id,
                'bundle_name' => $bundleModel->name,
                'reason' => BundleLifecycleReason::SETTING_MISMATCH,
                'message' => 'Produk induk paket tidak sesuai',
            ];
        }

        // 2. Active status check
        if (! (bool) $bundleModel->is_active) {
            $reasons[] = BundleLifecycleReason::DISABLED;
            $warnings[] = [
                'type' => 'bundle',
                'bundle_id' => $bundleModel->id,
                'bundle_name' => $bundleModel->name,
                'reason' => BundleLifecycleReason::DISABLED,
                'message' => BundleLifecycleReason::label(BundleLifecycleReason::DISABLED),
            ];
        }

        // 3. Inclusive date boundaries check
        $today = $businessDate ?? self::currentBusinessDate();

        if ($bundleModel->active_from !== null) {
            $from = is_string($bundleModel->active_from) ? substr($bundleModel->active_from, 0, 10) : $bundleModel->active_from->toDateString();
            if ($today < $from) {
                $reasons[] = BundleLifecycleReason::NOT_STARTED;
                $warnings[] = [
                    'type' => 'bundle',
                    'bundle_id' => $bundleModel->id,
                    'bundle_name' => $bundleModel->name,
                    'reason' => BundleLifecycleReason::NOT_STARTED,
                    'message' => BundleLifecycleReason::label(BundleLifecycleReason::NOT_STARTED),
                    'active_from' => $from,
                ];
            }
        }

        if ($bundleModel->active_to !== null) {
            $to = is_string($bundleModel->active_to) ? substr($bundleModel->active_to, 0, 10) : $bundleModel->active_to->toDateString();
            if ($today > $to) {
                $reasons[] = BundleLifecycleReason::EXPIRED;
                $warnings[] = [
                    'type' => 'bundle',
                    'bundle_id' => $bundleModel->id,
                    'bundle_name' => $bundleModel->name,
                    'reason' => BundleLifecycleReason::EXPIRED,
                    'message' => BundleLifecycleReason::label(BundleLifecycleReason::EXPIRED),
                    'active_to' => $to,
                ];
            }
        }

        // 4. Composition check
        $items = $bundleModel->items;
        if ($items->isEmpty()) {
            $reasons[] = BundleLifecycleReason::EMPTY_COMPOSITION;
            $warnings[] = [
                'type' => 'bundle',
                'bundle_id' => $bundleModel->id,
                'bundle_name' => $bundleModel->name,
                'reason' => BundleLifecycleReason::EMPTY_COMPOSITION,
                'message' => BundleLifecycleReason::label(BundleLifecycleReason::EMPTY_COMPOSITION),
            ];
        } else {
            foreach ($items as $item) {
                $product = $item->product;
                if (! $product) {
                    $reasons[] = BundleLifecycleReason::MISSING_COMPONENT;
                    $warnings[] = [
                        'type' => 'component',
                        'bundle_id' => $bundleModel->id,
                        'product_id' => $item->product_id,
                        'reason' => BundleLifecycleReason::MISSING_COMPONENT,
                        'message' => BundleLifecycleReason::label(BundleLifecycleReason::MISSING_COMPONENT),
                    ];
                    continue;
                }

                // Check merged/retired or deleted
                $isComponentActive = $product->merged_into_id === null;
                if (! $isComponentActive) {
                    $reasons[] = BundleLifecycleReason::INACTIVE_COMPONENT;
                    $warnings[] = [
                        'type' => 'component',
                        'bundle_id' => $bundleModel->id,
                        'product_id' => $product->id,
                        'product_name' => $product->product_name,
                        'reason' => BundleLifecycleReason::INACTIVE_COMPONENT,
                        'message' => "Komponen produk {$product->product_name} tidak aktif",
                    ];
                }
            }
        }

        if (!empty($reasons)) {
            return BundleLifecycleEvaluationResult::ineligible($reasons, $warnings, [
                'bundle_id' => $bundleModel->id,
                'bundle_name' => $bundleModel->name,
                'setting_id' => $settingId,
            ]);
        }

        return BundleLifecycleEvaluationResult::eligible([
            'bundle_id' => $bundleModel->id,
            'bundle_name' => $bundleModel->name,
            'setting_id' => $settingId,
        ]);
    }

    /**
     * Evaluate captured POS lines snapshot against live bundle definitions.
     *
     * @param array<int, array<string, mixed>> $cartLines Or list of lines with bundle metadata
     * @param int $settingId
     * @param string|null $businessDate
     * @return BundleLifecycleEvaluationResult
     */
    public function evaluatePosCartSnapshot(
        array $cartLines,
        int $settingId,
        ?string $businessDate = null
    ): BundleLifecycleEvaluationResult {
        $warnings = [];

        foreach ($cartLines as $line) {
            $bundleId = $line['bundle_id'] ?? ($line['options']['bundle_id'] ?? null);
            if (!$bundleId) {
                continue;
            }

            $lineWarnings = $this->evaluateCapturedBundleLine(
                bundleId: (int) $bundleId,
                parentProductId: (int) ($line['product_id'] ?? ($line['options']['product_id'] ?? 0)),
                settingId: $settingId,
                lineLabel: (string) ($line['product_name'] ?? ($line['name'] ?? 'Baris Paket')),
                capturedComponents: (array) ($line['bundle_items'] ?? ($line['options']['bundle_items'] ?? [])),
                businessDate: $businessDate,
                capturedParentStockManaged: isset($line['captured_stock_managed'])
                    ? (bool) $line['captured_stock_managed']
                    : (isset($line['stock_managed'])
                        ? (bool) $line['stock_managed']
                        : (isset($line['options']['captured_stock_managed'])
                            ? (bool) $line['options']['captured_stock_managed']
                            : (isset($line['options']['stock_managed']) ? (bool) $line['options']['stock_managed'] : null))),
                capturedParentSerialRequired: isset($line['captured_serial_number_required'])
                    ? (bool) $line['captured_serial_number_required']
                    : (isset($line['serial_number_required'])
                        ? (bool) $line['serial_number_required']
                        : (isset($line['options']['captured_serial_number_required'])
                            ? (bool) $line['options']['captured_serial_number_required']
                            : (isset($line['options']['serial_number_required']) ? (bool) $line['options']['serial_number_required'] : null)))
            );

            foreach ($lineWarnings as $w) {
                $warnings[] = $w;
            }
        }

        return BundleLifecycleEvaluationResult::warning($warnings, ['setting_id' => $settingId]);
    }

    /**
     * Evaluate captured Sales details / bundle items against live bundle definitions.
     *
     * @param Collection|iterable $saleDetails
     * @param int $settingId
     * @param string|null $businessDate
     * @return BundleLifecycleEvaluationResult
     */
    public function evaluateSalesSnapshot(
        iterable $saleDetails,
        int $settingId,
        ?string $businessDate = null
    ): BundleLifecycleEvaluationResult {
        $warnings = [];

        foreach ($saleDetails as $detail) {
            // detail can be SaleDetails model or array/CartItem
            $bundleItems = [];
            $bundleId = null;
            $parentProductId = null;
            $lineLabel = '';

            if (is_array($detail)) {
                $bundleItems = $detail['bundle_items'] ?? ($detail['options']['bundle_items'] ?? []);
                $parentProductId = $detail['product_id'] ?? ($detail['options']['product_id'] ?? 0);
                $lineLabel = $detail['product_name'] ?? ($detail['name'] ?? 'Baris Paket');
                if (!empty($bundleItems)) {
                    $first = is_array($bundleItems[0]) ? $bundleItems[0] : (array) $bundleItems[0];
                    $bundleId = $first['bundle_id'] ?? null;
                }
                $bundleId = $bundleId ?? ($detail['bundle_id'] ?? ($detail['options']['bundle_id'] ?? null));
            } elseif (is_object($detail)) {
                if (method_exists($detail, 'relationLoaded') && $detail->relationLoaded('bundleItems')) {
                    $bundleItems = $detail->bundleItems->toArray();
                } elseif (isset($detail->bundleItems) && $detail->bundleItems instanceof Collection) {
                    $bundleItems = $detail->bundleItems->toArray();
                } elseif (isset($detail->options->bundle_items)) {
                    $bundleItems = (array) $detail->options->bundle_items;
                }

                $parentProductId = $detail->product_id ?? ($detail->options->product_id ?? ($detail->id ?? 0));
                $lineLabel = $detail->product_name ?? ($detail->name ?? 'Baris Paket');
                if (!empty($bundleItems)) {
                    $first = is_array($bundleItems[0]) ? $bundleItems[0] : (array) $bundleItems[0];
                    $bundleId = $first['bundle_id'] ?? null;
                }
                $bundleId = $bundleId ?? ($detail->options->bundle_id ?? ($detail->bundle_id ?? null));
            }

            if (!$bundleId && empty($bundleItems)) {
                continue;
            }

            if ($bundleId) {
                $lineWarnings = $this->evaluateCapturedBundleLine(
                    bundleId: (int) $bundleId,
                    parentProductId: (int) $parentProductId,
                    settingId: $settingId,
                    lineLabel: (string) $lineLabel,
                    capturedComponents: $bundleItems,
                    businessDate: $businessDate
                );

                foreach ($lineWarnings as $w) {
                    $warnings[] = $w;
                }
            }
        }

        return BundleLifecycleEvaluationResult::warning($warnings, ['setting_id' => $settingId]);
    }

    /**
     * Core helper to compare captured bundle line with live setting-scoped definition.
     */
    public function evaluateCapturedBundleLine(
        int $bundleId,
        int $parentProductId,
        int $settingId,
        string $lineLabel,
        array $capturedComponents = [],
        ?string $businessDate = null,
        ?bool $capturedParentStockManaged = null,
        ?bool $capturedParentSerialRequired = null
    ): array {
        $warnings = [];
        $today = $businessDate ?? self::currentBusinessDate();

        $bundleModel = ProductBundle::with('items.product')
            ->where('id', $bundleId)
            ->first();

        // 1. Definition deleted / missing
        if (! $bundleModel) {
            $warnings[] = [
                'type' => 'bundle',
                'bundle_id' => $bundleId,
                'line_label' => $lineLabel,
                'reason' => BundleLifecycleReason::DEFINITION_DELETED,
                'message' => "Definisi paket '{$lineLabel}' sudah tidak ditemukan di sistem",
            ];

            // Bulk load products for captured components to avoid N+1 queries
            $capturedPids = [];
            foreach ($capturedComponents as $comp) {
                $compArray = is_array($comp) ? $comp : (array) $comp;
                $compPid = (int) ($compArray['product_id'] ?? 0);
                if ($compPid > 0) {
                    $capturedPids[] = $compPid;
                }
            }

            $liveProducts = !empty($capturedPids)
                ? Product::whereIn('id', array_unique($capturedPids))->get()->keyBy('id')
                : collect();

            foreach ($capturedComponents as $comp) {
                $compArray = is_array($comp) ? $comp : (array) $comp;
                $compPid = (int) ($compArray['product_id'] ?? 0);
                $compName = $compArray['name'] ?? ($compArray['product_name'] ?? "Produk #{$compPid}");
                if ($compPid > 0) {
                    $liveCompProduct = $liveProducts->get($compPid);
                    if (! $liveCompProduct) {
                        $warnings[] = [
                            'type' => 'component',
                            'bundle_id' => $bundleId,
                            'product_id' => $compPid,
                            'product_name' => $compName,
                            'reason' => BundleLifecycleReason::MISSING_COMPONENT,
                            'message' => "Komponen '{$compName}' sudah tidak ditemukan di katalog produk",
                        ];
                    } elseif ($liveCompProduct->merged_into_id !== null) {
                        $warnings[] = [
                            'type' => 'component',
                            'bundle_id' => $bundleId,
                            'product_id' => $compPid,
                            'product_name' => $compName,
                            'reason' => BundleLifecycleReason::INACTIVE_COMPONENT,
                            'message' => "Komponen '{$compName}' telah digabungkan/tidak aktif",
                        ];
                    }
                }
            }
            return $warnings;
        }

        // Setting mismatch
        if ((int) $bundleModel->setting_id !== (int) $settingId) {
            $warnings[] = [
                'type' => 'bundle',
                'bundle_id' => $bundleId,
                'bundle_name' => $bundleModel->name,
                'line_label' => $lineLabel,
                'reason' => BundleLifecycleReason::SETTING_MISMATCH,
                'message' => "Paket '{$bundleModel->name}' tidak terdaftar pada unit bisnis saat ini",
            ];
        }

        // Disabled
        if (! (bool) $bundleModel->is_active) {
            $warnings[] = [
                'type' => 'bundle',
                'bundle_id' => $bundleId,
                'bundle_name' => $bundleModel->name,
                'line_label' => $lineLabel,
                'reason' => BundleLifecycleReason::DISABLED,
                'message' => "Paket '{$bundleModel->name}' saat ini berstatus non-aktif",
            ];
        }

        // Dates
        if ($bundleModel->active_from !== null) {
            $from = is_string($bundleModel->active_from) ? substr($bundleModel->active_from, 0, 10) : $bundleModel->active_from->toDateString();
            if ($today < $from) {
                $warnings[] = [
                    'type' => 'bundle',
                    'bundle_id' => $bundleId,
                    'bundle_name' => $bundleModel->name,
                    'line_label' => $lineLabel,
                    'reason' => BundleLifecycleReason::NOT_STARTED,
                    'message' => "Periode aktif paket '{$bundleModel->name}' belum dimulai (mulai: {$from})",
                ];
            }
        }

        if ($bundleModel->active_to !== null) {
            $to = is_string($bundleModel->active_to) ? substr($bundleModel->active_to, 0, 10) : $bundleModel->active_to->toDateString();
            if ($today > $to) {
                $warnings[] = [
                    'type' => 'bundle',
                    'bundle_id' => $bundleId,
                    'bundle_name' => $bundleModel->name,
                    'line_label' => $lineLabel,
                    'reason' => BundleLifecycleReason::EXPIRED,
                    'message' => "Masa berlaku paket '{$bundleModel->name}' telah berakhir pada {$to}",
                ];
            }
        }

        // Composition comparison
        $liveItems = $bundleModel->items;
        if ($liveItems->isEmpty()) {
            $warnings[] = [
                'type' => 'bundle',
                'bundle_id' => $bundleId,
                'bundle_name' => $bundleModel->name,
                'line_label' => $lineLabel,
                'reason' => BundleLifecycleReason::EMPTY_COMPOSITION,
                'message' => "Komposisi paket '{$bundleModel->name}' saat ini kosong",
            ];
        }

        $liveItemsByProductId = $liveItems->keyBy('product_id');
        $liveProductIds = $liveItemsByProductId->keys()->all();

        // Check parent product operational classification drift if parentProductId is valid
        if ($parentProductId > 0) {
            $liveParentProduct = Product::find($parentProductId);
            if ($liveParentProduct) {
                if (isset($capturedParentStockManaged)) {
                    $capturedStockManaged = (bool) $capturedParentStockManaged;
                    $currentStockManaged = (bool) $liveParentProduct->stock_managed;
                    if ($capturedStockManaged !== $currentStockManaged) {
                        $warnings[] = [
                            'type' => 'bundle_parent',
                            'bundle_id' => $bundleId,
                            'product_id' => $parentProductId,
                            'product_name' => $liveParentProduct->product_name ?? $lineLabel,
                            'reason' => BundleLifecycleReason::STOCK_MANAGED_CHANGED,
                            'message' => "Status pengelolaan stok produk utama '{$lineLabel}' telah berubah",
                            'captured_value' => $capturedStockManaged,
                            'current_value' => $currentStockManaged,
                        ];
                    }
                }

                if (isset($capturedParentSerialRequired)) {
                    $capturedSerialRequired = (bool) $capturedParentSerialRequired;
                    $currentSerialRequired = (bool) $liveParentProduct->serial_number_required;
                    if ($capturedSerialRequired !== $currentSerialRequired) {
                        $warnings[] = [
                            'type' => 'bundle_parent',
                            'bundle_id' => $bundleId,
                            'product_id' => $parentProductId,
                            'product_name' => $liveParentProduct->product_name ?? $lineLabel,
                            'reason' => BundleLifecycleReason::SERIAL_REQUIRED_CHANGED,
                            'message' => "Status kewajiban nomor seri produk utama '{$lineLabel}' telah berubah",
                            'captured_value' => $capturedSerialRequired,
                            'current_value' => $currentSerialRequired,
                        ];
                    }
                }
            }
        }

        // Collect captured product IDs
        $capturedProductIds = [];
        $capturedMissingLivePids = [];
        foreach ($capturedComponents as $comp) {
            $compArray = is_array($comp) ? $comp : (array) $comp;
            $compPid = (int) ($compArray['product_id'] ?? 0);
            if ($compPid > 0) {
                $capturedProductIds[] = $compPid;
                if (! $liveItemsByProductId->has($compPid)) {
                    $capturedMissingLivePids[] = $compPid;
                }
            }
        }

        // Bulk load products that are in capturedComponents but NOT in liveItems->product
        $extraProducts = !empty($capturedMissingLivePids)
            ? Product::whereIn('id', array_unique($capturedMissingLivePids))->get()->keyBy('id')
            : collect();

        // 1. Check captured components against live definition
        foreach ($capturedComponents as $comp) {
            $compArray = is_array($comp) ? $comp : (array) $comp;
            $compPid = (int) ($compArray['product_id'] ?? 0);
            $compName = $compArray['name'] ?? ($compArray['product_name'] ?? "Produk #{$compPid}");

            if ($compPid <= 0) {
                continue;
            }

            // Was it removed from live composition?
            if (! $liveItemsByProductId->has($compPid)) {
                $warnings[] = [
                    'type' => 'component',
                    'bundle_id' => $bundleId,
                    'product_id' => $compPid,
                    'product_name' => $compName,
                    'reason' => BundleLifecycleReason::COMPONENT_REMOVED,
                    'message' => "Komponen '{$compName}' telah dihapus dari definisi paket terkini",
                ];
            } else {
                $liveItem = $liveItemsByProductId->get($compPid);
                $liveQtyPerBundle = (float) ($liveItem->quantity ?? 0);
                $capturedQtyPerBundle = isset($compArray['quantity_per_bundle'])
                    ? (float) $compArray['quantity_per_bundle']
                    : (isset($compArray['quantity']) ? (float) $compArray['quantity'] : null);

                if ($capturedQtyPerBundle !== null && $capturedQtyPerBundle > 0 && abs($capturedQtyPerBundle - $liveQtyPerBundle) > 0.0001) {
                    $warnings[] = [
                        'type' => 'component',
                        'bundle_id' => $bundleId,
                        'product_id' => $compPid,
                        'product_name' => $compName,
                        'reason' => BundleLifecycleReason::QUANTITY_CHANGED,
                        'message' => "Kuantitas komponen '{$compName}' dalam definisi paket telah berubah dari {$capturedQtyPerBundle} menjadi {$liveQtyPerBundle}",
                        'captured_value' => $capturedQtyPerBundle,
                        'current_value' => $liveQtyPerBundle,
                    ];
                }

                // Check informational_item_price drift
                $liveInfoPrice = round((float) ($liveItem->informational_item_price ?? 0), 2);
                $capturedInfoPrice = isset($compArray['informational_item_price']) && is_numeric($compArray['informational_item_price'])
                    ? round((float) $compArray['informational_item_price'], 2)
                    : null;

                if ($capturedInfoPrice !== null && abs($capturedInfoPrice - $liveInfoPrice) > 0.0001) {
                    $warnings[] = [
                        'type' => 'component',
                        'bundle_id' => $bundleId,
                        'product_id' => $compPid,
                        'product_name' => $compName,
                        'reason' => BundleLifecycleReason::INFORMATIONAL_ALLOCATION_CHANGED,
                        'message' => "Alokasi nilai informasi komponen '{$compName}' telah berubah dari Rp " . number_format($capturedInfoPrice, 0, ',', '.') . " menjadi Rp " . number_format($liveInfoPrice, 0, ',', '.'),
                        'captured_value' => $capturedInfoPrice,
                        'current_value' => $liveInfoPrice,
                    ];
                }
            }

            // Get product from live items eager-load or bulk-loaded extra
            $liveProduct = $liveItemsByProductId->has($compPid)
                ? $liveItemsByProductId->get($compPid)->product
                : $extraProducts->get($compPid);

            if (! $liveProduct) {
                $warnings[] = [
                    'type' => 'component',
                    'bundle_id' => $bundleId,
                    'product_id' => $compPid,
                    'product_name' => $compName,
                    'reason' => BundleLifecycleReason::MISSING_COMPONENT,
                    'message' => "Komponen '{$compName}' sudah tidak ditemukan di katalog produk",
                ];
            } elseif ($liveProduct->merged_into_id !== null) {
                $warnings[] = [
                    'type' => 'component',
                    'bundle_id' => $bundleId,
                    'product_id' => $compPid,
                    'product_name' => $compName,
                    'reason' => BundleLifecycleReason::INACTIVE_COMPONENT,
                    'message' => "Komponen '{$compName}' telah digabungkan/tidak aktif",
                ];
            } else {
                // Check stock_managed and serial_number_required classification drift
                if (isset($compArray['stock_managed'])) {
                    $capturedStockManaged = (bool) $compArray['stock_managed'];
                    $currentStockManaged = (bool) $liveProduct->stock_managed;
                    if ($capturedStockManaged !== $currentStockManaged) {
                        $warnings[] = [
                            'type' => 'component',
                            'bundle_id' => $bundleId,
                            'product_id' => $compPid,
                            'product_name' => $compName,
                            'reason' => BundleLifecycleReason::STOCK_MANAGED_CHANGED,
                            'message' => "Status pengelolaan stok komponen '{$compName}' telah berubah",
                            'captured_value' => $capturedStockManaged,
                            'current_value' => $currentStockManaged,
                        ];
                    }
                }

                if (isset($compArray['serial_number_required'])) {
                    $capturedSerialRequired = (bool) $compArray['serial_number_required'];
                    $currentSerialRequired = (bool) $liveProduct->serial_number_required;
                    if ($capturedSerialRequired !== $currentSerialRequired) {
                        $warnings[] = [
                            'type' => 'component',
                            'bundle_id' => $bundleId,
                            'product_id' => $compPid,
                            'product_name' => $compName,
                            'reason' => BundleLifecycleReason::SERIAL_REQUIRED_CHANGED,
                            'message' => "Status kewajiban nomor seri komponen '{$compName}' telah berubah",
                            'captured_value' => $capturedSerialRequired,
                            'current_value' => $currentSerialRequired,
                        ];
                    }
                }
            }
        }

        // 2. Check if a live component was added after capture
        foreach ($liveItems as $liveItem) {
            if (! in_array($liveItem->product_id, $capturedProductIds, true)) {
                $addedCompName = $liveItem->product?->product_name ?? "Produk #{$liveItem->product_id}";
                $warnings[] = [
                    'type' => 'component',
                    'bundle_id' => $bundleId,
                    'product_id' => $liveItem->product_id,
                    'product_name' => $addedCompName,
                    'reason' => BundleLifecycleReason::COMPONENT_ADDED,
                    'message' => "Komponen baru '{$addedCompName}' telah ditambahkan ke definisi paket terkini",
                ];
            }
        }

        return $warnings;
    }
}
