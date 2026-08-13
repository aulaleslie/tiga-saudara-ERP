<?php

namespace Modules\Purchase\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Purchase\Entities\UomNormalizationBatch;
use Modules\Purchase\Entities\UomNormalizationLine;

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
        ProductUnitConversion $conversion,
        Collection $purchaseDetailIds,
        User $actor,
        int $settingId,
        string $reason,
    ): array {
        if (empty(trim($reason))) {
            return ['success' => false, 'error' => 'Alasan normalisasi wajib diisi.'];
        }

        return DB::transaction(function () use ($product, $conversion, $purchaseDetailIds, $actor, $settingId, $reason) {
            $factor = (float) $conversion->conversion_factor;

            // ── Lock affected rows ──────────────────────────────────────────
            $product = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();

            $conversion = ProductUnitConversion::where('id', $conversion->id)
                ->lockForUpdate()->firstOrFail();

            $purchaseDetails = PurchaseDetail::whereIn('id', $purchaseDetailIds)
                ->lockForUpdate()
                ->with(['receivedNoteDetails.receivedNote', 'purchase'])
                ->get();

            // Collect all approved receiving details
            $receivedNoteDetails = collect();
            foreach ($purchaseDetails as $pd) {
                $approved = $pd->receivedNoteDetails
                    ->filter(fn ($rnd) => $rnd->receivedNote && $rnd->receivedNote->status === ReceivedNote::STATUS_APPROVED);
                $receivedNoteDetails = $receivedNoteDetails->merge($approved);
            }

            // Lock receiving details
            ReceivedNoteDetail::whereIn('id', $receivedNoteDetails->pluck('id'))
                ->lockForUpdate()->get();

            // ── Inside-transaction revalidation ─────────────────────────────
            
            // Check that all selected details exist uniquely and match criteria
            if ($purchaseDetails->count() !== $purchaseDetailIds->count()) {
                throw new \RuntimeException('Beberapa detail pembelian tidak valid atau duplikat.');
            }
            foreach ($purchaseDetails as $pd) {
                if ($pd->product_id !== $product->id) {
                    throw new \RuntimeException("Detail pembelian #{$pd->id} bukan untuk produk ini.");
                }
                if ($pd->purchase && $pd->purchase->setting_id !== $settingId) {
                    throw new \RuntimeException("Detail pembelian #{$pd->id} bukan dari cabang (setting) aktif.");
                }
            }

            // 1. Validate conversion
            if ($conversion->product_id !== $product->id) {
                throw new \RuntimeException('Konversi UOM bukan milik produk ini.');
            }
            if ($conversion->base_unit_id !== $product->base_unit_id) {
                throw new \RuntimeException('Konversi harus ke satuan dasar produk.');
            }
            if ($factor <= 0) {
                throw new \RuntimeException('Faktor konversi harus positif.');
            }

            // 2. Validate product eligibility
            if (!$product->stock_managed) {
                throw new \RuntimeException('Produk tidak dikelola stoknya.');
            }
            if ($product->serial_number_required || $product->serialNumbers()->exists()) {
                throw new \RuntimeException('Produk serial-tracked tidak dapat dinormalisasi.');
            }

            // 3. Check no overlap (prior normalization)
            $normResult = $this->eligibilityService->checkNoPriorNormalization($receivedNoteDetails->pluck('id'));
            if (!$normResult['clean']) {
                throw new \RuntimeException('Beberapa detail penerimaan sudah pernah dinormalisasi.');
            }

            // 4. Check receipt completion
            $completionResult = $this->eligibilityService->checkReceiptCompletion($purchaseDetailIds);
            if (!$completionResult['all_complete']) {
                throw new \RuntimeException('Semua baris pembelian harus sudah sepenuhnya diterima.');
            }

            // 5. Check stock history
            $historyResult = $this->eligibilityService->checkStockHistory($product, $settingId, $receivedNoteDetails);
            if (!$historyResult['eligible']) {
                $blockerMessages = array_column($historyResult['blockers'], 'message');
                throw new \RuntimeException('Produk tidak memenuhi syarat: ' . implode('; ', $blockerMessages));
            }

            // 6. Resolve transactions
            $transactionResult = $this->transactionResolver->resolveAll($receivedNoteDetails);
            if (!$transactionResult['all_matched']) {
                throw new \RuntimeException('Tidak semua transaksi inventori dapat dicocokkan.');
            }

            // Lock matched transactions
            $matchedTransactionIds = collect($transactionResult['results'])
                ->pluck('transaction.id')
                ->filter();
            $matchedTransactions = Transaction::whereIn('id', $matchedTransactionIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Lock product stocks
            $locationIds = $receivedNoteDetails->map(fn ($rnd) => $rnd->receivedNote->location_id)->unique();
            $productStocks = ProductStock::where('product_id', $product->id)
                ->whereIn('location_id', $locationIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('location_id');

            // ── Create the batch header ─────────────────────────────────────
            $batch = UomNormalizationBatch::create([
                'setting_id' => $settingId,
                'product_id' => $product->id,
                'product_unit_conversion_id' => $conversion->id,
                'actor_user_id' => $actor->id,
                'status' => UomNormalizationBatch::STATUS_PENDING,
                'reason' => $reason,
                'source_unit_id' => $conversion->unit_id,
                'base_unit_id' => $conversion->base_unit_id,
                'conversion_factor' => $factor,
            ]);

            // ── Pre-calculate new PurchaseDetail quantities ────────────────
            $pdNewQuantities = [];
            foreach ($receivedNoteDetails as $rnd) {
                if (!isset($pdNewQuantities[$rnd->po_detail_id])) {
                    $pdNewQuantities[$rnd->po_detail_id] = 0;
                }
                $pdNewQuantities[$rnd->po_detail_id] += round((float) $rnd->quantity_received * $factor, 3);
            }

            // Save original quantities for pro-rating before updating
            $pdOriginalQuantities = [];
            foreach ($purchaseDetails as $pd) {
                $pdOriginalQuantities[$pd->id] = (float) $pd->quantity;
                if (isset($pdNewQuantities[$pd->id])) {
                    $pd->update(['quantity' => $pdNewQuantities[$pd->id]]);
                }
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
                    $rnd = $receivedNoteDetails->first(function($item) use ($transactionResult, $txn) {
                        return isset($transactionResult['results'][$item->id]) && 
                               $transactionResult['results'][$item->id]['transaction']->id === $txn->id;
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

                        // Line audit
                        $originalPdQty = $pdOriginalQuantities[$pd->id] ?? 0;
                        $receiptRatio = $originalPdQty > 0 ? $sourceQty / $originalPdQty : 0;
                        $lineSubTotal = (float) ($pd->sub_total ?? 0) * $receiptRatio;
                        $lineTax = (float) ($pd->product_tax_amount ?? 0) * $receiptRatio;
                        $lineDpp = $lineSubTotal - $lineTax;
                        $normalizedUnitCost = $normalizedQty > 0 ? $lineDpp / $normalizedQty : 0;

                        UomNormalizationLine::create([
                            'batch_id' => $batch->id,
                            'purchase_detail_id' => $pd->id,
                            'received_note_detail_id' => $rnd->id,
                            'transaction_id' => $txn->id,
                            'source_quantity' => $sourceQty,
                            'source_sub_total' => round($lineSubTotal, 2),
                            'source_unit_price' => (float) ($pd->unit_price ?? 0),
                            'source_tax_amount' => round($lineTax, 2),
                            'normalized_quantity' => $normalizedQty,
                            'normalized_unit_cost' => round($normalizedUnitCost, 6),
                            'transaction_snapshot_before' => $txnSnapshotBefore,
                            'transaction_snapshot_after' => $txnSnapshotAfter,
                        ]);

                        // Stock increments
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

            // ── 4.5: Recalculate current HPP ────────────────────────────────
            $costOutcome = $this->recalculateCurrentHpp(
                $product,
                $settingId,
                $purchaseDetails,
                $totalNormalizedQty,
            );

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

        // Sync average across all settings
        if (class_exists(\Modules\Product\Services\ProductAveragePriceSynchronizer::class)) {
            app(\Modules\Product\Services\ProductAveragePriceSynchronizer::class)
                ->syncAveragePurchasePrice($product->id, $newAverage);
        }

        $after['average_purchase_price'] = round($newAverage, 2);
        $after['last_purchase_price'] = $lastPrice;

        return [
            'before' => $before,
            'after' => $after,
        ];
    }
}
