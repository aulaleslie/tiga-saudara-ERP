<?php

namespace Modules\Purchase\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Unit;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Purchase\Entities\UomNormalizationBatch;
use Modules\Purchase\Entities\UomNormalizationLine;
use Modules\Purchase\Support\PurchaseCostHelper;

/**
 * Atomic execution service for UOM normalization.
 * Performs all mutations within a single database transaction with row-level locks.
 */
class UomNormalizationExecutionService
{
    public function __construct(
        private UomNormalizationEligibilityService $eligibilityService,
        private LegacyTransactionResolver $transactionResolver,
    ) {
    }

    /**
     * Execute a UOM normalization batch.
     *
     * @return array{success: bool, error?: string, batch?: UomNormalizationBatch}
     */
    public function execute(
        Product $product,
        Unit $targetUnit,
        float $factor,
        Collection $purchaseDetailIds,
        User $actor,
        int $settingId,
        string $reason,
        bool $isAcknowledged = true,
        bool $isSalesPriceWarningAcknowledged = true,
    ): array {
        if (empty(trim($reason))) {
            return ['success' => false, 'error' => 'Alasan normalisasi wajib diisi.'];
        }

        if (!$isAcknowledged || !$isSalesPriceWarningAcknowledged) {
            return ['success' => false, 'error' => 'Semua persetujuan wajib dicentang.'];
        }

        return DB::transaction(function () use ($product, $targetUnit, $factor, $purchaseDetailIds, $actor, $settingId, $reason, $isAcknowledged, $isSalesPriceWarningAcknowledged) {
            // ── Lock the COMPLETE product-wide scope BEFORE any validation ───
            // All rows that eligibility checks read (directly or transitively)
            // must be locked first, so the single authoritative validateAll()
            // call below observes a consistent, concurrency-safe snapshot.
            // Preview (generatePreview) may still use unlocked reads — only
            // this execution path needs the full lock-then-validate ordering.
            $product = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();

            $targetUnit = Unit::where('id', $targetUnit->id)
                ->lockForUpdate()->firstOrFail();

            // Lock EVERY active purchase detail for this product in this setting,
            // not only the submitted IDs, so a concurrent request cannot slip in
            // a new PurchaseDetail between preview and execution.
            $allActivePurchaseDetails = PurchaseDetail::where('product_id', $product->id)
                ->whereHas('purchase', function ($query) use ($settingId) {
                    $query->where('setting_id', $settingId)
                          ->whereNotIn('status', ['VOID', 'CANCELLED']);
                })
                ->lockForUpdate()
                ->with(['receivedNoteDetails.receivedNote.location', 'receivedNoteDetails.purchaseDetail', 'purchase', 'tax'])
                ->get();

            // Rebuild the submitted selection from the now-locked, complete set.
            $purchaseDetails = $allActivePurchaseDetails->whereIn('id', $purchaseDetailIds->all())->values();

            // Lock related Purchase headers and ReceivedNote headers for the
            // complete active scope (not just the submitted lines).
            $purchaseIds = $allActivePurchaseDetails->pluck('purchase_id')->unique();
            \Modules\Purchase\Entities\Purchase::whereIn('id', $purchaseIds)
                ->lockForUpdate()->get();

            // Collect all approved receiving details across the COMPLETE active
            // scope, then lock every one of them (not only those tied to the
            // submitted purchase-detail IDs).
            $allReceivedNoteDetails = collect();
            foreach ($allActivePurchaseDetails as $pd) {
                $approved = $pd->receivedNoteDetails
                    ->filter(fn ($rnd) => $rnd->receivedNote && $rnd->receivedNote->status === ReceivedNote::STATUS_APPROVED);
                $allReceivedNoteDetails = $allReceivedNoteDetails->merge($approved);
            }

            \Modules\Purchase\Entities\ReceivedNote::whereIn(
                'id',
                $allReceivedNoteDetails->pluck('received_note_id')->unique()
            )->lockForUpdate()->get();

            ReceivedNoteDetail::whereIn('id', $allReceivedNoteDetails->pluck('id'))
                ->lockForUpdate()->get();

            // Receiving details for the submitted selection only (used below to
            // drive the actual mutation), re-derived from the locked snapshot.
            $submittedPdIds = $purchaseDetails->pluck('id');
            $receivedNoteDetails = $allReceivedNoteDetails
                ->filter(fn ($rnd) => $submittedPdIds->contains($rnd->po_detail_id))
                ->values();

            // Lock all candidate/relevant Transaction rows up front. These
            // criteria (product_id + setting_id, stock-affecting types, and BUY
            // rows) don't depend on validateAll()'s output, so they can be
            // locked before validation runs.
            $stockAffectingTypes = ['SELL', 'ADJ', 'TRF', 'RET', 'BRK', 'RPL', 'IMP', 'INIT', 'BUY'];
            Transaction::where('product_id', $product->id)
                ->where(function ($q) use ($settingId, $stockAffectingTypes) {
                    $q->where('setting_id', $settingId)
                      ->whereIn('type', $stockAffectingTypes);
                })
                ->orWhere(function ($q) use ($product, $settingId) {
                    // Also lock cross-setting rows for this product, since the
                    // cross-setting-footprint check reads them too.
                    $q->where('product_id', $product->id)
                      ->where('setting_id', '!=', $settingId);
                })
                ->lockForUpdate()->get();

            // Lock existing conversions, barcode identities, and prices.
            $existingConversionsForLock = \Modules\Product\Entities\ProductUnitConversion::where('product_id', $product->id)
                ->lockForUpdate()->get();
            \Modules\Product\Entities\BarcodeIdentity::where('product_id', $product->id)
                ->orWhereIn('product_unit_conversion_id', $existingConversionsForLock->pluck('id'))
                ->lockForUpdate()->get();

            // Lock ALL ProductPrice rows for the product across EVERY
            // setting (not just the active one) — needed both for the
            // cross-setting footprint classification below and for the
            // price-only rebase performed later in this same transaction.
            \Modules\Product\Entities\ProductPrice::where('product_id', $product->id)
                ->lockForUpdate()->get();

            // Lock ALL product stocks across all locations for this product
            // (all settings, not just the active one) — required to classify
            // other-setting stock footprint under lock.
            $productStocks = ProductStock::where('product_id', $product->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('location_id');

            // Lock ALL purchase/receipt history for this product across
            // every OTHER setting too, so cross-setting classification reads
            // a locked, consistent snapshot before any mutation.
            $otherSettingPurchaseDetailIds = PurchaseDetail::where('product_id', $product->id)
                ->whereHas('purchase', fn ($q) => $q->where('setting_id', '!=', $settingId))
                ->lockForUpdate()
                ->pluck('id');
            if ($otherSettingPurchaseDetailIds->isNotEmpty()) {
                \Modules\Purchase\Entities\Purchase::whereIn(
                    'id',
                    PurchaseDetail::whereIn('id', $otherSettingPurchaseDetailIds)->pluck('purchase_id')->unique()
                )->lockForUpdate()->get();
            }

            // ── Classify other-setting footprints under lock, BEFORE any
            // batch/audit row is created and before the single authoritative
            // validateAll() call, so a physical/history footprint rejects
            // before any mutation touches the database.
            $footprints = $this->eligibilityService->classifyOtherSettingFootprints($product, $settingId);
            if (!empty($footprints['blocking_settings'])) {
                $settingNames = collect($footprints['blocking_settings'])->pluck('setting_name')->join(', ');
                throw new \RuntimeException(
                    "Gagal mengeksekusi normalisasi: Produk memiliki riwayat fisik (stok/pembelian/transaksi) di cabang (setting) lain: {$settingNames}. Normalisasi base UOM tidak diizinkan secara sepihak dari cabang ini."
                );
            }
            $priceOnlySettings = $footprints['price_only_settings'];

            // ── Single authoritative revalidation against fully-locked state ─
            $validation = $this->eligibilityService->validateAll($product, $targetUnit, $factor, $purchaseDetailIds, $settingId, $receivedNoteDetails);

            if (!$validation['eligible']) {
                $errorMsg = empty($validation['errors']) ? 'Validasi gagal' : implode('; ', $validation['errors']);
                throw new \RuntimeException('Gagal mengeksekusi normalisasi: ' . $errorMsg);
            }

            // Lock matched transactions (already covered by the broad Transaction
            // lock above, but re-key them here for use in the mutation loop).
            $matchedTransactionIds = collect($validation['transaction_matches']['results'])
                ->pluck('transaction.id')
                ->filter();
            $matchedTransactions = Transaction::whereIn('id', $matchedTransactionIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Pre-calculate per-location snapshots including broken quantities
            $locationSnapshots = [];
            foreach ($productStocks as $locId => $stock) {
                $locationSnapshots[$locId] = [
                    'before' => [
                        'quantity' => (float) $stock->quantity,
                        'quantity_tax' => (float) $stock->quantity_tax,
                        'quantity_non_tax' => (float) $stock->quantity_non_tax,
                        'broken_quantity' => (float) $stock->broken_quantity,
                        'broken_quantity_tax' => (float) $stock->broken_quantity_tax,
                        'broken_quantity_non_tax' => (float) $stock->broken_quantity_non_tax,
                    ],
                ];
            }

            $oldPrimaryUnitId = $product->unit_id;
            $oldBaseUnitId = $product->base_unit_id;
            $newPrimaryUnitId = $targetUnit->id;
            $newBaseUnitId = $targetUnit->id;

            // ── Create the batch header ─────────────────────────────────────
            $batch = UomNormalizationBatch::create([
                'setting_id' => $settingId,
                'product_id' => $product->id,
                'actor_user_id' => $actor->id,
                'status' => UomNormalizationBatch::STATUS_PENDING,
                'reason' => $reason,
                'old_primary_unit_id' => $oldPrimaryUnitId,
                'new_primary_unit_id' => $newPrimaryUnitId,
                'old_base_unit_id' => $oldBaseUnitId,
                'new_base_unit_id' => $newBaseUnitId,
                'conversion_factor' => $factor,
                'is_acknowledged' => $isAcknowledged,
                'is_sales_price_warning_acknowledged' => $isSalesPriceWarningAcknowledged,
                'conversion_barcode_changes' => [], // Populated below once conversions/barcodes are resolved
                'location_snapshots' => [], // Will be updated below
                'rounding_amount' => 0,
            ]);

            // ── Pre-calculate new PurchaseDetail quantities ────────────────
            $pdNewQuantities = [];
            foreach ($receivedNoteDetails as $rnd) {
                if (!isset($pdNewQuantities[$rnd->po_detail_id])) {
                    $pdNewQuantities[$rnd->po_detail_id] = 0;
                }
                $pdNewQuantities[$rnd->po_detail_id] += round((float) $rnd->quantity_received * $factor, 3);
            }

            // Save original quantities/prices for pro-rating before updating
            $pdOriginalQuantities = [];
            $pdUnitPriceChanges = [];
            foreach ($purchaseDetails as $pd) {
                $pdOriginalQuantities[$pd->id] = (float) $pd->quantity;
                if (isset($pdNewQuantities[$pd->id])) {
                    $oldUnitPrice = (float) $pd->unit_price;
                    // Round to 2 decimals: rupiah has no sub-cent unit, matching
                    // the intended decimal(15,2) precision of this monetary column
                    // (production schema; the SQLite test schema does not enforce it).
                    $newUnitPrice = round($oldUnitPrice / $factor, 2);
                    $roundingEffect = ($oldUnitPrice / $factor) - $newUnitPrice;

                    $pd->update([
                        'quantity' => $pdNewQuantities[$pd->id],
                        'unit_price' => $newUnitPrice,
                    ]);

                    $pdUnitPriceChanges[$pd->id] = [
                        'old_unit_price' => $oldUnitPrice,
                        'new_unit_price' => $newUnitPrice,
                        'rounding_effect' => $roundingEffect,
                    ];
                }
            }

            // ── Allocate PurchaseDetail-level sub_total/tax across receipts ──
            // Deterministic order (received_note_detail id ascending) is the
            // single shared contract between preview and execution — both
            // must compute the identical allocation so they never disagree.
            // See PurchaseCostHelper::allocateProportionally() for the
            // exact-conservation/remainder-assignment rule.
            $subTotalAllocations = [];
            $taxAllocations = [];
            $receiptsByPurchaseDetail = $receivedNoteDetails
                ->sortBy('id')
                ->groupBy('po_detail_id');
            foreach ($receiptsByPurchaseDetail as $pdId => $rnds) {
                $pd = $purchaseDetails->firstWhere('id', $pdId);
                if (!$pd) {
                    continue;
                }
                $originalPdQty = $pdOriginalQuantities[$pd->id] ?? 0;
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
            }

            // Fetch all subsequent BUY transactions for this product to rebuild running balances
            $earliestApprovedAt = $receivedNoteDetails->map(fn($rnd) => $rnd->receivedNote->approved_at ?? $rnd->created_at)->min();
            $subsequentTransactions = Transaction::where('product_id', $product->id)
                ->where('setting_id', $settingId)
                ->where('type', 'BUY')
                ->where('created_at', '>=', $earliestApprovedAt)
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // ── Process each transaction in chronological order ─────────────
            $globalDelta = 0;
            $locationDeltas = [];
            $totalSourceQty = 0;
            $totalNormalizedQty = 0;

            foreach ($subsequentTransactions as $txn) {
                $locId = $txn->location_id;
                if (!isset($locationDeltas[$locId])) {
                    $locationDeltas[$locId] = 0;
                }

                $isMatched = isset($matchedTransactions[$txn->id]);
                if ($isMatched) {
                    $txnRecord = $matchedTransactions[$txn->id];
                    // Find corresponding receipt detail
                    $rnd = $receivedNoteDetails->first(function($item) use ($validation, $txn) {
                        return isset($validation['transaction_matches']['results'][$item->id]) &&
                               $validation['transaction_matches']['results'][$item->id]['transaction']->id === $txn->id;
                    });

                    if ($rnd) {
                        $pd = $purchaseDetails->firstWhere('id', $rnd->po_detail_id);
                        $sourceQty = (float) $rnd->quantity_received;
                        $normalizedQty = round($sourceQty * $factor, 3);
                        $qtyDelta = $normalizedQty - $sourceQty;

                        // Snapshot before
                        $txnSnapshotBefore = [
                            'quantity' => (float) $txn->quantity,
                            'quantity_tax' => (float) $txn->quantity_tax,
                            'quantity_non_tax' => (float) $txn->quantity_non_tax,
                            'previous_quantity' => (float) $txn->previous_quantity,
                            'after_quantity' => (float) $txn->after_quantity,
                            'previous_quantity_at_location' => (float) $txn->previous_quantity_at_location,
                            'after_quantity_at_location' => (float) $txn->after_quantity_at_location,
                            'current_quantity' => (float) $txn->current_quantity,
                        ];

                        // Update receiving detail quantity
                        $rnd->update(['quantity_received' => $normalizedQty]);

                        // Update transaction
                        $newTxnQty = $normalizedQty;
                        $newTxnTaxQty = $pd->tax_id ? $normalizedQty : 0;
                        $newTxnNonTaxQty = $pd->tax_id ? 0 : $normalizedQty;

                        $txn->previous_quantity = (float) $txn->previous_quantity + $globalDelta;
                        $txn->previous_quantity_at_location = (float) $txn->previous_quantity_at_location + $locationDeltas[$locId];

                        $globalDelta += $qtyDelta;
                        $locationDeltas[$locId] += $qtyDelta;

                        $newAfterQty = (float) $txn->previous_quantity + $newTxnQty;
                        $newAfterAtLoc = (float) $txn->previous_quantity_at_location + $newTxnQty;

                        $txn->update([
                            'quantity' => $newTxnQty,
                            'quantity_tax' => $newTxnTaxQty,
                            'quantity_non_tax' => $newTxnNonTaxQty,
                            'previous_quantity' => (float) $txn->previous_quantity,
                            'previous_quantity_at_location' => (float) $txn->previous_quantity_at_location,
                            'after_quantity' => $newAfterQty,
                            'after_quantity_at_location' => $newAfterAtLoc,
                            'current_quantity' => $newAfterQty,
                            'received_note_detail_id' => $rnd->id,
                        ]);

                        // Snapshot after
                        $txnSnapshotAfter = [
                            'quantity' => $newTxnQty,
                            'quantity_tax' => $newTxnTaxQty,
                            'quantity_non_tax' => $newTxnNonTaxQty,
                            'previous_quantity' => (float) $txn->previous_quantity,
                            'after_quantity' => $newAfterQty,
                            'previous_quantity_at_location' => (float) $txn->previous_quantity_at_location,
                            'after_quantity_at_location' => $newAfterAtLoc,
                            'current_quantity' => $newAfterQty,
                        ];

                        // Line audit: use the pre-computed, deterministically
                        // ordered allocation (same helper/order preview uses)
                        // rather than an independent per-line proportional
                        // calculation, so preview and execution can never
                        // disagree and the allocated amounts always sum
                        // exactly to the PurchaseDetail's sub_total/tax.
                        $lineSubTotal = $subTotalAllocations[$pd->id][$rnd->id] ?? 0.0;
                        $lineTax = $taxAllocations[$pd->id][$rnd->id] ?? 0.0;
                        $lineDpp = $lineSubTotal - $lineTax;
                        $normalizedUnitCost = $normalizedQty > 0 ? $lineDpp / $normalizedQty : 0;

                        // Every UomNormalizationLine belonging to this purchase detail
                        // (one per receipt) records the SAME normalized_unit_price /
                        // rounding_effect audit values, since the price correction is
                        // applied once per PurchaseDetail, not per receipt.
                        $priceChange = $pdUnitPriceChanges[$pd->id] ?? null;

                        UomNormalizationLine::create([
                            'batch_id' => $batch->id,
                            'purchase_detail_id' => $pd->id,
                            'received_note_detail_id' => $rnd->id,
                            'transaction_id' => $txn->id,
                            'source_quantity' => $sourceQty,
                            'source_sub_total' => round($lineSubTotal, 2),
                            'source_unit_price' => $priceChange['old_unit_price'] ?? (float) ($pd->unit_price ?? 0),
                            'source_tax_amount' => round($lineTax, 2),
                            'normalized_quantity' => $normalizedQty,
                            'normalized_unit_cost' => round($normalizedUnitCost, 6),
                            'normalized_unit_price' => $priceChange['new_unit_price'] ?? null,
                            'unit_price_rounding_effect' => $priceChange['rounding_effect'] ?? null,
                            'transaction_snapshot_before' => $txnSnapshotBefore,
                            'transaction_snapshot_after' => $txnSnapshotAfter,
                        ]);

                        // Stock increments (good quantity only)
                        $productStock = $productStocks[$locId] ?? null;
                        if ($productStock) {
                            $productStock->increment('quantity', $qtyDelta);
                            if ($pd->tax_id) {
                                $productStock->increment('quantity_tax', $qtyDelta);
                            } else {
                                $productStock->increment('quantity_non_tax', $qtyDelta);
                            }
                        }
                        $product->increment('product_quantity', $qtyDelta);

                        $totalSourceQty += $sourceQty;
                        $totalNormalizedQty += $normalizedQty;
                    }
                } else {
                    // Unselected subsequent transaction
                    if ($globalDelta != 0 || !empty($locationDeltas[$locId])) {
                        $locDelta = $locationDeltas[$locId] ?? 0;
                        $txn->update([
                            'previous_quantity' => (float) $txn->previous_quantity + $globalDelta,
                            'after_quantity' => (float) $txn->after_quantity + $globalDelta,
                            'current_quantity' => (float) $txn->current_quantity + $globalDelta,
                            'previous_quantity_at_location' => (float) $txn->previous_quantity_at_location + $locDelta,
                            'after_quantity_at_location' => (float) $txn->after_quantity_at_location + $locDelta,
                        ]);
                    }
                }
            }

            // Post-calculate per-location snapshots including broken quantities
            // Re-fetch since increment doesn't update the local model instantly
            $productStocks = $productStocks->map(fn ($s) => $s->fresh());
            foreach ($productStocks as $locId => $stock) {
                if (isset($locationSnapshots[$locId])) {
                    $locationSnapshots[$locId]['after'] = [
                        'quantity' => (float) $stock->quantity,
                        'quantity_tax' => (float) $stock->quantity_tax,
                        'quantity_non_tax' => (float) $stock->quantity_non_tax,
                        'broken_quantity' => (float) $stock->broken_quantity,
                        'broken_quantity_tax' => (float) $stock->broken_quantity_tax,
                        'broken_quantity_non_tax' => (float) $stock->broken_quantity_non_tax,
                    ];
                }
            }
            $batch->update(['location_snapshots' => $locationSnapshots]);

            // Note: broken/damaged stock is never rebased by this feature. Any
            // non-zero broken quantity anywhere is a hard blocker enforced by
            // UomNormalizationEligibilityService::checkStockHistory(), so execution
            // never reaches this point while broken stock exists on the product.

            // ── 4.5: Handle product unit and conversions ────────────────────
            $conversionBarcodeChanges = [];

            $existingConversions = \Modules\Product\Entities\ProductUnitConversion::where('product_id', $product->id)->get();
            foreach ($existingConversions as $conv) {
                $oldFactor = $conv->conversion_factor;
                $newFactor = $oldFactor * $factor;
                $conv->update([
                    'base_unit_id' => $newBaseUnitId,
                    'conversion_factor' => $newFactor,
                ]);
                // Barcode column is intentionally untouched here — existing
                // conversion barcodes (legacy inline column and their
                // BarcodeIdentity registry ownership) are preserved as-is.
                $conversionBarcodeChanges[] = [
                    'type' => 'update',
                    'unit_id' => $conv->unit_id,
                    'old_factor' => $oldFactor,
                    'new_factor' => $newFactor,
                    'barcode_preserved' => $conv->barcode,
                ];
            }

            if ($oldBaseUnitId !== $newBaseUnitId) {
                $newConversion = \Modules\Product\Entities\ProductUnitConversion::create([
                    'product_id' => $product->id,
                    'unit_id' => $oldBaseUnitId,
                    'base_unit_id' => $newBaseUnitId,
                    'conversion_factor' => $factor,
                ]);
                $conversionBarcodeChanges[] = [
                    'type' => 'create',
                    'unit_id' => $oldBaseUnitId,
                    'factor' => $factor,
                ];

                // Migrate the product's own barcode identity (representing the
                // FORMER base unit) to the newly created former-base conversion,
                // since checkBarcodeIntegrity() already proved this is safe
                // (product_id-owned BarcodeIdentity exists, no collision).
                if (!empty($product->barcode)) {
                    $oldProductBarcode = $product->barcode;
                    $productIdentity = \Modules\Product\Entities\BarcodeIdentity::where('product_id', $product->id)->first();

                    if ($productIdentity) {
                        $newConversion->update(['barcode' => $oldProductBarcode]);

                        // Single atomic update flips both owner columns together,
                        // satisfying the DB single-owner trigger in one statement.
                        $productIdentity->update([
                            'product_id' => null,
                            'product_unit_conversion_id' => $newConversion->id,
                        ]);

                        $product->update(['barcode' => null]);

                        $conversionBarcodeChanges[] = [
                            'type' => 'barcode_migrated',
                            'from' => 'product',
                            'to_conversion_unit_id' => $oldBaseUnitId,
                            'barcode_value' => $oldProductBarcode,
                            'canonical_key' => $productIdentity->canonical_key,
                        ];
                    }
                }
            }

            $product->update([
                'unit_id' => $newPrimaryUnitId,
                'base_unit_id' => $newBaseUnitId,
            ]);

            $batch->update([
                'conversion_barcode_changes' => $conversionBarcodeChanges,
            ]);

            // ── 4.6: Recalculate current HPP ────────────────────────────────
            $activeSettingOutcome = $this->recalculateCurrentHpp(
                $product,
                $settingId,
                $purchaseDetails,
                $totalNormalizedQty,
            );
            $activeSettingOutcome['classification'] = 'active-recalculated';
            $activeSettingOutcome['setting_id'] = $settingId;

            // ── Rebase purchase cost for every price-only other setting ────
            // Divide (not recalculate from receipt history — there is none
            // in these settings) each setting's existing average/last
            // purchase price by the same factor, atomically in this same
            // transaction. Never touch sale_price/tier prices/conversion
            // selling prices in any setting.
            $otherSettingOutcomes = [];
            foreach ($priceOnlySettings as $priceOnlySetting) {
                $otherSettingOutcomes[] = $this->rebasePriceOnlySettingCost(
                    $product,
                    $priceOnlySetting['setting_id'],
                    $priceOnlySetting['setting_name'],
                    $factor,
                );
            }

            $costOutcome = [
                'active_setting' => $activeSettingOutcome,
                'price_only_settings' => $otherSettingOutcomes,
            ];

            // ── 4.6: Finalize the batch ─────────────────────────────────────
            $batch->update([
                'status' => UomNormalizationBatch::STATUS_EXECUTED,
                'executed_at' => now(),
                'cost_outcome' => $costOutcome,
            ]);

            return [
                'success' => true,
                'batch' => $batch->fresh(['lines', 'product', 'actor']),
            ];
        });
    }

    /**
     * Recalculate current HPP (average and last purchase price) from
     * normalized receipt history. Does NOT replay sale HPP snapshots.
     */
    private function recalculateCurrentHpp(
        Product $product,
        int $settingId,
        Collection $purchaseDetails,
        float $totalNormalizedQty,
    ): array {
        $before = [];
        $after = [];

        // Get current per-setting price
        $productPrice = ProductPrice::firstOrCreate(
            ['product_id' => $product->id, 'setting_id' => $settingId],
            ['average_purchase_price' => 0, 'last_purchase_price' => 0],
        );

        $before['average_purchase_price'] = (float) $productPrice->average_purchase_price;
        $before['last_purchase_price'] = (float) $productPrice->last_purchase_price;

        // Recalculate from all approved receipts for this product in this setting
        $approvedReceipts = ReceivedNoteDetail::whereHas('receivedNote', fn($q) => $q->where('status', ReceivedNote::STATUS_APPROVED))
            ->whereHas('purchaseDetail', function($q) use ($product, $settingId) {
                $q->where('product_id', $product->id)
                  ->whereHas('purchase', fn($q2) => $q2->where('setting_id', $settingId));
            })
            ->with(['purchaseDetail', 'receivedNote'])
            ->get()
            ->sortBy(fn($rnd) => $rnd->receivedNote->approved_at ?? $rnd->created_at);

        $totalValue = 0;
        $totalQty = 0;
        $lastPrice = 0;

        foreach ($approvedReceipts as $rnd) {
            $pd = $rnd->purchaseDetail;
            $approvedQty = (float) $rnd->quantity_received;

            if ($approvedQty > 0) {
                // Pro-rate the line's financial value for this receipt's quantity
                $originalPdQty = (float) $pd->quantity;
                if ($originalPdQty <= 0) continue;

                // If it's fully received (or over-received), we use the exact ratio
                $receiptRatio = $approvedQty / $originalPdQty;
                $unitCost = PurchaseCostHelper::calculateUnitCost(
                    $pd->sub_total, $pd->product_tax_amount, $pd->product_discount_amount, $pd->quantity
                );

                $totalValue += $unitCost * $approvedQty;
                $totalQty += $approvedQty;
                // Last purchase price is the normalized base unit cost/price, not original pack price
                $lastPrice = $unitCost;
            }
        }

        $newAverage = $totalQty > 0 ? $totalValue / $totalQty : 0;

        $productPrice->update([
            'average_purchase_price' => round($newAverage, 2),
            'last_purchase_price' => $lastPrice,
        ]);

        $after['average_purchase_price'] = round($newAverage, 2);
        $after['last_purchase_price'] = $lastPrice;

        return [
            'before' => $before,
            'after' => $after,
        ];
    }

    /**
     * Divide a price-only other setting's existing average/last purchase
     * price by the factor. Never recalculated from receipt history (there is
     * none for this setting) — this is a pure denomination change from the
     * old base UOM to the new base UOM, preserving that setting's
     * independent purchase-cost basis. sale_price, tier prices, and
     * conversion selling prices are never touched.
     */
    private function rebasePriceOnlySettingCost(
        Product $product,
        int $otherSettingId,
        string $otherSettingName,
        float $factor,
    ): array {
        $productPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $otherSettingId)
            ->first();

        if (!$productPrice) {
            // No ProductPrice row actually exists for this "price-only"
            // setting (classified via some other zero-footprint signal) —
            // nothing to rebase.
            return [
                'classification' => 'price-only-rebased',
                'setting_id' => $otherSettingId,
                'setting_name' => $otherSettingName,
                'before' => null,
                'after' => null,
                'factor' => $factor,
            ];
        }

        $beforeAverage = (float) $productPrice->average_purchase_price;
        $beforeLast = (float) $productPrice->last_purchase_price;

        // Same monetary precision/rounding policy as the active-setting
        // purchase-cost-only rebase (Part B): round to 2 decimals, matching
        // the intended decimal(15,2) precision of these columns.
        $newAverageRaw = $beforeAverage / $factor;
        $newLastRaw = $beforeLast / $factor;
        $newAverage = round($newAverageRaw, 2);
        $newLast = round($newLastRaw, 2);

        $productPrice->update([
            'average_purchase_price' => $newAverage,
            'last_purchase_price' => $newLast,
        ]);

        return [
            'classification' => 'price-only-rebased',
            'setting_id' => $otherSettingId,
            'setting_name' => $otherSettingName,
            'before' => [
                'average_purchase_price' => $beforeAverage,
                'last_purchase_price' => $beforeLast,
            ],
            'after' => [
                'average_purchase_price' => $newAverage,
                'last_purchase_price' => $newLast,
            ],
            'factor' => $factor,
            'rounding_effect' => [
                'average_purchase_price' => $newAverageRaw - $newAverage,
                'last_purchase_price' => $newLastRaw - $newLast,
            ],
        ];
    }
}
