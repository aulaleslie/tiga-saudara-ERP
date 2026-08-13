<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Purchase\Entities\UomNormalizationLine;

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
        ProductUnitConversion $conversion,
        Collection $purchaseDetailIds,
        int $settingId
    ): array {
        $errors = [];

        // Product must be stock-managed
        if (!$product->stock_managed) {
            $errors[] = 'Produk tidak dikelola stoknya (stock_managed = false).';
        }

        // Product must not be serial-tracked
        if ($product->serial_number_required || $product->serialNumbers()->exists()) {
            $errors[] = 'Produk menggunakan serial number dan tidak dapat dinormalisasi.';
        }

        // Conversion must belong to this product
        if ($conversion->product_id !== $product->id) {
            $errors[] = 'Konversi UOM tidak dimiliki oleh produk ini.';
        }

        // Conversion must target the product's base unit
        if ($conversion->base_unit_id !== $product->base_unit_id) {
            $errors[] = 'Konversi harus ke satuan dasar produk (' . optional($product->baseUnit)->name . ').';
        }

        // Conversion factor must be positive
        if ((float) $conversion->conversion_factor <= 0) {
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
            $errors[] = 'Beberapa detail pembelian tidak ditemukan.';
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

        if ($receivedNoteDetails && $receivedNoteDetails->isNotEmpty()) {
            $earliestApprovedAt = $receivedNoteDetails->map(function($rnd) {
                return $rnd->receivedNote->approved_at ?? $rnd->created_at;
            })->min();

            if ($earliestApprovedAt) {
                $stockAffectingTypes = ['SELL', 'ADJ', 'TRF', 'RET', 'BRK', 'RPL', 'IMP', 'INIT'];
                $laterTransactions = Transaction::query()
                    ->where('product_id', $product->id)
                    ->where('setting_id', $settingId)
                    ->whereIn('type', $stockAffectingTypes)
                    ->where('created_at', '>=', $earliestApprovedAt)
                    ->get();

                if ($laterTransactions->count() > 0) {
                    $txIds = $laterTransactions->pluck('id')->implode(', ');
                    $blockers[] = [
                        'type' => 'later_transaction',
                        'count' => $laterTransactions->count(),
                        'message' => "Terdapat {$laterTransactions->count()} transaksi inventori yang menghalangi (ID: {$txIds}) setelah tanggal penerimaan.",
                    ];
                }

                $bundleParentIds = \Modules\Product\Entities\ProductBundleItem::where('product_id', $product->id)
                    ->join('product_bundles', 'product_bundles.id', '=', 'product_bundle_items.bundle_id')
                    ->pluck('product_bundles.parent_product_id');

                $posTransactionsCount = \Modules\Pos\Entities\PosTransaction::query()
                    ->where('setting_id', $settingId)
                    ->where('status', \Modules\Pos\Entities\PosTransaction::STATUS_COMPLETED)
                    ->where('created_at', '>=', $earliestApprovedAt)
                    ->whereHas('lines', function($query) use ($product, $bundleParentIds) {
                        $query->whereIn('product_id', collect([$product->id])->merge($bundleParentIds));
                    })
                    ->count();

                if ($posTransactionsCount > 0) {
                    $blockers[] = [
                        'type' => 'pos_transaction',
                        'count' => $posTransactionsCount,
                        'message' => "Terdapat {$posTransactionsCount} transaksi POS yang menghalangi setelah tanggal penerimaan.",
                    ];
                }
            }
        }

        return [
            'eligible' => empty($blockers),
            'blockers' => $blockers,
        ];
    }
    /**
     * Generate a full preview for a normalization batch.
     *
     * @return array Preview data including line details, eligibility, projected values
     */
    public function generatePreview(
        Product $product,
        ProductUnitConversion $conversion,
        Collection $purchaseDetailIds,
        int $settingId,
    ): array {
        $factor = (float) $conversion->conversion_factor;

        // Validate batch selection
        $selectionResult = $this->validateBatchSelection($product, $conversion, $purchaseDetailIds, $settingId);
        if (!$selectionResult['valid']) {
            return [
                'eligible' => false,
                'can_preview' => false,
                'errors' => $selectionResult['errors'],
            ];
        }

        // Check receipt completion
        $completionResult = $this->checkReceiptCompletion($purchaseDetailIds);

        // Collect all approved receiving details for selected purchase details
        $purchaseDetails = PurchaseDetail::whereIn('id', $purchaseDetailIds)
            ->with(['receivedNoteDetails.receivedNote', 'purchase', 'tax'])
            ->get();

        $receivedNoteDetails = collect();
        foreach ($purchaseDetails as $pd) {
            $approved = $pd->receivedNoteDetails
                ->filter(fn ($rnd) => $rnd->receivedNote && $rnd->receivedNote->status === ReceivedNote::STATUS_APPROVED);
            $receivedNoteDetails = $receivedNoteDetails->merge($approved);
        }

        // Check prior normalization
        $normResult = $this->checkNoPriorNormalization($receivedNoteDetails->pluck('id'));

        // Check stock history (pass the receivedNoteDetails to find earliest receipt)
        $historyResult = $this->checkStockHistory($product, $settingId, $receivedNoteDetails);

        // Resolve transactions
        $transactionResult = $this->transactionResolver->resolveAll($receivedNoteDetails);

        // Build line previews
        $linePreview = [];
        $totalSourceQty = 0;
        $totalNormalizedQty = 0;

        foreach ($receivedNoteDetails as $rnd) {
            $pd = $purchaseDetails->firstWhere('id', $rnd->po_detail_id);
            $sourceQty = (float) $rnd->quantity_received;
            $normalizedQty = round($sourceQty * $factor, 3);

            $txnResult = $transactionResult['results'][$rnd->id] ?? ['status' => 'missing', 'transaction' => null];

            // Calculate normalized unit cost (preserve monetary total, divide by new quantity)
            $lineDpp = ((float) ($pd->sub_total ?? 0)) - ((float) ($pd->product_tax_amount ?? 0));
            // Pro-rate cost for this receiving detail if partial receipt
            $receiptRatio = $pd->quantity > 0 ? $sourceQty / (float) $pd->quantity : 0;
            $lineReceiptDpp = $lineDpp * $receiptRatio;
            $normalizedUnitCost = $normalizedQty > 0 ? $lineReceiptDpp / $normalizedQty : 0;

            $linePreview[] = [
                'received_note_detail_id' => $rnd->id,
                'purchase_detail_id' => $pd->id,
                'purchase_reference' => $pd->purchase->reference ?? '—',
                'location' => $rnd->receivedNote->location->name ?? '—',
                'source_quantity' => $sourceQty,
                'normalized_quantity' => $normalizedQty,
                'source_sub_total' => (float) ($pd->sub_total ?? 0),
                'source_unit_price' => (float) ($pd->unit_price ?? 0),
                'source_tax_amount' => (float) ($pd->product_tax_amount ?? 0),
                'normalized_unit_cost' => round($normalizedUnitCost, 6),
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

        $canExecute = $completionResult['all_complete']
            && $normResult['clean']
            && $historyResult['eligible']
            && $transactionResult['all_matched'];

        return [
            'eligible' => $canExecute,
            'can_preview' => true,
            'errors' => array_merge(
                $completionResult['all_complete'] ? [] : ['Beberapa baris pembelian belum sepenuhnya diterima.'],
                $normResult['clean'] ? [] : ['Beberapa detail penerimaan sudah pernah dinormalisasi.'],
                $historyResult['blockers'] ? array_column($historyResult['blockers'], 'message') : [],
                $transactionResult['all_matched'] ? [] : ['Beberapa transaksi inventori tidak dapat dicocokkan dengan unik.'],
            ),
            'receipt_completion' => $completionResult,
            'prior_normalization' => $normResult,
            'stock_history' => $historyResult,
            'transaction_matches' => $transactionResult,
            'conversion' => [
                'source_unit' => optional($conversion->unit)->name,
                'base_unit' => optional($conversion->baseUnit)->name,
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
