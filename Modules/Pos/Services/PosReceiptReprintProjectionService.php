<?php

namespace Modules\Pos\Services;

use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Exceptions\PosReprintAmbiguityException;
use Modules\Pos\Exceptions\PosReprintProjectionException;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\PaymentMethod;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PosReceiptReprintProjectionService
{
    public function __construct(
        protected PosReachableSalesResolver $salesResolver = new PosReachableSalesResolver(),
        protected PosSettlementProjectionService $settlementProjectionService = new PosSettlementProjectionService(),
        protected PosReceiptService $receiptService = new PosReceiptService()
    ) {}

    /**
     * Shared completed-receipt builder callable by all completed receipt print/reprint actions.
     *
     * @param PosCheckout $checkout
     * @return array
     * @throws PosReprintAmbiguityException
     * @throws PosReprintProjectionException
     */
    public function getCompletedReceiptData(PosCheckout $checkout): array
    {
        return $this->getCompletedReprintReceiptData($checkout);
    }

    /**
     * Build completed-reprint receipt data projecting current Sale-derived prices.
     *
     * @param PosCheckout $checkout
     * @return array
     * @throws PosReprintAmbiguityException
     * @throws PosReprintProjectionException
     */
    public function getCompletedReprintReceiptData(PosCheckout $checkout): array
    {
        // 1. Get base receipt data layout and facts (customer, terminal, cashier, tender, change, etc.)
        $baseReceiptData = $this->receiptService->getReceiptData($checkout);

        // 2. Resolve transaction
        $transaction = $checkout->transaction
            ?? PosTransaction::where('completed_checkout_id', $checkout->id)->first()
            ?? PosTransaction::find($checkout->pos_transaction_id);

        if (! $transaction) {
            throw new PosReprintProjectionException('Transaksi POS untuk checkout ini tidak ditemukan.');
        }

        $transaction->loadMissing([
            'lines.conversion.unit',
            'lines.product.unit',
            'lines.product.baseUnit',
            'lines.serials',
        ]);

        $receiptTransactions = $checkout->transactions()
            ->with([
                'lines.conversion.unit',
                'lines.product.unit',
                'lines.product.baseUnit',
                'lines.serials',
            ])
            ->get();
        if (! $receiptTransactions->contains('id', $transaction->id)) {
            $receiptTransactions->push($transaction);
        }
        $receiptLines = $receiptTransactions
            ->flatMap(fn (PosTransaction $receiptTransaction) => $receiptTransaction->lines)
            ->unique('id')
            ->values();

        // 3. Resolve reachable sales via PosReachableSalesResolver with diagnostics
        $diagnostics = $this->salesResolver->resolveWithDiagnostics($checkout);
        if (! $diagnostics['is_valid'] || $diagnostics['sales']->isEmpty()) {
            $reason = implode(' ', $diagnostics['anomalies'] ?: ['Pemetaan transaksi ke dokumen penjualan tidak valid.']);
            throw new PosReprintProjectionException("Tidak dapat memproyeksikan harga struk terkini: {$reason}");
        }

        $sales = $diagnostics['sales'];
        // Ensure sale details are fresh with relations
        $sales->load([
            'saleDetails.bundleItems',
            'saleDetails.product.unit',
            'saleDetails.product.baseUnit',
            'salePayments.paymentMethod',
        ]);

        // 4. Group authoritative SaleDetails by PosTransactionLine
        $lineDetailsMap = $this->mapSaleDetailsToTransactionLines($receiptLines, $sales);

        // 5. For each Sale, reconcile header differences (discount, shipping, tax adjustments)
        // across its mapped POS lines using minor-unit arithmetic
        $assignedLineAmounts = $this->computeAssignedLineAmounts($sales, $lineDetailsMap);

        // 6. Project current global POS settlement for Total and Sisa Utang
        $settlement = $this->settlementProjectionService->project($transaction);
        if (! $settlement['is_valid']) {
            throw new PosReprintProjectionException('Proyeksi penyelesaian penjualan POS tidak valid.');
        }

        $currentTotal = round((float) $settlement['total_amount'], 2);
        $currentLiveDue = round((float) $settlement['live_due'], 2);

        // 7. Load completed returns for the checkout group
        $transactionIds = $receiptTransactions->pluck('id')->filter()->all();
        $completedReturns = PosReturn::query()
            ->with([
                'lines.returnedSerial',
                'lines.replacementSerial',
                'lines.product',
                'lines.posTransactionLine',
                'lines.saleDetail',
                'saleReturns.saleReturnPayments',
                'saleReturns.saleReturnDetails',
            ])
            ->where('setting_id', $checkout->setting_id)
            ->where('status', PosReturn::STATUS_COMPLETED)
            ->where('is_reversed', false)
            ->where(function ($q) use ($checkout, $transactionIds) {
                $q->where('pos_checkout_id', $checkout->id);
                if (! empty($transactionIds)) {
                    $q->orWhereIn('pos_transaction_id', $transactionIds);
                }
            })
            ->orderBy('settled_at', 'asc')
            ->orderBy('approved_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $hasCompletedReturns = $completedReturns->isNotEmpty();
        $allReturnLines = $completedReturns->flatMap(fn ($r) => $r->lines)->values();

        // Resolve proven transaction line ID on return lines before determining fully returned lines
        $allDetails = [];
        foreach ($sales as $sale) {
            foreach ($sale->saleDetails as $detail) {
                $allDetails[] = $detail;
            }
        }

        foreach ($completedReturns as $posReturn) {
            foreach ($posReturn->lines as $returnLine) {
                if (empty($returnLine->pos_transaction_line_id)) {
                    // Do not assign parent POS line ID to bundle component return rows:
                    // component association must stay separate from customer-facing line accounting.
                    if (! $this->isBundleComponentReturnLine($returnLine)) {
                        $resolvedTxnLineId = $this->resolveTransactionLineIdFromReturnLine($returnLine, $receiptLines, $allDetails);
                        if ($resolvedTxnLineId) {
                            $resolvedLine = $receiptLines->firstWhere('id', $resolvedTxnLineId);
                            if ($resolvedLine && (int) $resolvedLine->product_id === (int) $returnLine->product_id) {
                                $returnLine->pos_transaction_line_id = $resolvedTxnLineId;
                            }
                        }
                    }
                }
            }
        }

        $fullyReturnedLineIds = [];
        foreach ($receiptLines as $txnLine) {
            $lineId = (int) $txnLine->id;
            $qty = (float) $txnLine->qty;
            $cashReturnedQty = (float) $allReturnLines
                ->filter(function ($l) use ($lineId, $txnLine) {
                    if ((int) $l->pos_transaction_line_id !== $lineId) {
                        return false;
                    }
                    if ($this->isBundleComponentReturnLine($l, $txnLine)) {
                        return false;
                    }
                    return (int) $l->product_id === (int) $txnLine->product_id;
                })
                ->where('resolution', PosReturnLine::RESOLUTION_CASH_RETURN)
                ->sum('quantity');
            if ($cashReturnedQty >= $qty - 0.00001 && $qty > 0) {
                $fullyReturnedLineIds[] = $lineId;
            }
        }

        $returnHistory = $this->buildReturnHistory($completedReturns, $receiptLines, $fullyReturnedLineIds, $allDetails);

        // 8. Rebuild line representations with updated prices & breakdowns
        $reprintedLines = $this->buildReprintedLines(
            $receiptLines,
            $baseReceiptData['lines'],
            $assignedLineAmounts,
            $completedReturns,
            $lineDetailsMap
        );

        // Verify remaining displayed line amounts reconcile to currentTotal
        $sumLinesCents = array_sum(array_map(
            fn ($line) => (int) round((float) $line['sub_total'] * 100),
            $reprintedLines
        ));
        $currentTotalCents = (int) round($currentTotal * 100);
        if ($sumLinesCents !== $currentTotalCents) {
            $sumLines = $sumLinesCents / 100;
            throw new PosReprintProjectionException("Rekonsiliasi total baris ({$sumLines}) berbeda dengan total penjualan ({$currentTotal}).");
        }

        // 9. Construct final receipt data
        $reprintedData = $baseReceiptData;
        $reprintedData['lines'] = $reprintedLines;
        $reprintedData['grand_total'] = $currentTotal;
        $reprintedData['subtotal'] = $currentTotal; // Canonical current total matches subtotal before global discounts if already factored
        // Projected line charges are already net of the current Sale's discount, shipping and tax,
        // so the checkout-time discount snapshot must not be deducted again.
        $reprintedData['discount'] = 0.0;
        $reprintedData['tax'] = 0.0;
        $reprintedData['outstanding_debt'] = $currentLiveDue;

        if ($transaction && trim((string) $transaction->note) !== '') {
            $reprintedData['note'] = (string) $transaction->note;
        } elseif (! array_key_exists('note', $reprintedData)) {
            $reprintedData['note'] = null;
        }

        $isDebtTransaction = (bool) ($checkout->metadata['is_debt'] ?? false)
            || ((float) ($checkout->debt_amount ?? 0) > 0.005)
            || ((float) ($checkout->grand_total - $checkout->paid_total) > 0.005);

        if ($hasCompletedReturns || $isDebtTransaction) {
            $retainedPaidAmount = round(max(0, $currentTotal - $currentLiveDue), 2);
            $reprintedData['amount_paid'] = $retainedPaidAmount;
            $reprintedData['change'] = 0.0;
            $reprintedData['payment_breakdown'] = $this->buildReconciledPaymentBreakdown($sales, $checkout, $retainedPaidAmount);
            $reprintedData['return_history'] = $returnHistory;
        } else {
            $reprintedData['amount_paid'] = $checkout->paid_total;
            $reprintedData['change'] = isset($baseReceiptData['change'])
                ? (float) $baseReceiptData['change']
                : (float) ($checkout->change_total > 0 ? $checkout->change_total : max(0, $checkout->paid_total - $checkout->grand_total));
            $reprintedData['payment_breakdown'] = $baseReceiptData['payment_breakdown'] ?? [];
            $reprintedData['return_history'] = [];
        }

        $reprintedData['is_reprint_projected'] = true;
        $reprintedData['receipt_visibility'] = $baseReceiptData['receipt_visibility']
            ?? $this->receiptService->resolveReceiptVisibility($checkout->setting_id, $checkout->setting);

        return $reprintedData;
    }

    /**
     * Map SaleDetails to PosTransactionLine.id.
     *
     * @return array<int, array<int, SaleDetails>> [transaction_line_id => [SaleDetails, ...]]
     */
    protected function mapSaleDetailsToTransactionLines(Collection $lines, $sales): array
    {
        $allDetails = [];
        foreach ($sales as $sale) {
            foreach ($sale->saleDetails as $detail) {
                $allDetails[] = $detail;
            }
        }

        if ($lines->isEmpty() || empty($allDetails)) {
            throw new PosReprintAmbiguityException(
                'Struk tidak dapat dicetak ulang dengan harga terkini karena baris transaksi atau rincian penjualan tidak tersedia. Silakan lihat struk historis checkout asli.'
            );
        }

        $lineDetailsMap = [];
        $unlinkedDetails = [];

        foreach ($allDetails as $detail) {
            if (! empty($detail->pos_transaction_line_id)) {
                $lineId = (int) $detail->pos_transaction_line_id;
                $lineDetailsMap[$lineId][] = $detail;
            } else {
                $unlinkedDetails[] = $detail;
            }
        }

        // Check if any unlinked details belong to replacement sales linked via DispatchDetail
        if (! empty($unlinkedDetails)) {
            $unlinkedSaleIds = collect($unlinkedDetails)->pluck('sale_id')->filter()->unique()->all();
            if (! empty($unlinkedSaleIds)) {
                $dispatchDetails = DispatchDetail::query()
                    ->with(['posReturnLine.bundleParentSaleDetail'])
                    ->whereIn('sale_id', $unlinkedSaleIds)
                    ->whereNotNull('pos_return_line_id')
                    ->get();

                $remainingUnlinked = [];
                foreach ($unlinkedDetails as $detail) {
                    $matchingDispatch = $dispatchDetails->first(function (DispatchDetail $dd) use ($detail) {
                        // 1. Direct link by sale_detail_id
                        if (! empty($dd->sale_detail_id) && (int) $dd->sale_detail_id === (int) $detail->id) {
                            return true;
                        }

                        // 2. Link by sale_id and product_id
                        if ((int) $dd->sale_id === (int) $detail->sale_id && (int) $dd->product_id === (int) $detail->product_id) {
                            // If it is a same-owner replacement dispatch, ensure this detail was actually the returned item
                            if (! empty($dd->replacement_of_dispatch_detail_id)) {
                                $returnedSaleDetailId = $dd->posReturnLine?->sale_detail_id;
                                return ! empty($returnedSaleDetailId) && (int) $returnedSaleDetailId === (int) $detail->id;
                            }

                            return true;
                        }

                        return false;
                    });

                    $lineId = $matchingDispatch
                        ? $this->resolveTransactionLineIdFromReturnLine($matchingDispatch->posReturnLine, $lines, $allDetails)
                        : null;

                    if ($lineId) {
                        $lineDetailsMap[(int) $lineId][] = $detail;
                    } else {
                        $remainingUnlinked[] = $detail;
                    }
                }
                $unlinkedDetails = $remainingUnlinked;
            }
        }

        // If there are unlinked details, map remaining unrelated rows using historical resolution rules
        if (! empty($unlinkedDetails)) {
            $unmappedLines = $lines->filter(fn ($l) => empty($lineDetailsMap[(int) $l->id]));
            $isSingleLineReceipt = ($lines->count() === 1);
            $historicalMapped = $this->resolveHistoricalMapping($unmappedLines, $sales, $unlinkedDetails, $isSingleLineReceipt);

            foreach ($historicalMapped as $lineId => $details) {
                foreach ($details as $d) {
                    $lineDetailsMap[$lineId][] = $d;
                }
            }
        }

        // Ensure every single SaleDetail is accounted for in lineDetailsMap
        $mappedDetailCount = array_sum(array_map('count', $lineDetailsMap));
        if ($mappedDetailCount !== count($allDetails)) {
            throw new PosReprintAmbiguityException(
                'Sebagian rincian penjualan tidak dapat dipetakan secara deterministik ke baris transaksi.'
            );
        }

        // Ensure all POS lines have at least one mapped detail and no foreign lines
        $validLineIds = $lines->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach ($validLineIds as $requiredLineId) {
            if (empty($lineDetailsMap[$requiredLineId])) {
                throw new PosReprintAmbiguityException(
                    "Baris transaksi #{$requiredLineId} tidak dapat dipetakan secara unik ke rincian penjualan."
                );
            }
        }

        foreach (array_keys($lineDetailsMap) as $mappedLineId) {
            if (! in_array($mappedLineId, $validLineIds, true)) {
                throw new PosReprintProjectionException("Rincian penjualan terhubung ke baris transaksi #{$mappedLineId} di luar transaksi ini.");
            }
        }

        return $lineDetailsMap;
    }

    /**
     * Resolve transaction line ID from a PosReturnLine (direct or via bundle parent).
     */
    protected function resolveTransactionLineIdFromReturnLine(?PosReturnLine $returnLine, Collection $lines, array $allDetails = []): ?int
    {
        if (! $returnLine) {
            return null;
        }

        if (! empty($returnLine->pos_transaction_line_id)) {
            return (int) $returnLine->pos_transaction_line_id;
        }

        if (! empty($returnLine->sale_detail_id)) {
            foreach ($allDetails as $d) {
                if ((int) $d->id === (int) $returnLine->sale_detail_id) {
                    if (! empty($d->pos_transaction_line_id)) {
                        return (int) $d->pos_transaction_line_id;
                    }
                    $matchingLines = $lines->filter(fn ($l) => (int) $l->product_id === (int) $d->product_id);
                    if ($matchingLines->count() === 1) {
                        return (int) $matchingLines->first()->id;
                    }
                }
            }

            $saleDetail = $returnLine->relationLoaded('saleDetail') && $returnLine->saleDetail
                ? $returnLine->saleDetail
                : SaleDetails::query()->find($returnLine->sale_detail_id);

            if ($saleDetail) {
                if (! empty($saleDetail->pos_transaction_line_id)) {
                    return (int) $saleDetail->pos_transaction_line_id;
                }
                $matchingLines = $lines->filter(fn ($l) => (int) $l->product_id === (int) $saleDetail->product_id);
                if ($matchingLines->count() === 1) {
                    return (int) $matchingLines->first()->id;
                }
            }
        }

        if (! empty($returnLine->bundle_parent_sale_detail_id)) {
            foreach ($allDetails as $d) {
                if ((int) $d->id === (int) $returnLine->bundle_parent_sale_detail_id) {
                    if (! empty($d->pos_transaction_line_id)) {
                        return (int) $d->pos_transaction_line_id;
                    }
                    $matchingLines = $lines->filter(fn ($l) => (int) $l->product_id === (int) $d->product_id);
                    if ($matchingLines->count() > 1) {
                        throw new PosReprintAmbiguityException(
                            "Terdapat lebih dari satu baris transaksi untuk produk paket #{$d->product_id}; pemetaan komponen retur bersifat ambigu."
                        );
                    }
                    if ($matchingLines->count() === 1) {
                        return (int) $matchingLines->first()->id;
                    }
                }
            }

            $parentDetail = SaleDetails::query()->find($returnLine->bundle_parent_sale_detail_id);
            if ($parentDetail) {
                if (! empty($parentDetail->pos_transaction_line_id)) {
                    return (int) $parentDetail->pos_transaction_line_id;
                }
                $matchingLines = $lines->filter(fn ($l) => (int) $l->product_id === (int) $parentDetail->product_id);
                if ($matchingLines->count() > 1) {
                    throw new PosReprintAmbiguityException(
                        "Terdapat lebih dari satu baris transaksi untuk produk paket #{$parentDetail->product_id}; pemetaan komponen retur bersifat ambigu."
                    );
                }
                if ($matchingLines->count() === 1) {
                    return (int) $matchingLines->first()->id;
                }
            }
        }

        return null;
    }

    /**
     * Determine if a return line is an internal bundle component row rather than a customer-facing purchase line.
     */
    protected function isBundleComponentReturnLine(PosReturnLine $line, ?PosTransactionLine $txnLine = null): bool
    {
        if ((float) ($line->component_quantity_per_bundle ?? 0) > 0) {
            return true;
        }

        if (empty($line->bundle_parent_sale_detail_id)) {
            return false;
        }

        if (! empty($line->sale_detail_id) && (int) $line->bundle_parent_sale_detail_id !== (int) $line->sale_detail_id) {
            return true;
        }

        if ($txnLine && (int) $line->product_id !== (int) $txnLine->product_id) {
            return true;
        }

        return false;
    }

    /**
     * Resolve historical unlinked SaleDetails to POS lines or fail safely.
     */
    protected function resolveHistoricalMapping(
        Collection $lines,
        $sales,
        array $allDetails,
        bool $isSingleLineReceipt = false
    ): array {
        if (empty($allDetails)) {
            return [];
        }

        if ($lines->isEmpty()) {
            throw new PosReprintAmbiguityException(
                'Rincian penjualan tidak dapat dipetakan ke baris transaksi karena tidak ada baris transaksi yang tersedia.'
            );
        }

        // Rule 1: Single customer-facing POS line (restricted to receipts that originally contained one customer-facing line)
        if ($isSingleLineReceipt && $lines->count() === 1) {
            $singleLine = $lines->first();
            return [
                (int) $singleLine->id => $allDetails,
            ];
        }

        // Rule 2: Multi-line checkout - accept ONLY if there is a 100% bijective unique matching
        // by product identity without duplicates or ambiguity.
        $productCountsInLines = [];
        foreach ($lines as $l) {
            $pId = (int) $l->product_id;
            $productCountsInLines[$pId] = ($productCountsInLines[$pId] ?? 0) + 1;
        }

        // If any product appears more than once in POS lines, it is ambiguous
        foreach ($productCountsInLines as $pId => $cnt) {
            if ($cnt > 1) {
                throw new PosReprintAmbiguityException();
            }
        }

        // Check if every SaleDetail can be uniquely assigned to exactly one POS line
        $lineDetailsMap = [];
        foreach ($allDetails as $detail) {
            $matchedLines = $lines->filter(function (PosTransactionLine $line) use ($detail) {
                return (int) $line->product_id === (int) $detail->product_id;
            });

            if ($matchedLines->count() !== 1) {
                // Could not match uniquely (e.g. bundle component product that was split into separate line with different product_id)
                throw new PosReprintAmbiguityException();
            }

            $lineId = (int) $matchedLines->first()->id;

            // If a line already has a mapped detail in historical mapping, it's ambiguous
            if (! empty($lineDetailsMap[$lineId])) {
                throw new PosReprintAmbiguityException();
            }

            $lineDetailsMap[$lineId][] = $detail;
        }

        // Ensure all POS lines have at least one mapped detail
        foreach ($lines as $l) {
            if (empty($lineDetailsMap[(int) $l->id])) {
                throw new PosReprintAmbiguityException();
            }
        }

        return $lineDetailsMap;
    }

    /**
     * Compute assigned current line amount in Rupiah for each POS transaction line.
     * Reconciles header discounts/shipping per Sale in minor units.
     *
     * @param iterable<Sale> $sales
     * @param array<int, array<int, SaleDetails>> $lineDetailsMap
     * @return array<int, float> [transaction_line_id => float amount]
     */
    protected function computeAssignedLineAmounts($sales, array $lineDetailsMap): array
    {
        $lineAmounts = [];
        foreach (array_keys($lineDetailsMap) as $lineId) {
            $lineAmounts[$lineId] = 0.0;
        }

        foreach ($sales as $sale) {
            $saleTotalCents = (int) round(((float) $sale->total_amount) * 100);

            // Group details of this Sale by line
            $saleLineDetails = [];
            foreach ($sale->saleDetails as $detail) {
                $foundLineId = null;
                foreach ($lineDetailsMap as $lineId => $details) {
                    if (in_array($detail, $details, true) || collect($details)->contains('id', $detail->id)) {
                        $foundLineId = $lineId;
                        break;
                    }
                }
                if ($foundLineId !== null) {
                    $saleLineDetails[$foundLineId][] = $detail;
                }
            }

            if (empty($saleLineDetails)) {
                continue;
            }

            // Sum of subtotals of details for this Sale
            $rawDetailSubtotalsByLine = [];
            $totalDetailSubtotalCents = 0;
            foreach ($saleLineDetails as $lineId => $details) {
                $lineSubtotal = 0.0;
                foreach ($details as $d) {
                    $lineSubtotal += (float) $d->sub_total;
                }
                $cents = (int) round($lineSubtotal * 100);
                $rawDetailSubtotalsByLine[$lineId] = $cents;
                $totalDetailSubtotalCents += $cents;
            }

            // Allocate $saleTotalCents across lines using largest-remainder method
            $allocatedCentsByLine = [];
            $lineKeys = array_keys($rawDetailSubtotalsByLine);

            if ($totalDetailSubtotalCents <= 0) {
                // If total detail subtotal is 0, split evenly
                $count = count($lineKeys);
                $base = intdiv($saleTotalCents, $count);
                $remainder = $saleTotalCents - ($base * $count);
                foreach ($lineKeys as $idx => $lineId) {
                    $allocatedCentsByLine[$lineId] = $base + ($idx < $remainder ? 1 : 0);
                }
            } else {
                $distributed = 0;
                $remainders = [];
                foreach ($rawDetailSubtotalsByLine as $lineId => $cents) {
                    $exact = ($cents * $saleTotalCents) / $totalDetailSubtotalCents;
                    $floor = (int) floor($exact);
                    $allocatedCentsByLine[$lineId] = $floor;
                    $distributed += $floor;
                    $remainders[$lineId] = $exact - $floor;
                }

                $centsDiff = $saleTotalCents - $distributed;
                if ($centsDiff > 0) {
                    arsort($remainders, SORT_NUMERIC);
                    foreach (array_keys($remainders) as $lineId) {
                        if ($centsDiff <= 0) {
                            break;
                        }
                        $allocatedCentsByLine[$lineId]++;
                        $centsDiff--;
                    }
                }
            }

            foreach ($allocatedCentsByLine as $lineId => $cents) {
                $lineAmounts[$lineId] = ($lineAmounts[$lineId] ?? 0.0) + ($cents / 100);
            }
        }

        return $lineAmounts;
    }

    /**
     * Build reprinted receipt lines with updated prices and unit breakdowns.
     */
    protected function buildReprintedLines(
        Collection $receiptLines,
        array $baseLines,
        array $assignedLineAmounts,
        ?Collection $completedReturns = null,
        array $lineDetailsMap = []
    ): array {
        $linesById = $receiptLines->keyBy('id');
        $baseLineIds = [];
        foreach ($baseLines as $baseLine) {
            $lineId = (int) ($baseLine['pos_transaction_line_id'] ?? 0);
            if ($lineId <= 0 || isset($baseLineIds[$lineId])) {
                throw new PosReprintProjectionException('Baris struk tidak memiliki identitas baris transaksi POS yang unik.');
            }
            $baseLineIds[$lineId] = true;
        }

        $assignedLineIds = array_map('intval', array_keys($assignedLineAmounts));
        $receiptLineIds = array_map('intval', array_keys($baseLineIds));
        sort($assignedLineIds);
        sort($receiptLineIds);
        if ($assignedLineIds !== $receiptLineIds) {
            throw new PosReprintProjectionException('Pemetaan baris struk tidak sesuai dengan rincian penjualan yang diproyeksikan.');
        }

        $reprintedLines = [];

        $allReturnLines = $completedReturns !== null
            ? $completedReturns->flatMap(fn ($r) => $r->lines)->values()
            : collect();

        foreach ($baseLines as $baseLine) {
            $lineId = (int) $baseLine['pos_transaction_line_id'];
            $txnLine = $linesById->get($lineId);
            if (! $txnLine || ! array_key_exists($lineId, $assignedLineAmounts)) {
                throw new PosReprintProjectionException("Baris struk #{$lineId} tidak memiliki rincian penjualan yang dapat diproyeksikan.");
            }
            $assignedAmount = $assignedLineAmounts[$lineId];

            $qty = (float) $baseLine['qty'];

            $parentSaleDetailIds = collect($lineDetailsMap[$lineId] ?? [])->pluck('id')->filter()->all();

            $matchingReturnLines = $allReturnLines->filter(function ($l) use ($lineId, $parentSaleDetailIds) {
                if ((int) $l->pos_transaction_line_id === $lineId) {
                    return true;
                }
                if (! empty($l->bundle_parent_sale_detail_id) && in_array((int) $l->bundle_parent_sale_detail_id, $parentSaleDetailIds, true)) {
                    return true;
                }
                return false;
            })->values();

            $cashReturnedQty = (float) $matchingReturnLines
                ->filter(function ($l) use ($lineId, $txnLine) {
                    if ((int) $l->pos_transaction_line_id !== $lineId) {
                        return false;
                    }
                    if ($this->isBundleComponentReturnLine($l, $txnLine)) {
                        return false;
                    }
                    return (int) $l->product_id === (int) $txnLine->product_id;
                })
                ->where('resolution', PosReturnLine::RESOLUTION_CASH_RETURN)
                ->sum('quantity');

            if ($cashReturnedQty > $qty + 0.0001) {
                throw new PosReprintProjectionException("Kuantitas retur melebihi kuantitas pembelian asli pada baris #{$lineId}.");
            }

            $remainingQty = max(0.0, round($qty - $cashReturnedQty, 4));

            // Omit lines with zero remaining quantity from main items
            if ($completedReturns !== null && $matchingReturnLines->isNotEmpty() && $remainingQty <= 0.00001) {
                continue;
            }

            $effectiveQty = $remainingQty > 0 ? $remainingQty : $qty;
            $pricePerUnit = $effectiveQty > 0 ? round($assignedAmount / $effectiveQty, 2) : $assignedAmount;

            // Reconstruct unit breakdown if present
            $unitBreakdown = $baseLine['unit_breakdown'] ?? null;
            if ($txnLine && $unitBreakdown) {
                $priceSource = $txnLine->line_meta['price_source'] ?? 'BASE';
                $breakdown = $txnLine->line_meta['breakdown'] ?? null;

                if ($priceSource === 'PACKED' && is_array($breakdown) && is_array($unitBreakdown)) {
                    $originalLineTotal = (float) ($baseLine['sub_total'] ?? 0.0);
                    if ($matchingReturnLines->isEmpty() && abs($assignedAmount - $originalLineTotal) < 0.01) {
                        // Preserved as-is from baseLine when no returns and amount unchanged
                    } elseif ($matchingReturnLines->isEmpty()) {
                        $unitBreakdown = $this->recomputePackedBreakdown($breakdown, $txnLine, $assignedAmount);
                    } else {
                        $conversionFactor = (float) ($txnLine->conversion?->conversion_factor ?? 0.0);
                        if ($conversionFactor > 1) {
                            $remWhole = (int) round($effectiveQty);
                            $boxCount = intdiv($remWhole, (int) round($conversionFactor));
                            $looseCount = $remWhole % (int) round($conversionFactor);
                        } else {
                            $boxCount = (int) ($breakdown['box_count'] ?? 0);
                            $looseCount = (int) round($effectiveQty);
                        }
                        $updatedBreakdown = array_merge($breakdown, [
                            'box_count' => $boxCount,
                            'loose_count' => $looseCount,
                        ]);
                        $unitBreakdown = $this->recomputePackedBreakdown($updatedBreakdown, $txnLine, $assignedAmount);
                    }
                } elseif (is_string($unitBreakdown)) {
                    $unitName = null;
                    if ($txnLine->conversion && $txnLine->conversion->unit) {
                        $unitName = $txnLine->conversion->unit->short_name ?? $txnLine->conversion->unit->name;
                    } elseif ($txnLine->product) {
                        $unitName = $txnLine->product->unit->short_name
                            ?? $txnLine->product->unit->name
                            ?? $txnLine->product->baseUnit->short_name
                            ?? $txnLine->product->baseUnit->name
                            ?? $txnLine->product->product_unit;
                    }
                    if ($unitName) {
                        $wholeQty = (int) round($effectiveQty);
                        $unitBreakdown = $wholeQty > 0 && abs($effectiveQty - $wholeQty) < 0.000001
                            ? $this->formatUnitBreakdown($wholeQty, (int) round($assignedAmount * 100), $unitName . '(S)')
                            : sprintf(
                                "%s %s(S) @ %s",
                                $effectiveQty,
                                $unitName,
                                $this->receiptService->formatReceiptCurrency($pricePerUnit)
                            );
                    }
                }
            }

            // Scale bundle composition if parent quantity reduced
            $bundleComposition = $baseLine['bundle_composition'] ?? [];
            if (! empty($bundleComposition) && $qty > 0 && $effectiveQty < $qty) {
                $ratio = $effectiveQty / $qty;
                $scaledComposition = [];
                foreach ($bundleComposition as $compItem) {
                    $scaledItem = $compItem;
                    $scaledItem['qty'] = round((float) ($compItem['qty'] ?? 0) * $ratio, 4);
                    $scaledComposition[] = $scaledItem;
                }
                $bundleComposition = $scaledComposition;
            }

            // Resolve effective serials through replacements and cash returns
            $effectiveSerials = collect($baseLine['assigned_serials'] ?? [])->values()->all();
            if ($matchingReturnLines->isNotEmpty()) {
                foreach ($matchingReturnLines as $retLine) {
                    if ($retLine->resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT) {
                        $retSerial = $retLine->returnedSerial?->serial_number;
                        $repSerial = $retLine->replacementSerial?->serial_number;
                        if ($retSerial && $repSerial) {
                            $idx = array_search($retSerial, $effectiveSerials, true);
                            if ($idx !== false) {
                                $effectiveSerials[$idx] = $repSerial;
                            } else {
                                $foundInBundle = false;
                                foreach ($bundleComposition as &$compItem) {
                                    if (! empty($compItem['serials']) && is_array($compItem['serials'])) {
                                        $compIdx = array_search($retSerial, $compItem['serials'], true);
                                        if ($compIdx !== false) {
                                            $compItem['serials'][$compIdx] = $repSerial;
                                            $compItem['serials'] = array_values($compItem['serials']);
                                            $foundInBundle = true;
                                            break;
                                        }
                                    }
                                }
                                unset($compItem);

                                if (! $foundInBundle) {
                                    throw new PosReprintProjectionException("Nomor seri yang diganti ({$retSerial}) tidak ditemukan pada baris transaksi.");
                                }
                            }
                        }
                    } elseif ($retLine->resolution === PosReturnLine::RESOLUTION_CASH_RETURN) {
                        $retSerial = $retLine->returnedSerial?->serial_number;
                        if ($retSerial) {
                            $idx = array_search($retSerial, $effectiveSerials, true);
                            if ($idx !== false) {
                                array_splice($effectiveSerials, $idx, 1);
                            } else {
                                foreach ($bundleComposition as &$compItem) {
                                    if (! empty($compItem['serials']) && is_array($compItem['serials'])) {
                                        $compIdx = array_search($retSerial, $compItem['serials'], true);
                                        if ($compIdx !== false) {
                                            array_splice($compItem['serials'], $compIdx, 1);
                                            break;
                                        }
                                    }
                                }
                                unset($compItem);
                            }
                        }
                    }
                }
            }

            $priceAdjustments = $this->computeLinePriceAdjustments(
                $txnLine,
                $baseLine,
                $lineDetailsMap[$lineId] ?? [],
                $effectiveQty
            );

            $reprintedLine = $baseLine;
            $reprintedLine['qty'] = $effectiveQty;
            $reprintedLine['price'] = $pricePerUnit;
            $reprintedLine['line_gross'] = $assignedAmount;
            $reprintedLine['sub_total'] = $assignedAmount;
            $reprintedLine['charged_total'] = $assignedAmount;
            $reprintedLine['discount'] = 0.0;
            $reprintedLine['bill_discount'] = 0.0;
            $reprintedLine['unit_breakdown'] = $unitBreakdown;
            $reprintedLine['bundle_composition'] = $bundleComposition;
            $reprintedLine['assigned_serials'] = $effectiveSerials;
            $reprintedLine['price_adjustments'] = $priceAdjustments;

            $reprintedLines[] = $reprintedLine;
        }

        return $reprintedLines;
    }

    /**
     * Build return history payload grouped by completed PosReturn reference.
     */
    protected function buildReturnHistory(
        Collection $completedReturns,
        ?Collection $receiptLines = null,
        array $fullyReturnedLineIds = [],
        array $allDetails = []
    ): array {
        $history = [];
        foreach ($completedReturns as $posReturn) {
            $items = [];
            foreach ($posReturn->lines as $line) {
                if ($line->resolution === PosReturnLine::RESOLUTION_NONE) {
                    continue;
                }

                $resolutionLabel = match ($line->resolution) {
                    PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT => 'Ganti Barang',
                    PosReturnLine::RESOLUTION_CASH_RETURN => 'Retur Kas',
                    default => 'Lainnya',
                };

                $returnedSerial = $line->returnedSerial?->serial_number;
                $replacementSerial = $line->replacementSerial?->serial_number;

                $priceComparison = null;
                $txnLineId = $line->pos_transaction_line_id
                    ? (int) $line->pos_transaction_line_id
                    : ($receiptLines ? $this->resolveTransactionLineIdFromReturnLine($line, $receiptLines, $allDetails) : null);
                if ($txnLineId && ! empty($receiptLines) && in_array($txnLineId, $fullyReturnedLineIds, true)) {
                    $txnLine = $receiptLines->firstWhere('id', $txnLineId);
                    if ($txnLine) {
                        $priceSource = $txnLine->line_meta['price_source'] ?? 'BASE';
                        $isBundle = ! empty($txnLine->line_meta['bundle_items'])
                            || strtoupper((string) ($txnLine->line_meta['price_source'] ?? '')) === 'BUNDLE'
                            || ! empty($line->bundle_parent_sale_detail_id);

                        if ($priceSource !== 'PACKED' && ! $isBundle) {
                            $origUnitPrice = (float) $txnLine->unit_price;
                            $returnUnitPrice = (float) $line->unit_price;
                            $origCents = (int) round($origUnitPrice * 100);
                            $retCents = (int) round($returnUnitPrice * 100);

                            if ($retCents !== $origCents && $retCents > 0) {
                                $origDec = fmod(round($origUnitPrice, 2), 1.0) != 0.0 ? 2 : 0;
                                $retDec = fmod(round($returnUnitPrice, 2), 1.0) != 0.0 ? 2 : 0;
                                $origFormatted = number_format($origUnitPrice, $origDec, ',', '.');
                                $retFormatted = number_format($returnUnitPrice, $retDec, ',', '.');
                                $priceComparison = "Harga satuan saat retur: {$origFormatted} → {$retFormatted}";
                            }
                        }
                    }
                }

                $items[] = [
                    'pos_return_line_id' => (int) $line->id,
                    'pos_transaction_line_id' => $txnLineId,
                    'product_name' => $line->product_name ?: ($line->product?->product_name ?? '-'),
                    'quantity' => (float) $line->quantity,
                    'resolution' => $resolutionLabel,
                    'resolution_code' => $line->resolution,
                    'unit_price' => (float) $line->unit_price,
                    'line_total' => (float) $line->line_total,
                    'returned_serial' => $returnedSerial,
                    'replacement_serial' => $replacementSerial,
                    'price_comparison' => $priceComparison,
                ];
            }

            $totalReturnAmount = round((float) $posReturn->total_amount, 2);

            $actualRefund = 0.0;
            foreach ($posReturn->saleReturns as $saleReturn) {
                $actualRefund += (float) $saleReturn->saleReturnPayments->sum(fn ($p) => (float) $p->amount);
            }
            $actualRefund = round($actualRefund, 2);

            $debtOffset = round(max(0, $totalReturnAmount - $actualRefund), 2);

            $completionDate = $posReturn->settled_at ?? $posReturn->approved_at ?? $posReturn->updated_at ?? $posReturn->created_at;
            $formattedDate = $completionDate ? Carbon::parse($completionDate)->translatedFormat('d M, Y H:i') : '-';

            $history[] = [
                'id' => (int) $posReturn->id,
                'reference' => $posReturn->reference,
                'date' => $formattedDate,
                'status' => 'Selesai',
                'raw_status' => $posReturn->status,
                'items' => $items,
                'total_amount' => $totalReturnAmount,
                'debt_reduction' => $debtOffset,
                'debt_offset' => $debtOffset,
                'actual_refund' => $actualRefund,
            ];
        }

        return $history;
    }

    /**
     * Build reconciled payment breakdown for current return receipts.
     */
    protected function buildReconciledPaymentBreakdown($sales, PosCheckout $checkout, float $retainedPaidAmount): array
    {
        if ($retainedPaidAmount <= 0.001) {
            return [];
        }

        $activePayments = collect($sales)->flatMap(fn ($s) => $s->salePayments ?? [])
            ->filter(fn ($p) => $p instanceof SalePayment && $p->isActive());

        if ($activePayments->isEmpty()) {
            throw new PosReprintProjectionException(
                "Tidak ditemukan bukti pembayaran aktif untuk merekonsiliasi pembayaran yang dipertahankan sebesar {$retainedPaidAmount}."
            );
        }

        $paymentBreakdown = [];
        $grouped = $activePayments->groupBy(function (SalePayment $payment) {
            if ($payment->relationLoaded('paymentMethod') && $payment->paymentMethod) {
                return $payment->paymentMethod->name;
            }

            if (! empty($payment->payment_method_id)) {
                $method = PaymentMethod::find($payment->payment_method_id);
                if ($method) {
                    return $method->name;
                }
            }

            return $payment->payment_method ?: 'Tunai';
        });

        foreach ($grouped as $methodName => $payments) {
            $amount = round((float) $payments->sum(fn ($p) => (float) $p->amount), 2);
            if ($amount > 0) {
                $paymentBreakdown[] = [
                    'method_name' => $methodName,
                    'amount' => $amount,
                ];
            }
        }

        $sumCents = array_sum(array_map(fn ($p) => (int) round($p['amount'] * 100), $paymentBreakdown));
        $targetCents = (int) round($retainedPaidAmount * 100);
        if ($sumCents !== $targetCents) {
            $sumFormatted = number_format($sumCents / 100, 2, '.', '');
            $targetFormatted = number_format($targetCents / 100, 2, '.', '');
            throw new PosReprintProjectionException(
                "Rekonsiliasi rincian pembayaran aktif ({$sumFormatted}) tidak sesuai dengan sisa pembayaran yang dipertahankan ({$targetFormatted})."
            );
        }

        return $paymentBreakdown;
    }

    /**
     * Recompute packed breakdown prices so box and loose sum up to assignedAmount.
     */
    protected function recomputePackedBreakdown(array $breakdown, PosTransactionLine $line, float $assignedAmount): array
    {
        $boxCount = (int) ($breakdown['box_count'] ?? 0);
        $looseCount = (int) ($breakdown['loose_count'] ?? 0);

        $conversionLabel = $breakdown['conversion_unit_label'] ?? 'Dus';
        $baseLabel = $breakdown['base_unit_label'] ?? 'Pcs';

        $totalUnitCount = $boxCount + $looseCount;
        if ($totalUnitCount <= 0) {
            return [];
        }

        $conversionFactor = 1.0;
        if ($line->conversion && (float) $line->conversion->conversion_factor > 0) {
            $conversionFactor = (float) $line->conversion->conversion_factor;
        }

        $lines = [];
        $groupWeights = [];
        if ($boxCount > 0) {
            $groupWeights['box'] = $boxCount * $conversionFactor;
        }
        if ($looseCount > 0) {
            $groupWeights['loose'] = $looseCount;
        }

        $groupCents = $this->allocateCents((int) round($assignedAmount * 100), $groupWeights);
        if ($boxCount > 0) {
            array_push(
                $lines,
                ...$this->formatUnitBreakdown($boxCount, $groupCents['box'], $conversionLabel)
            );
        }
        if ($looseCount > 0) {
            array_push(
                $lines,
                ...$this->formatUnitBreakdown($looseCount, $groupCents['loose'], $baseLabel)
            );
        }

        return $lines;
    }

    /**
     * Split a group amount into whole-cent unit prices that sum to the group total.
     *
     * @return array<int, string>
     */
    private function formatUnitBreakdown(int $unitCount, int $totalCents, string $unitLabel): array
    {
        if ($unitCount <= 0) {
            return [];
        }

        $baseCents = intdiv($totalCents, $unitCount);
        $remainder = $totalCents % $unitCount;
        $lines = [];

        if ($unitCount > $remainder) {
            $lines[] = sprintf(
                "%d %s @ %s",
                $unitCount - $remainder,
                $unitLabel,
                $this->receiptService->formatReceiptCurrency($baseCents / 100)
            );
        }
        if ($remainder > 0) {
            $lines[] = sprintf(
                "%d %s @ %s",
                $remainder,
                $unitLabel,
                $this->receiptService->formatReceiptCurrency(($baseCents + 1) / 100)
            );
        }

        return $lines;
    }

    /**
     * Allocate a total number of cents proportionally, giving leftover cents to
     * the groups with the largest fractional remainders.
     *
     * @param array<string, float|int> $weights
     * @return array<string, int>
     */
    private function allocateCents(int $totalCents, array $weights): array
    {
        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0) {
            return array_fill_keys(array_keys($weights), 0);
        }

        $allocated = [];
        $remainders = [];
        $allocatedCents = 0;
        foreach ($weights as $key => $weight) {
            $exactCents = $totalCents * ($weight / $totalWeight);
            $wholeCents = (int) floor($exactCents);
            $allocated[$key] = $wholeCents;
            $allocatedCents += $wholeCents;
            $remainders[$key] = $exactCents - $wholeCents;
        }

        arsort($remainders, SORT_NUMERIC);
        foreach (array_keys($remainders) as $key) {
            if ($allocatedCents >= $totalCents) {
                break;
            }
            $allocated[$key]++;
            $allocatedCents++;
        }

        return $allocated;
    }

    /**
     * Compute optional explanatory price adjustments for a reprinted POS receipt line.
     *
     * @param PosTransactionLine $txnLine
     * @param array $baseLine
     * @param array<int, SaleDetails> $mappedDetails
     * @param float $effectiveQty
     * @return array<int, string>
     */
    protected function computeLinePriceAdjustments(
        PosTransactionLine $txnLine,
        array $baseLine,
        array $mappedDetails,
        float $effectiveQty
    ): array {
        $adjustments = [];
        $priceSource = $txnLine->line_meta['price_source'] ?? 'BASE';

        // 1. Packed item: check for proven blended unit-price changes
        if ($priceSource === 'PACKED') {
            $origBlendedCents = (int) round(((float) $txnLine->unit_price) * 100);
            $hasBlendedPriceChange = false;

            foreach ($mappedDetails as $detail) {
                if (! empty($detail->pos_transaction_line_id) && (int) $detail->pos_transaction_line_id === (int) $txnLine->id) {
                    $detailCents = (int) round(((float) $detail->unit_price) * 100);
                    if ($detailCents !== $origBlendedCents) {
                        $hasBlendedPriceChange = true;
                        break;
                    }
                }
            }

            if ($hasBlendedPriceChange) {
                $adjustments[] = 'Harga penjualan diperbarui';
            }

            return $adjustments;
        }

        // 2. Bundle lines: exclude internal allocations, do not invent numeric comparisons
        $isBundle = ! empty($txnLine->line_meta['bundle_items'])
            || strtoupper((string) ($txnLine->line_meta['price_source'] ?? '')) === 'BUNDLE'
            || ! empty($baseLine['bundle_composition']);
        if ($isBundle) {
            return [];
        }

        // 3. Standard items: compare original POS unit price against current linked SaleDetails
        $origUnitPrice = (float) $txnLine->unit_price;
        $origCents = (int) round($origUnitPrice * 100);

        // Filter to customer purchase details
        $customerDetails = collect($mappedDetails)->filter(function ($d) use ($txnLine) {
            if (! empty($d->pos_transaction_line_id)) {
                return (int) $d->pos_transaction_line_id === (int) $txnLine->id;
            }
            return (int) $d->product_id === (int) $txnLine->product_id;
        })->values();

        // Must have proven lineage
        if ($customerDetails->isEmpty()) {
            return [];
        }

        // Only active remaining details
        $activeDetails = $customerDetails->filter(fn ($d) => (float) $d->quantity > 0.0001)->values();
        if ($activeDetails->isEmpty()) {
            return [];
        }

        $allDistinctPrices = $activeDetails->map(fn ($d) => (int) round(((float) $d->unit_price) * 100))->unique()->values();

        // Group by current price in cents
        $grouped = $activeDetails->groupBy(fn ($d) => (int) round(((float) $d->unit_price) * 100));

        foreach ($grouped as $currCents => $detailsInGroup) {
            $currCents = (int) $currCents;
            if ($currCents === $origCents) {
                continue; // No price change for this group
            }

            $groupQty = (float) $detailsInGroup->sum(fn ($d) => (float) $d->quantity);
            $currUnitPrice = $currCents / 100;

            $origDec = fmod(round($origUnitPrice, 2), 1.0) != 0.0 ? 2 : 0;
            $currDec = fmod(round($currUnitPrice, 2), 1.0) != 0.0 ? 2 : 0;
            $origFormatted = number_format($origUnitPrice, $origDec, ',', '.');
            $currFormatted = number_format($currUnitPrice, $currDec, ',', '.');

            if ($allDistinctPrices->count() === 1 && abs($groupQty - $effectiveQty) < 0.0001) {
                $adjustments[] = "Harga satuan: {$origFormatted} → {$currFormatted}";
            } else {
                $qtyFormatted = fmod(round($groupQty, 4), 1.0) != 0.0 ? round($groupQty, 2) : (int) $groupQty;
                $adjustments[] = "Harga satuan: {$origFormatted} → {$currFormatted} ({$qtyFormatted})";
            }
        }

        return $adjustments;
    }
}
