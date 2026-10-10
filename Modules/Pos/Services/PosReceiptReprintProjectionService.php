<?php

namespace Modules\Pos\Services;

use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Exceptions\PosReprintAmbiguityException;
use Modules\Pos\Exceptions\PosReprintProjectionException;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Illuminate\Support\Collection;

class PosReceiptReprintProjectionService
{
    public function __construct(
        protected PosReachableSalesResolver $salesResolver = new PosReachableSalesResolver(),
        protected PosSettlementProjectionService $settlementProjectionService = new PosSettlementProjectionService(),
        protected PosReceiptService $receiptService = new PosReceiptService()
    ) {}

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

        // Verify that sum of assigned line amounts reconciles to currentTotal
        $sumLinesCents = array_sum(array_map(
            fn ($amount) => (int) round((float) $amount * 100),
            $assignedLineAmounts
        ));
        $currentTotalCents = (int) round($currentTotal * 100);
        if ($sumLinesCents !== $currentTotalCents) {
            $sumLines = $sumLinesCents / 100;
            throw new PosReprintProjectionException("Rekonsiliasi total baris ({$sumLines}) berbeda dengan total penjualan ({$currentTotal}).");
        }

        // 7. Rebuild line representations with updated prices & breakdowns
        $reprintedLines = $this->buildReprintedLines($receiptLines, $baseReceiptData['lines'], $assignedLineAmounts);

        // 8. Construct final receipt data
        $reprintedData = $baseReceiptData;
        $reprintedData['lines'] = $reprintedLines;
        $reprintedData['grand_total'] = $currentTotal;
        $reprintedData['subtotal'] = $currentTotal; // Canonical current total matches subtotal before global discounts if already factored
        // Projected line charges are already net of the current Sale's discount, shipping and tax,
        // so the checkout-time discount snapshot must not be deducted again.
        $reprintedData['discount'] = 0.0;
        $reprintedData['tax'] = 0.0;
        $reprintedData['outstanding_debt'] = $currentLiveDue;

        // Preserve checkout tender and change as historical facts
        $reprintedData['amount_paid'] = $checkout->paid_total;
        $reprintedData['change'] = (float) $checkout->change_total;
        $reprintedData['is_reprint_projected'] = true;

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

        // If all details have provenance links, verify they belong to this transaction
        if (empty($unlinkedDetails)) {
            $validLineIds = $lines->pluck('id')->map(fn ($id) => (int) $id)->all();
            foreach (array_keys($lineDetailsMap) as $mappedLineId) {
                if (! in_array($mappedLineId, $validLineIds, true)) {
                    throw new PosReprintProjectionException("Rincian penjualan terhubung ke baris transaksi #{$mappedLineId} di luar transaksi ini.");
                }
            }

            return $lineDetailsMap;
        }

        // If there are unlinked details, apply historical resolution rules (Design Section 3 / Task 3.1)
        return $this->resolveHistoricalMapping($lines, $sales, $allDetails);
    }

    /**
     * Resolve historical unlinked SaleDetails to POS lines or fail safely.
     */
    protected function resolveHistoricalMapping(Collection $lines, $sales, array $allDetails): array
    {
        // Rule 1: Single customer-facing POS line
        if ($lines->count() === 1) {
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
    protected function buildReprintedLines(Collection $receiptLines, array $baseLines, array $assignedLineAmounts): array
    {
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

        foreach ($baseLines as $baseLine) {
            $lineId = (int) $baseLine['pos_transaction_line_id'];
            $txnLine = $linesById->get($lineId);
            if (! $txnLine || ! array_key_exists($lineId, $assignedLineAmounts)) {
                throw new PosReprintProjectionException("Baris struk #{$lineId} tidak memiliki rincian penjualan yang dapat diproyeksikan.");
            }
            $assignedAmount = $assignedLineAmounts[$lineId];

            $qty = (float) $baseLine['qty'];
            $pricePerUnit = $qty > 0 ? round($assignedAmount / $qty, 2) : $assignedAmount;

            // Reconstruct unit breakdown if present
            $unitBreakdown = $baseLine['unit_breakdown'] ?? null;
            if ($txnLine && $unitBreakdown) {
                $priceSource = $txnLine->line_meta['price_source'] ?? 'BASE';
                $breakdown = $txnLine->line_meta['breakdown'] ?? null;

                if ($priceSource === 'PACKED' && is_array($breakdown) && is_array($unitBreakdown)) {
                    // Allocate every cent across the packed and loose units.
                    $unitBreakdown = $this->recomputePackedBreakdown($breakdown, $txnLine, $assignedAmount);
                } elseif (is_string($unitBreakdown)) {
                    // Single unit string format: "2 Box(S) @ Rp. 50.000"
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
                        $wholeQty = (int) round($qty);
                        $unitBreakdown = $wholeQty > 0 && abs($qty - $wholeQty) < 0.000001
                            ? $this->formatUnitBreakdown($wholeQty, (int) round($assignedAmount * 100), $unitName . '(S)')
                            : sprintf(
                                "%s %s(S) @ %s",
                                $qty,
                                $unitName,
                                $this->receiptService->formatReceiptCurrency($pricePerUnit)
                            );
                    }
                }
            }

            $reprintedLine = $baseLine;
            $reprintedLine['price'] = $pricePerUnit;
            $reprintedLine['line_gross'] = $assignedAmount;
            $reprintedLine['sub_total'] = $assignedAmount;
            $reprintedLine['charged_total'] = $assignedAmount;
            $reprintedLine['discount'] = 0.0;
            $reprintedLine['bill_discount'] = 0.0;
            $reprintedLine['unit_breakdown'] = $unitBreakdown;

            $reprintedLines[] = $reprintedLine;
        }

        return $reprintedLines;
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
}
