<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\BarcodeIdentity;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\Transaction;
use Modules\Product\Utils\BarcodeUtils;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Purchase\Entities\UomNormalizationLine;
use Modules\Purchase\Support\PurchaseCostHelper;
use Modules\Setting\Entities\Unit;

/**
 * Domain service for validating UOM normalization eligibility,
 * resolving transactions, and generating preview data.
 */
class UomNormalizationEligibilityService
{
    public function __construct(
        private LegacyTransactionResolver $transactionResolver,
    ) {
    }

    /**
     * Validate the basic batch selection:
     * - Product must be stock-managed and non-serial
     * - Conversion must be a direct conversion to the product's base UOM
     * - All selected purchase details must belong to the same product
     *
     * @return array{valid: bool, errors: array}
     */
    public function validateBatchSelection(
        Product $product,
        Unit $targetUnit,
        float $factor,
        Collection $purchaseDetailIds,
        int $settingId
    ): array {
        $errors = [];

        // Product must be stock-managed
        if (!$product->stock_managed) {
            $errors[] = 'Produk tidak dikelola stoknya (stock_managed = false).';
        }

        // Product must not be serial-tracked
        if ($product->stock_managed && $product->serial_number_required) {
            $errors[] = 'Produk serial-tracked tidak dapat dinormalisasi.';
        }

        // Target unit must be different from current base unit
        if ($targetUnit->id === $product->base_unit_id) {
            $errors[] = 'Satuan target harus berbeda dari satuan dasar produk saat ini.';
        }

        // Conversion factor must be positive
        if ($factor <= 0) {
            $errors[] = 'Faktor konversi harus positif.';
        }

        // All purchase details must be for this product and setting
        $purchaseDetails = PurchaseDetail::whereIn('id', $purchaseDetailIds)
            ->with('purchase')
            ->get();

        foreach ($purchaseDetails as $pd) {
            if ($pd->product_id !== $product->id) {
                $errors[] = "Detail pembelian #{$pd->id} bukan untuk produk ini.";
            }
            if ($pd->purchase && $pd->purchase->setting_id !== $settingId) {
                $errors[] = "Detail pembelian #{$pd->id} bukan dari cabang (setting) aktif.";
            }
        }

        if ($purchaseDetails->count() !== $purchaseDetailIds->count()) {
            $errors[] = 'Beberapa detail pembelian tidak ditemukan atau duplikat.';
        }

        // Classify each other-setting footprint: a bare ProductPrice row is
        // not a blocker (it is safely rebased atomically during execution),
        // but any physical/history footprint (stock, purchase/receipt
        // history, or inventory transactions) in another setting blocks
        // execution, since those facts would otherwise retain old-base
        // quantities under a global product UOM change.
        $footprintResult = $this->classifyOtherSettingFootprints($product, $settingId);
        if (!empty($footprintResult['blocking_settings'])) {
            $settingNames = collect($footprintResult['blocking_settings'])->pluck('setting_name')->join(', ');
            $errors[] = "Produk memiliki riwayat fisik (stok/pembelian/transaksi) di cabang (setting) lain: {$settingNames}. Normalisasi base UOM tidak diizinkan secara sepihak dari cabang ini.";
        }

        // Require all non-voided purchase details for this product in this setting to be selected
        $allActiveProductPurchaseDetails = PurchaseDetail::where('product_id', $product->id)
            ->whereHas('purchase', function ($query) use ($settingId) {
                $query->where('setting_id', $settingId)
                      ->whereNotIn('status', ['VOID', 'CANCELLED']);
            })
            ->pluck('id');

        $missingSelection = $allActiveProductPurchaseDetails->diff($purchaseDetailIds);
        if ($missingSelection->isNotEmpty()) {
            $errors[] = 'Semua baris pembelian produk ini (yang tidak dibatalkan) di cabang aktif harus dipilih untuk normalisasi (terdapat ' . $missingSelection->count() . ' baris yang belum dipilih).';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Check receipt completion status for selected purchase details.
     * Returns whether all selected lines are fully received.
     *
     * @return array{all_complete: bool, incomplete_lines: array, line_details: array}
     */
    public function checkReceiptCompletion(Collection $purchaseDetailIds): array
    {
        $purchaseDetails = PurchaseDetail::whereIn('id', $purchaseDetailIds)
            ->with(['receivedNoteDetails.receivedNote'])
            ->get();

        $incompleteLines = [];
        $lineDetails = [];

        foreach ($purchaseDetails as $pd) {
            $approvedReceived = $pd->receivedNoteDetails
                ->filter(fn ($rnd) => $rnd->receivedNote && $rnd->receivedNote->status === ReceivedNote::STATUS_APPROVED)
                ->sum('quantity_received');

            $isComplete = $approvedReceived >= (float) $pd->quantity;

            $lineDetails[$pd->id] = [
                'purchase_detail_id' => $pd->id,
                'ordered_quantity' => (float) $pd->quantity,
                'received_quantity' => $approvedReceived,
                'is_complete' => $isComplete,
                'product_name' => $pd->product_name,
            ];

            if (!$isComplete) {
                $incompleteLines[] = $pd->id;
            }
        }

        return [
            'all_complete' => empty($incompleteLines),
            'incomplete_lines' => $incompleteLines,
            'line_details' => $lineDetails,
        ];
    }

    /**
     * Check that no selected receiving detail has been previously normalized.
     *
     * @return array{clean: bool, normalized_details: array}
     */
    public function checkNoPriorNormalization(Collection $receivedNoteDetailIds): array
    {
        $alreadyNormalized = UomNormalizationLine::whereIn('received_note_detail_id', $receivedNoteDetailIds)
            ->pluck('received_note_detail_id')
            ->toArray();

        return [
            'clean' => empty($alreadyNormalized),
            'normalized_details' => $alreadyNormalized,
        ];
    }

    public function checkStockHistory(Product $product, int $settingId, Collection $receivedNoteDetails = null): array
    {
        $blockers = [];

        // Check for non-zero broken quantities (conservative policy: any non-zero
        // broken/damaged quantity anywhere blocks base-UOM correction; no broken
        // stock is ever rebased by this feature). There is no product-level
        // aggregate broken_quantity column (products.broken_quantity was removed);
        // broken quantity lives solely on ProductStock per location, so scanning
        // every ProductStock row for this product (unscoped by setting/location)
        // is the complete check — no location can hide broken stock from this scan.
        $productStocks = ProductStock::where('product_id', $product->id)->with('location')->get();
        $brokenStockLocations = [];
        foreach ($productStocks as $stock) {
            if ((float) $stock->broken_quantity !== 0.0) {
                $brokenStockLocations[] = [
                    'location_id' => $stock->location_id,
                    'location_name' => optional($stock->location)->name ?? "Lokasi #{$stock->location_id}",
                    'broken_quantity' => (float) $stock->broken_quantity,
                    'broken_quantity_tax' => (float) $stock->broken_quantity_tax,
                    'broken_quantity_non_tax' => (float) $stock->broken_quantity_non_tax,
                ];
            }
        }
        if (!empty($brokenStockLocations)) {
            $locNames = collect($brokenStockLocations)->pluck('location_name')->join(', ');
            $blockers[] = [
                'type' => 'broken_stock_exists',
                'message' => "Produk memiliki stok rusak di {$locNames} yang harus diselesaikan sebelum normalisasi. Ini mencegah kesalahan konversi stok rusak yang tidak dapat dilacak.",
                'details' => $brokenStockLocations,
            ];
        }

        $stockAffectingTypes = ['SELL', 'ADJ', 'TRF', 'RET', 'BRK', 'RPL', 'IMP', 'INIT'];
        $unexplainedTransactions = Transaction::query()
            ->where('product_id', $product->id)
            ->where('setting_id', $settingId)
            ->whereIn('type', $stockAffectingTypes)
            ->get();

        if ($unexplainedTransactions->count() > 0) {
            $txIds = $unexplainedTransactions->pluck('id')->implode(', ');
            $blockers[] = [
                'type' => 'unexplained_transaction',
                'count' => $unexplainedTransactions->count(),
                'message' => "Terdapat {$unexplainedTransactions->count()} transaksi inventori yang menghalangi (ID: {$txIds}). Hanya produk dengan sumber dari Pembelian (BUY) yang dapat dinormalisasi.",
            ];
        }

        $bundleParentIds = \Modules\Product\Entities\ProductBundleItem::where('product_id', $product->id)
            ->join('product_bundles', 'product_bundles.id', '=', 'product_bundle_items.bundle_id')
            ->pluck('product_bundles.parent_product_id');

        $posTransactionsCount = \Modules\Pos\Entities\PosTransaction::query()
            ->where('setting_id', $settingId)
            ->where('status', \Modules\Pos\Entities\PosTransaction::STATUS_COMPLETED)
            ->whereHas('lines', function($query) use ($product, $bundleParentIds) {
                $query->whereIn('product_id', collect([$product->id])->merge($bundleParentIds));
            })
            ->count();

        if ($posTransactionsCount > 0) {
            $blockers[] = [
                'type' => 'pos_transaction',
                'count' => $posTransactionsCount,
                'message' => "Terdapat {$posTransactionsCount} transaksi POS yang menghalangi.",
            ];
        }

        // Exact stock lineage check
        if ($receivedNoteDetails) {
            // Compute expected total from selected receipts per location and bucket
            $expectedLocationStock = [];
            $expectedGlobalStock = 0;

            foreach ($receivedNoteDetails as $rnd) {
                $locId = $rnd->receivedNote->location_id;
                if (!isset($expectedLocationStock[$locId])) {
                    $expectedLocationStock[$locId] = [
                        'quantity' => 0,
                        'quantity_tax' => 0,
                        'quantity_non_tax' => 0,
                        'name' => $rnd->receivedNote->location->name ?? 'Unknown',
                    ];
                }

                $qty = (float) $rnd->quantity_received;
                $pd = $rnd->purchaseDetail;

                $expectedLocationStock[$locId]['quantity'] += $qty;
                if ($pd && $pd->tax_id) {
                    $expectedLocationStock[$locId]['quantity_tax'] += $qty;
                } else {
                    $expectedLocationStock[$locId]['quantity_non_tax'] += $qty;
                }
                $expectedGlobalStock += $qty;
            }

            // Fetch actual stocks
            $actualStocks = ProductStock::where('product_id', $product->id)
                ->with('location')
                ->get()
                ->keyBy('location_id');

            foreach ($actualStocks as $locId => $stock) {
                $actualQty = (float) $stock->quantity;
                $actualTax = (float) $stock->quantity_tax;
                $actualNonTax = (float) $stock->quantity_non_tax;
                $locName = $stock->location->name ?? "Lokasi #{$locId}";

                if (!isset($expectedLocationStock[$locId])) {
                    if ($actualQty != 0) {
                        $blockers[] = [
                            'type' => 'unexplained_stock',
                            'message' => "Stok di $locName tidak dapat dijelaskan oleh riwayat penerimaan pembelian yang dipilih.",
                        ];
                    }
                    continue;
                }

                $expected = $expectedLocationStock[$locId];
                if (abs($actualQty - $expected['quantity']) > 0.001 ||
                    abs($actualTax - $expected['quantity_tax']) > 0.001 ||
                    abs($actualNonTax - $expected['quantity_non_tax']) > 0.001) {

                    $blockers[] = [
                        'type' => 'unexplained_stock',
                        'message' => "Stok di {$expected['name']} tidak sama dengan riwayat penerimaan pembelian yang dipilih (tersisa dari sumber yang belum dipilih atau mutasi lain).",
                    ];
                }
                unset($expectedLocationStock[$locId]);
            }

            // Check if there are expected locations that have NO stock record, but have expected quantity
            foreach ($expectedLocationStock as $locId => $expected) {
                if ($expected['quantity'] > 0) {
                    $blockers[] = [
                        'type' => 'unexplained_stock',
                        'message' => "Stok di {$expected['name']} tidak ditemukan meskipun ada riwayat penerimaan.",
                    ];
                }
            }

            $actualGlobalStock = (float) $product->product_quantity;
            if (abs($actualGlobalStock - $expectedGlobalStock) > 0.001) {
                $blockers[] = [
                    'type' => 'unexplained_global_stock',
                    'message' => "Stok global produk tidak sama dengan total riwayat penerimaan pembelian yang dipilih.",
                ];
            }
        }

        return [
            'eligible' => empty($blockers),
            'blockers' => $blockers,
        ];
    }

    /**
     * Classify every OTHER setting's footprint for this product into
     * price-only (safe — a ProductPrice row with no stock, no
     * purchase/receipt history, and no inventory transaction) or
     * physical/history (blocking — any stock, purchase/receipt, or
     * transaction presence in that setting).
     *
     * @return array{
     *     price_only_settings: array<int, array{setting_id: int, setting_name: string}>,
     *     blocking_settings: array<int, array{setting_id: int, setting_name: string, footprint_type: string}>,
     * }
     */
    public function classifyOtherSettingFootprints(Product $product, int $activeSettingId): array
    {
        $priceOnlySettings = [];
        $blockingSettings = [];

        // Every OTHER setting where any footprint at all exists: a
        // ProductPrice row, a Transaction, a PurchaseDetail whose Purchase
        // belongs to that setting, or ProductStock at a Location belonging
        // to that setting.
        $priceSettingIds = \Modules\Product\Entities\ProductPrice::where('product_id', $product->id)
            ->where('setting_id', '!=', $activeSettingId)
            ->pluck('setting_id');

        $transactionSettingIds = Transaction::where('product_id', $product->id)
            ->where('setting_id', '!=', $activeSettingId)
            ->pluck('setting_id');

        $purchaseSettingIds = PurchaseDetail::where('product_id', $product->id)
            ->whereHas('purchase', fn ($q) => $q->where('setting_id', '!=', $activeSettingId))
            ->with('purchase')
            ->get()
            ->pluck('purchase.setting_id');

        $stockLocationSettingIds = ProductStock::where('product_id', $product->id)
            ->where(function ($q) use ($product) {
                // Any non-zero bucket, or any row at all, counts as a
                // physical footprint — even a zero-quantity row at a
                // location in another setting proves stock was tracked
                // there and must not be silently ignored.
                $q->whereNotNull('id');
            })
            ->with('location')
            ->get()
            ->pluck('location.setting_id')
            ->filter(fn ($id) => $id !== null && $id !== $activeSettingId);

        $allOtherSettingIds = $priceSettingIds
            ->merge($transactionSettingIds)
            ->merge($purchaseSettingIds)
            ->merge($stockLocationSettingIds)
            ->filter()
            ->unique()
            ->values();

        foreach ($allOtherSettingIds as $otherSettingId) {
            $setting = \Modules\Setting\Entities\Setting::find($otherSettingId);
            $settingName = optional($setting)->company_name ?? "Setting #{$otherSettingId}";

            $hasTransaction = $transactionSettingIds->contains($otherSettingId);
            $hasPurchaseHistory = $purchaseSettingIds->contains($otherSettingId);
            $hasStock = $stockLocationSettingIds->contains($otherSettingId);

            if ($hasTransaction || $hasPurchaseHistory || $hasStock) {
                $footprintTypes = array_filter([
                    $hasStock ? 'stock' : null,
                    $hasPurchaseHistory ? 'purchase_history' : null,
                    $hasTransaction ? 'transaction' : null,
                ]);

                $blockingSettings[] = [
                    'setting_id' => $otherSettingId,
                    'setting_name' => $settingName,
                    'footprint_type' => implode('+', $footprintTypes),
                ];
            } else {
                // Only a ProductPrice row (or nothing beyond that) exists.
                $priceOnlySettings[] = [
                    'setting_id' => $otherSettingId,
                    'setting_name' => $settingName,
                ];
            }
        }

        return [
            'price_only_settings' => $priceOnlySettings,
            'blocking_settings' => $blockingSettings,
        ];
    }

    /**
     * Validate barcode ownership can be safely migrated/preserved for a
     * base-UOM correction. Conservative policy: if ownership, meaning,
     * uniqueness, or collision cannot be positively proven via the
     * authoritative barcode_identities registry, block before mutation
     * rather than guess.
     *
     * @return array{eligible: bool, blockers: array}
     */
    public function checkBarcodeIntegrity(Product $product, Unit $targetUnit): array
    {
        $blockers = [];

        $existingConversions = ProductUnitConversion::where('product_id', $product->id)->get();

        if (!empty($product->barcode)) {
            $productIdentity = BarcodeIdentity::where('product_id', $product->id)->first();

            if (!$productIdentity) {
                // products.barcode is set but there is no authoritative
                // BarcodeIdentity registry row for it (unbackfilled legacy
                // data). Ownership cannot be proven — block.
                $blockers[] = [
                    'type' => 'barcode_ambiguous',
                    'message' => "Produk memiliki barcode ({$product->barcode}) tanpa entri registry BarcodeIdentity yang sah. Kepemilikan barcode tidak dapat dibuktikan; migrasi barcode dibatalkan demi keamanan.",
                ];
            } else {
                $canonicalKey = BarcodeUtils::canonicalize($product->barcode);
                $colliding = BarcodeIdentity::where('canonical_key', $canonicalKey)
                    ->where('id', '!=', $productIdentity->id)
                    ->first();

                if ($colliding) {
                    $blockers[] = [
                        'type' => 'barcode_collision',
                        'message' => "Barcode produk ({$product->barcode}) akan bertabrakan dengan identitas barcode lain yang sudah ada. Migrasi barcode dibatalkan demi keamanan.",
                    ];
                }

                // The migration target is the legacy inline `barcode` column on
                // the (to-be-created) former-base ProductUnitConversion row,
                // which carries its own DB-level unique constraint. Check
                // proactively against any OTHER conversion already using this
                // value, so we can reject before mutation rather than let the
                // DB throw mid-transaction.
                $collidingConversion = ProductUnitConversion::where('barcode', $product->barcode)
                    ->where('product_id', '!=', $product->id)
                    ->first();

                if ($collidingConversion) {
                    $blockers[] = [
                        'type' => 'barcode_collision',
                        'message' => "Barcode produk ({$product->barcode}) akan bertabrakan dengan barcode konversi unit lain yang sudah ada (produk #{$collidingConversion->product_id}). Migrasi barcode dibatalkan demi keamanan.",
                    ];
                }
            }
        }

        // Legacy inline ProductUnitConversion.barcode values that diverge from
        // the authoritative registry are an ambiguous state — block.
        foreach ($existingConversions as $conv) {
            if (empty($conv->barcode)) {
                continue;
            }

            $registryEntry = BarcodeIdentity::where('product_unit_conversion_id', $conv->id)->first();
            $canonicalInline = BarcodeUtils::canonicalize($conv->barcode);

            if (!$registryEntry || $registryEntry->canonical_key !== $canonicalInline) {
                $blockers[] = [
                    'type' => 'barcode_ambiguous',
                    'message' => "Konversi unit #{$conv->id} memiliki barcode legacy ({$conv->barcode}) yang tidak sesuai dengan registry BarcodeIdentity. Migrasi barcode dibatalkan demi keamanan.",
                ];
            }
        }

        return [
            'eligible' => empty($blockers),
            'blockers' => $blockers,
        ];
    }

    public function validateAll(
        Product $product,
        Unit $targetUnit,
        float $factor,
        Collection $purchaseDetailIds,
        int $settingId,
        Collection $receivedNoteDetails = null
    ): array {
        if ($receivedNoteDetails === null) {
            $purchaseDetails = PurchaseDetail::whereIn('id', $purchaseDetailIds)
                ->with(['receivedNoteDetails.receivedNote', 'purchase', 'tax'])
                ->get();
            $receivedNoteDetails = collect();
            foreach ($purchaseDetails as $pd) {
                $approved = $pd->receivedNoteDetails
                    ->filter(fn ($rnd) => $rnd->receivedNote && $rnd->receivedNote->status === ReceivedNote::STATUS_APPROVED);
                $receivedNoteDetails = $receivedNoteDetails->merge($approved);
            }
        }

        $selectionResult = $this->validateBatchSelection($product, $targetUnit, $factor, $purchaseDetailIds, $settingId);
        $completionResult = $this->checkReceiptCompletion($purchaseDetailIds);
        $normResult = $this->checkNoPriorNormalization($receivedNoteDetails->pluck('id'));
        $historyResult = $this->checkStockHistory($product, $settingId, $receivedNoteDetails);
        $transactionResult = $this->transactionResolver->resolveAll($receivedNoteDetails);

        $conversionConflicts = [];
        $existingConversions = ProductUnitConversion::where('product_id', $product->id)->get();
        if ($existingConversions->where('unit_id', $product->base_unit_id)->count() > 0) {
            $conversionConflicts[] = "Produk sudah memiliki konversi untuk unit dasar lama (" . optional($product->baseUnit)->name . "). Harap hapus terlebih dahulu.";
        }
        foreach ($existingConversions as $conv) {
            if ($conv->unit_id === $targetUnit->id) {
                $conversionConflicts[] = "Produk sudah memiliki konversi untuk unit target (" . $targetUnit->name . "). Ini akan bertentangan dengan perubahan unit dasar.";
            }
        }

        $barcodeResult = $this->checkBarcodeIntegrity($product, $targetUnit);

        $canExecute = $selectionResult['valid']
            && $completionResult['all_complete']
            && $normResult['clean']
            && $historyResult['eligible']
            && $transactionResult['all_matched']
            && empty($conversionConflicts)
            && $barcodeResult['eligible'];

        $errors = array_merge(
            $selectionResult['errors'],
            $completionResult['all_complete'] ? [] : ['Beberapa baris pembelian belum sepenuhnya diterima atau tidak diplih.'],
            $normResult['clean'] ? [] : ['Beberapa detail penerimaan sudah pernah dinormalisasi.'],
            $historyResult['blockers'] ? array_column($historyResult['blockers'], 'message') : [],
            $transactionResult['all_matched'] ? [] : ['Beberapa transaksi inventori tidak dapat dicocokkan dengan unik.'],
            $conversionConflicts,
            $barcodeResult['blockers'] ? array_column($barcodeResult['blockers'], 'message') : []
        );

        return [
            'eligible' => $canExecute,
            'errors' => $errors,
            'selection' => $selectionResult,
            'receipt_completion' => $completionResult,
            'prior_normalization' => $normResult,
            'stock_history' => $historyResult,
            'transaction_matches' => $transactionResult,
            'barcode_integrity' => $barcodeResult,
        ];
    }

    /**
     * Generate a full preview for a normalization batch.
     *
     * @return array Preview data including line details, eligibility, projected values
     */
    public function generatePreview(
        Product $product,
        Unit $targetUnit,
        float $factor,
        Collection $purchaseDetailIds,
        int $settingId,
    ): array {
        // Collect all approved receiving details for selected purchase details
        $purchaseDetails = PurchaseDetail::whereIn('id', $purchaseDetailIds)
            ->with(['receivedNoteDetails.receivedNote.location', 'receivedNoteDetails.purchaseDetail', 'purchase', 'tax'])
            ->get();

        $receivedNoteDetails = collect();
        foreach ($purchaseDetails as $pd) {
            $approved = $pd->receivedNoteDetails
                ->filter(fn ($rnd) => $rnd->receivedNote && $rnd->receivedNote->status === ReceivedNote::STATUS_APPROVED);
            $receivedNoteDetails = $receivedNoteDetails->merge($approved);
        }

        $validationResult = $this->validateAll($product, $targetUnit, $factor, $purchaseDetailIds, $settingId, $receivedNoteDetails);

        // Purchase-unit-price rounding disclosure: computed once per
        // PurchaseDetail (not per receipt), mirroring exactly how
        // UomNormalizationExecutionService::execute() divides unit_price by
        // the factor exactly once — never per receiving detail. The
        // supplier subtotal is never recalculated from this rounded value;
        // it remains the original, preserved monetary fact.
        $pdUnitPriceChanges = [];
        foreach ($purchaseDetails as $pd) {
            $oldUnitPrice = (float) $pd->unit_price;
            $exactNewUnitPrice = $factor > 0 ? $oldUnitPrice / $factor : 0;
            $roundedNewUnitPrice = round($exactNewUnitPrice, 2);
            $roundingEffect = $exactNewUnitPrice - $roundedNewUnitPrice;

            $pdUnitPriceChanges[$pd->id] = [
                'source_unit_price' => $oldUnitPrice,
                'exact_normalized_unit_price' => $exactNewUnitPrice,
                'normalized_unit_price' => $roundedNewUnitPrice,
                'unit_price_rounding_effect' => $roundingEffect,
                'has_rounding_effect' => abs($roundingEffect) > 0.0000001,
            ];
        }

        // Allocate PurchaseDetail-level sub_total/tax across its receiving
        // details, using the SAME shared helper and deterministic ordering
        // (received_note_detail id ascending) as
        // UomNormalizationExecutionService::execute() uses when creating
        // UomNormalizationLine records — so preview and the persisted audit
        // can never disagree, and the allocated amounts always sum exactly
        // to the PurchaseDetail's sub_total/tax.
        $subTotalAllocations = [];
        $taxAllocations = [];
        $purchaseDetailTotals = [];
        $receiptsByPurchaseDetail = $receivedNoteDetails
            ->sortBy('id')
            ->groupBy('po_detail_id');
        foreach ($receiptsByPurchaseDetail as $pdId => $rnds) {
            $pd = $purchaseDetails->firstWhere('id', $pdId);
            if (!$pd) {
                continue;
            }
            $originalPdQty = (float) $pd->quantity;
            $orderedReceiptQuantities = $rnds->map(fn ($rnd) => [
                'key' => $rnd->id,
                'quantity' => (float) $rnd->quantity_received,
            ])->values()->all();

            $subTotalAllocations[$pdId] = PurchaseCostHelper::allocateProportionally(
                (float) ($pd->sub_total ?? 0),
                $originalPdQty,
                $orderedReceiptQuantities,
            );
            $taxAllocations[$pdId] = PurchaseCostHelper::allocateProportionally(
                (float) ($pd->product_tax_amount ?? 0),
                $originalPdQty,
                $orderedReceiptQuantities,
            );

            // Kept separately and clearly named — never presented as a
            // receipt-line subtotal in the UI.
            $purchaseDetailTotals[$pdId] = [
                'purchase_detail_sub_total' => (float) ($pd->sub_total ?? 0),
                'purchase_detail_tax_amount' => (float) ($pd->product_tax_amount ?? 0),
            ];
        }

        // Build line previews
        $linePreview = [];
        $totalSourceQty = 0;
        $totalNormalizedQty = 0;

        $transactionResult = $validationResult['transaction_matches'];

        foreach ($receivedNoteDetails as $rnd) {
            $pd = $purchaseDetails->firstWhere('id', $rnd->po_detail_id);
            $sourceQty = (float) $rnd->quantity_received;
            $normalizedQty = round($sourceQty * $factor, 3);

            $txnResult = $transactionResult['results'][$rnd->id] ?? ['status' => 'missing', 'transaction' => null];

            // Allocated (per-receipt) monetary values — NOT the whole
            // PurchaseDetail total. This is what must be shown as this
            // preview line's subtotal.
            $lineSubTotal = $subTotalAllocations[$pd->id][$rnd->id] ?? 0.0;
            $lineTax = $taxAllocations[$pd->id][$rnd->id] ?? 0.0;
            $lineDpp = $lineSubTotal - $lineTax;
            $normalizedUnitCost = $normalizedQty > 0 ? $lineDpp / $normalizedQty : 0;

            $priceChange = $pdUnitPriceChanges[$pd->id] ?? null;

            $linePreview[] = [
                'received_note_detail_id' => $rnd->id,
                'purchase_detail_id' => $pd->id,
                'purchase_reference' => $pd->purchase->reference ?? '—',
                'location' => $rnd->receivedNote->location->name ?? '—',
                'source_quantity' => $sourceQty,
                'normalized_quantity' => $normalizedQty,
                // Allocated receipt-line subtotal/tax — sums exactly to the
                // PurchaseDetail total across all of its receipt lines.
                'source_sub_total' => round($lineSubTotal, 2),
                'source_tax_amount' => round($lineTax, 2),
                'source_unit_price' => (float) ($pd->unit_price ?? 0),
                'normalized_unit_cost' => round($normalizedUnitCost, 6),
                // Displayed/persisted purchase unit price (rounded to
                // currency precision) and its exact, unrounded counterpart —
                // for UI disclosure only. Never used to recompute the
                // supplier subtotal, which stays authoritative as-is.
                'normalized_unit_price' => $priceChange['normalized_unit_price'] ?? null,
                'exact_normalized_unit_price' => $priceChange['exact_normalized_unit_price'] ?? null,
                'unit_price_rounding_effect' => $priceChange['unit_price_rounding_effect'] ?? null,
                'has_unit_price_rounding_effect' => $priceChange['has_rounding_effect'] ?? false,
                // PurchaseDetail-level total, kept separately and clearly
                // named — a UI must never render this as a receipt-line
                // subtotal.
                'purchase_detail_sub_total' => $purchaseDetailTotals[$pd->id]['purchase_detail_sub_total'] ?? (float) ($pd->sub_total ?? 0),
                'monetary_preserved' => true,
                'transaction_match' => $txnResult['status'],
                'transaction_id' => $txnResult['transaction']?->id,
            ];

            $totalSourceQty += $sourceQty;
            $totalNormalizedQty += $normalizedQty;
        }

        // Calculate projected current HPP
        $currentPrice = $product->priceForSetting($settingId);
        $projectedHpp = null;
        if ($totalNormalizedQty > 0) {
            // Sum all receipt DPP values at normalized quantities
            $totalNormalizedDpp = 0;
            foreach ($linePreview as $lp) {
                $totalNormalizedDpp += $lp['normalized_unit_cost'] * $lp['normalized_quantity'];
            }
            $projectedHpp = round($totalNormalizedDpp / $totalNormalizedQty, 2);
        }

        return [
            'eligible' => $validationResult['eligible'],
            'can_preview' => true,
            'errors' => $validationResult['errors'],
            'receipt_completion' => $validationResult['receipt_completion'],
            'prior_normalization' => $validationResult['prior_normalization'],
            'stock_history' => $validationResult['stock_history'],
            'transaction_matches' => $transactionResult,
            'conversion' => [
                'source_unit' => optional($product->baseUnit)->name,
                'base_unit' => $targetUnit->name,
                'factor' => $factor,
            ],
            'summary' => [
                'total_source_quantity' => $totalSourceQty,
                'total_normalized_quantity' => $totalNormalizedQty,
                'current_average_hpp' => (float) ($currentPrice->average_purchase_price ?? 0),
                'projected_hpp' => $projectedHpp,
                'line_count' => count($linePreview),
            ],
            'lines' => $linePreview,
        ];
    }
}
