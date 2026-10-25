<?php

namespace Modules\Pos\Services;

use App\Models\User;
use App\Scopes\ArchivingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnBalanceRepairAudit;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturn;

class PosReturnBalanceRepairService
{
    public const CLASSIFICATION_REPAIRABLE = 'repairable';
    public const CLASSIFICATION_UNCHANGED = 'unchanged';
    public const CLASSIFICATION_BLOCKED = 'blocked';
    public const CLASSIFICATION_REPAIRED = 'repaired';

    public function __construct(
        protected PosReachableSalesResolver $salesResolver = new PosReachableSalesResolver(),
        protected PosSettlementProjectionService $settlementProjectionService = new PosSettlementProjectionService(),
        protected PosReceiptReprintProjectionService $receiptProjectionService = new PosReceiptReprintProjectionService()
    ) {}

    /**
     * Plan and evaluate candidate checkout-group repair by transaction ID or code.
     *
     * @param int|string $transactionIdOrCode
     * @param int|null $settingId
     * @return array<string, mixed>
     */
    public function plan(int|string $transactionIdOrCode, ?int $settingId = null): array
    {
        $query = PosTransaction::query();
        if (is_numeric($transactionIdOrCode)) {
            $query->whereKey((int) $transactionIdOrCode);
        } else {
            $query->where('code', (string) $transactionIdOrCode);
        }

        /** @var PosTransaction|null $transaction */
        $transaction = $query->first();

        if (! $transaction) {
            return [
                'classification' => self::CLASSIFICATION_BLOCKED,
                'reason' => "Transaksi POS '{$transactionIdOrCode}' tidak ditemukan.",
                'block_reasons' => ["Transaksi POS '{$transactionIdOrCode}' tidak ditemukan."],
                'transaction_id' => is_numeric($transactionIdOrCode) ? (int) $transactionIdOrCode : null,
                'transaction_code' => (string) $transactionIdOrCode,
                'sales' => [],
                'summary' => [],
            ];
        }

        if ($settingId !== null && (int) $transaction->setting_id !== (int) $settingId) {
            return [
                'classification' => self::CLASSIFICATION_BLOCKED,
                'reason' => "Transaksi POS #{$transaction->id} bukan milik unit usaha setting #{$settingId} (setting transaksi: #{$transaction->setting_id}).",
                'block_reasons' => ["Transaksi POS #{$transaction->id} bukan milik unit usaha setting #{$settingId}."],
                'transaction_id' => $transaction->id,
                'transaction_code' => $transaction->code,
                'sales' => [],
                'summary' => [],
            ];
        }

        return $this->reconcile($transaction, $settingId);
    }

    /**
     * Reconcile checkout-group evidence in minor units for a POS transaction.
     *
     * @param PosTransaction $transaction
     * @param int|null $settingId
     * @return array<string, mixed>
     */
    public function reconcile(PosTransaction $transaction, ?int $settingId = null): array
    {
        $blockReasons = [];

        // Transaction status check
        if ($transaction->status === PosTransaction::STATUS_DRAFT) {
            $blockReasons[] = "Transaksi POS #{$transaction->id} berstatus DRAFT, bukan transaksi selesai.";
        }

        // Resolve checkout
        /** @var PosCheckout|null $checkout */
        $checkout = $transaction->completedCheckout
            ?? PosCheckout::where('pos_transaction_id', $transaction->id)->first()
            ?? ($transaction->completed_checkout_id ? PosCheckout::find($transaction->completed_checkout_id) : null);

        if (! $checkout) {
            return [
                'classification' => self::CLASSIFICATION_BLOCKED,
                'reason' => "Checkout untuk transaksi POS #{$transaction->id} tidak ditemukan.",
                'block_reasons' => ["Checkout untuk transaksi POS #{$transaction->id} tidak ditemukan."],
                'transaction_id' => $transaction->id,
                'transaction_code' => $transaction->code,
                'sales' => [],
                'summary' => [],
            ];
        }

        // Resolve all transactions in this checkout group using established relationships
        $groupTransactions = PosTransaction::query()
            ->with(['lines.product', 'lines.conversion'])
            ->where(function ($q) use ($checkout, $transaction) {
                $q->where('completed_checkout_id', $checkout->id)
                    ->orWhere('id', $transaction->id);

                if (! empty($checkout->pos_transaction_id)) {
                    $q->orWhere('id', $checkout->pos_transaction_id);
                }
            })
            ->orderBy('id', 'asc')
            ->get();

        $groupTransactionIds = $groupTransactions->pluck('id')->filter()->unique()->values()->all();

        // Check active / incomplete returns on the checkout or any group transaction
        $activeReturns = PosReturn::query()
            ->where(function ($q) use ($checkout, $groupTransactionIds) {
                $q->where('pos_checkout_id', $checkout->id)
                    ->orWhereIn('pos_transaction_id', $groupTransactionIds);
            })
            ->whereNotIn('status', [
                PosReturn::STATUS_COMPLETED,
                PosReturn::STATUS_ARCHIVED,
                PosReturn::STATUS_CANCELLED,
                PosReturn::STATUS_REJECTED,
            ])
            ->get();

        if ($activeReturns->isNotEmpty()) {
            $refs = $activeReturns->pluck('reference')->implode(', ');
            $blockReasons[] = "Terdapat retur aktif/belum selesai pada checkout ini ({$refs}).";
        }

        // Load completed returns
        $completedReturns = PosReturn::query()
            ->with(['lines', 'saleReturns.saleReturnDetails'])
            ->where(function ($q) use ($checkout, $groupTransactionIds) {
                $q->where('pos_checkout_id', $checkout->id)
                    ->orWhereIn('pos_transaction_id', $groupTransactionIds);
            })
            ->where('status', PosReturn::STATUS_COMPLETED)
            ->where('is_reversed', false)
            ->orderBy('id', 'asc')
            ->get();

        $completedReturnIds = $completedReturns->pluck('id')->all();

        foreach ($completedReturns as $completedReturn) {
            if ($completedReturn->return_option !== PosReturn::OPTION_CASH_RETURN) {
                $blockReasons[] = "Terdapat retur selesai dengan opsi non-tunai ('{$completedReturn->return_option}', ref: {$completedReturn->reference}). Hanya retur tunai yang didukung.";
            }
        }

        // Resolve all captured original transaction lines across the checkout group
        $receiptTransactions = $checkout->transactions()->with(['lines.product', 'lines.conversion'])->get();
        $allTransactionLines = $groupTransactions->concat($receiptTransactions)
            ->flatMap(fn ($t) => $t->lines)
            ->unique('id')
            ->values();

        if ($allTransactionLines->isEmpty()) {
            $blockReasons[] = "Bukti baris transaksi asli (PosTransactionLine) tidak ditemukan untuk transaksi ini.";
        }

        // Test receipt reprint projection in planning/preview
        try {
            $this->receiptProjectionService->getCompletedReprintReceiptData($checkout);
        } catch (\Throwable $e) {
            $blockReasons[] = "Proyeksi cetak ulang struk tidak valid: " . $e->getMessage();
        }

        // Resolve reachable sales via PosReachableSalesResolver with diagnostics
        $diagnostics = $this->salesResolver->resolveWithDiagnostics($checkout);
        if (! $diagnostics['is_valid'] || $diagnostics['sales']->isEmpty()) {
            $anomalies = implode('; ', $diagnostics['anomalies'] ?: ['Pemetaan penjualan tidak valid.']);
            $blockReasons[] = "Pemetaan dokumen penjualan tidak valid: {$anomalies}";
        }

        /** @var Collection<int, Sale> $sales */
        $sales = $diagnostics['sales']->sortBy('id')->values();
        $sales->load([
            'saleDetails.product',
            'saleDetails.bundleItems',
            'salePayments',
            'bundleItems.saleDetail',
        ]);

        $allSaleDetails = $sales->flatMap->saleDetails;
        $allReturnLines = $completedReturns->flatMap->lines;

        // Perform row-level verification across all captured lines
        if ($allTransactionLines->isNotEmpty()) {
            foreach ($allSaleDetails as $detail) {
                $hasMatch = false;
                if (! empty($detail->pos_transaction_line_id)) {
                    $hasMatch = $allTransactionLines->contains('id', (int) $detail->pos_transaction_line_id);
                } else {
                    $matched = $allTransactionLines->filter(fn ($l) => (int) $l->product_id === (int) $detail->product_id);
                    $hasMatch = ($matched->count() === 1);
                }

                if (! $hasMatch) {
                    $blockReasons[] = "Sale #{$detail->sale_id}, baris #{$detail->id} ({$detail->product_name}): tidak dapat dipetakan ke baris transaksi asli secara unik.";
                }
            }

            foreach ($allTransactionLines as $txnLine) {
                $mappedDetails = $allSaleDetails->filter(function ($d) use ($txnLine, $allTransactionLines) {
                    if (! empty($d->pos_transaction_line_id)) {
                        return (int) $d->pos_transaction_line_id === (int) $txnLine->id;
                    }
                    $matches = $allTransactionLines->filter(fn ($l) => (int) $l->product_id === (int) $d->product_id);
                    return $matches->count() === 1 && (int) $matches->first()->id === (int) $txnLine->id;
                });

                if ($mappedDetails->isEmpty()) {
                    $blockReasons[] = "Baris transaksi #{$txnLine->id} ({$txnLine->product_name_snapshot}): tidak memiliki rincian penjualan yang terhubung.";
                    continue;
                }

                $mappedDetailIds = $mappedDetails->pluck('id')->all();

                $mappedReturnLines = $allReturnLines->filter(function ($rl) use ($txnLine, $mappedDetailIds, $allTransactionLines) {
                    if (! empty($rl->pos_transaction_line_id)) {
                        return (int) $rl->pos_transaction_line_id === (int) $txnLine->id;
                    }
                    if (! empty($rl->sale_detail_id) && in_array((int) $rl->sale_detail_id, $mappedDetailIds, true)) {
                        return true;
                    }
                    $matches = $allTransactionLines->filter(fn ($l) => (int) $l->product_id === (int) $rl->product_id);
                    return $matches->count() === 1 && (int) $matches->first()->id === (int) $txnLine->id;
                });

                $cashReturnLines = $mappedReturnLines->filter(function ($rl) {
                    if ($rl->resolution === PosReturnLine::RESOLUTION_NONE) {
                        return false;
                    }
                    if (! empty($rl->resolution)) {
                        return $rl->resolution === PosReturnLine::RESOLUTION_CASH_RETURN;
                    }
                    return (float) ($rl->line_total ?? $rl->expected_cash_amount ?? 0) > 0 || ! empty($rl->sale_return_detail_id);
                });

                $unsupportedReturnLines = $mappedReturnLines->filter(function ($rl) {
                    return ! empty($rl->resolution)
                        && ! in_array($rl->resolution, [PosReturnLine::RESOLUTION_CASH_RETURN, PosReturnLine::RESOLUTION_NONE], true);
                });
                if ($unsupportedReturnLines->isNotEmpty()) {
                    $unsupportedRes = $unsupportedReturnLines->pluck('resolution')->unique()->implode(', ');
                    $blockReasons[] = "Baris transaksi #{$txnLine->id} ({$txnLine->product_name_snapshot}) memiliki baris retur dengan resolusi non-tunai ('{$unsupportedRes}').";
                }

                $origQty = (float) $txnLine->qty;
                $origAmounts = PosTransactionLineAmountResolver::resolve($txnLine);
                $origCents = (int) round($origAmounts['charged_total'] * 100);

                $storedQty = (float) $mappedDetails->sum(fn ($d) => (float) ($d->quantity ?? 0));
                $storedCents = (int) round($mappedDetails->sum(fn ($d) => (float) ($d->sub_total ?? 0)) * 100);

                $returnedQty = (float) $cashReturnLines->sum(fn ($rl) => (float) ($rl->quantity ?? 0));
                $returnedCents = (int) round($cashReturnLines->sum(fn ($rl) => (float) ($rl->line_total ?? $rl->expected_cash_amount ?? 0)) * 100);

                // Row-level quantity invariant: Original Qty == Stored Remaining Qty + Returned Qty
                $accountedQty = $storedQty + $returnedQty;
                if (abs($origQty - $accountedQty) > 0.0001) {
                    $blockReasons[] = "Baris transaksi #{$txnLine->id} ({$txnLine->product_name_snapshot}): kuantitas awal ({$origQty}) tidak sama dengan sisa tersimpan ({$storedQty}) ditambah retur ({$returnedQty}).";
                }

                // Row-level monetary invariant: Original Subtotal Cents == Stored Remaining Cents + Returned Cents
                $accountedCents = $storedCents + $returnedCents;
                if ($origCents !== $accountedCents) {
                    $diffCents = abs($origCents - $accountedCents);
                    $isBlendedUnitPriceDrift = false;

                    if ($origQty > 0) {
                        $reconstructedUnitPrice = round(($accountedCents / 100) / $origQty, 2);
                        $txnUnitPrice = round((float) $txnLine->unit_price, 2);
                        $expectedReconstructedCents = (int) round($reconstructedUnitPrice * $origQty * 100);

                        if ($reconstructedUnitPrice === $txnUnitPrice && $origCents === $expectedReconstructedCents) {
                            $isBlendedUnitPriceDrift = true;
                        }
                    }

                    if (! $isBlendedUnitPriceDrift) {
                        $diff = $diffCents / 100;
                        $blockReasons[] = "Baris transaksi #{$txnLine->id} ({$txnLine->product_name_snapshot}): nilai awal (" . ($origCents / 100) . ") tidak sama dengan sisa tersimpan (" . ($storedCents / 100) . ") ditambah retur (" . ($returnedCents / 100) . ", selisih: {$diff}).";
                    }
                }
            }
        }

        $salesPlan = [];
        $hasDrift = false;

        $totalCapturedCents = 0;
        $totalCommittedReturnCents = 0;
        $totalStoredRemainingCents = 0;
        $totalCurrentGrandCents = 0;
        $totalProposedGrandCents = 0;
        $totalPaidCents = 0;
        $totalCurrentDueCents = 0;
        $totalProposedDueCents = 0;

        $checkoutSales = PosCheckoutSale::query()
            ->where('pos_checkout_id', $checkout->id)
            ->orderBy('id', 'asc')
            ->get();

        $allSaleReturns = SaleReturn::query()
            ->whereIn('sale_id', $sales->pluck('id'))
            ->with('saleReturnDetails')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($sales as $sale) {
            // Filter active details
            $activeDetails = $sale->saleDetails
                ->filter(fn ($d) => (float) ($d->quantity ?? 0) > 0.0001)
                ->values();

            // Supplemental bundle items (if any)
            $supplementalBundleItems = $sale->bundleItems
                ->filter(function ($bundleItem) {
                    $saleDetail = $bundleItem->saleDetail;
                    return (! $saleDetail || (float) ($saleDetail->quantity ?? 0) <= 0)
                        && ((float) ($bundleItem->quantity ?? 0) > 0 || (float) ($bundleItem->sub_total ?? 0) > 0);
                })
                ->values();

            // Stored remaining detail subtotals in cents
            $activeSubtotalCents = (int) round($activeDetails->sum(fn ($d) => (float) $d->sub_total) * 100);
            $supplementalSubtotalCents = (int) round($supplementalBundleItems->sum(fn ($b) => (float) $b->sub_total) * 100);
            $storedRemainingCents = $activeSubtotalCents + $supplementalSubtotalCents;

            // Determine original captured charge for this owner sale
            /** @var PosCheckoutSale|null $checkoutSale */
            $checkoutSale = $checkoutSales->firstWhere('sale_id', $sale->id);

            if ($checkoutSale) {
                $originalChargeCents = (int) round(((float) $checkoutSale->grand_total) * 100);
            } elseif ($sales->count() === 1) {
                $originalChargeCents = (int) round(((float) $checkout->grand_total) * 100);
            } else {
                $blockReasons[] = "Data alokasi penjualan checkout (PosCheckoutSale) tidak ditemukan untuk sale #{$sale->id}.";
                $originalChargeCents = 0;
            }

            // Committed cash returns for this sale - verify correspondence, cash type, and status
            $saleReturnsForSale = $allSaleReturns->where('sale_id', $sale->id);

            $validSaleReturns = collect();
            foreach ($saleReturnsForSale as $sr) {
                $statusUpper = strtoupper((string) $sr->status);
                if ($statusUpper !== 'COMPLETED') {
                    $blockReasons[] = "Sale #{$sale->id} memiliki retur penjualan #{$sr->id} ({$sr->reference}) dengan status bukan 'Completed' ('{$sr->status}').";
                    continue;
                }

                if ($sr->approval_status) {
                    $approvalUpper = strtoupper((string) $sr->approval_status);
                    if (in_array($approvalUpper, ['REJECTED', 'CANCELLED'], true)) {
                        $blockReasons[] = "Sale #{$sale->id} memiliki retur penjualan #{$sr->id} ({$sr->reference}) dengan status persetujuan '{$sr->approval_status}'.";
                        continue;
                    }
                }

                if (! $sr->pos_return_id || ! in_array((int) $sr->pos_return_id, $completedReturnIds, true)) {
                    $blockReasons[] = "Sale #{$sale->id} memiliki retur penjualan #{$sr->id} ({$sr->reference}) yang tidak terhubung dengan PosReturn tunai selesai yang valid.";
                    continue;
                }

                $validSaleReturns->push($sr);
            }

            $committedReturnCents = (int) round($validSaleReturns->sum(fn ($sr) => (float) $sr->total_amount) * 100);
            $expectedRemainingCents = $originalChargeCents - $committedReturnCents;

            // Gate: Captured charge minus committed returns must match stored remaining rows
            if ($originalChargeCents > 0 && $expectedRemainingCents !== $storedRemainingCents) {
                $diff = abs($expectedRemainingCents - $storedRemainingCents) / 100;
                $blockReasons[] = "Sale #{$sale->id}: alokasi awal dikurangi retur tunai (" . ($expectedRemainingCents / 100) . ") tidak sama dengan jumlah baris tersimpan (" . ($storedRemainingCents / 100) . ", selisih: {$diff}).";
            }

            // Gate: Unexplained discount or shipping adjustments
            if ((float) ($sale->discount_amount ?? 0) > 0.001) {
                $blockReasons[] = "Sale #{$sale->id} memiliki diskon faktur (" . number_format($sale->discount_amount, 2) . ") yang tidak didukung untuk balance repair.";
            }
            if ((float) ($sale->shipping_amount ?? 0) > 0.001) {
                $blockReasons[] = "Sale #{$sale->id} memiliki biaya pengiriman faktur (" . number_format($sale->shipping_amount, 2) . ") yang tidak didukung untuk balance repair.";
            }

            // Paid amount and overpayment gate
            $paidCents = (int) round(((float) $sale->paid_amount) * 100);
            if ($paidCents > $expectedRemainingCents && $expectedRemainingCents > 0) {
                $blockReasons[] = "Sale #{$sale->id} terdeteksi overpayment: pembayaran (" . ($paidCents / 100) . ") melebihi sisa kewajiban (" . ($expectedRemainingCents / 100) . ").";
            }

            // Header drift check
            $currentTotalCents = (int) round(((float) $sale->total_amount) * 100);
            $driftCents = $expectedRemainingCents - $currentTotalCents;

            if ($driftCents !== 0) {
                // Verify historical unit-price reconstruction
                // Historical return calculation summed rounded unit_price * quantity:
                $reconstructed1 = (int) round($activeDetails->sum(function ($d) {
                    $up = round((float) ($d->unit_price ?: $d->price), 2);
                    return round($up * (float) $d->quantity, 2);
                }) * 100) + $supplementalSubtotalCents;

                // Or using displayed rounded unit price (sub_total / qty):
                $reconstructed2 = (int) round($activeDetails->sum(function ($d) {
                    $qty = (float) $d->quantity;
                    if ($qty <= 0) return 0.0;
                    $up = round(((float) $d->sub_total) / $qty, 2);
                    return round($up * $qty, 2);
                }) * 100) + $supplementalSubtotalCents;

                if ($currentTotalCents === $reconstructed1 || $currentTotalCents === $reconstructed2) {
                    $hasDrift = true;
                } else {
                    $driftRupiah = $driftCents / 100;
                    $blockReasons[] = "Sale #{$sale->id}: perbedaan header ({$driftRupiah}) tidak dapat dibuktikan dari rekonstruksi harga satuan historis.";
                }
            }

            // Proposed values for this sale
            $proposedTotalAmount = round($expectedRemainingCents / 100, 2);
            $proposedPaidAmount = (float) $sale->paid_amount;
            $proposedDueAmount = max(0.0, round(($expectedRemainingCents - $paidCents) / 100, 2));

            $isPaid = $paidCents >= $expectedRemainingCents;
            $isPartial = $paidCents > 0;
            $saleStatusIsUpper = ctype_upper(str_replace([' ', '_'], '', (string) $sale->payment_status));
            if ($saleStatusIsUpper) {
                $proposedPaymentStatus = $isPaid ? 'PAID' : ($isPartial ? 'PARTIAL' : 'UNPAID');
            } else {
                $proposedPaymentStatus = $isPaid ? 'Paid' : ($isPartial ? 'Partial' : 'Unpaid');
            }

            $currentDueCents = (int) round(((float) $sale->due_amount) * 100);
            $proposedDueCents = max(0, $expectedRemainingCents - $paidCents);

            $totalCapturedCents += $originalChargeCents;
            $totalCommittedReturnCents += $committedReturnCents;
            $totalStoredRemainingCents += $storedRemainingCents;
            $totalCurrentGrandCents += $currentTotalCents;
            $totalProposedGrandCents += ($driftCents !== 0 ? $expectedRemainingCents : $currentTotalCents);
            $totalPaidCents += $paidCents;
            $totalCurrentDueCents += $currentDueCents;
            $totalProposedDueCents += $proposedDueCents;

            $salesPlan[] = [
                'sale_id' => $sale->id,
                'reference' => $sale->reference,
                'source_setting_id' => $sale->setting_id,
                'has_drift' => ($driftCents !== 0),
                'drift_cents' => $driftCents,
                'drift_amount' => round($driftCents / 100, 2),
                'original_charge_cents' => $originalChargeCents,
                'committed_return_cents' => $committedReturnCents,
                'stored_remaining_cents' => $storedRemainingCents,
                'current' => [
                    'total_amount' => (float) $sale->total_amount,
                    'paid_amount' => (float) $sale->paid_amount,
                    'due_amount' => (float) $sale->due_amount,
                    'payment_status' => (string) $sale->payment_status,
                ],
                'proposed' => [
                    'total_amount' => $proposedTotalAmount,
                    'paid_amount' => $proposedPaidAmount,
                    'due_amount' => $proposedDueAmount,
                    'payment_status' => $proposedPaymentStatus,
                ],
                'details' => $activeDetails->map(fn ($d) => [
                    'detail_id' => $d->id,
                    'product_id' => $d->product_id,
                    'product_name' => $d->product?->product_name ?? $d->product_name ?? '',
                    'quantity' => (float) $d->quantity,
                    'unit_price' => (float) ($d->unit_price ?: $d->price),
                    'sub_total' => (float) $d->sub_total,
                ])->all(),
            ];
        }

        // Determine classification
        if (! empty($blockReasons)) {
            $classification = self::CLASSIFICATION_BLOCKED;
            $reason = implode('; ', $blockReasons);
        } elseif ($hasDrift) {
            $classification = self::CLASSIFICATION_REPAIRABLE;
            $reason = null;
        } else {
            $classification = self::CLASSIFICATION_UNCHANGED;
            $reason = null;
        }

        // Build deterministic evidence fingerprint containing all decision inputs
        $fingerprintPayload = [
            'checkout_id' => $checkout->id,
            'checkout_grand_total' => (float) $checkout->grand_total,
            'checkout_updated_at' => (string) $checkout->updated_at,
            'checkout_sales' => $checkoutSales->sortBy('id')->values()->map(fn ($cs) => [
                'id' => $cs->id,
                'pos_checkout_id' => $cs->pos_checkout_id,
                'sale_id' => $cs->sale_id,
                'source_setting_id' => $cs->source_setting_id,
                'source_location_id' => $cs->source_location_id,
                'grand_total' => (float) $cs->grand_total,
                'paid_amount' => (float) ($cs->paid_amount ?? 0),
                'due_amount' => (float) ($cs->due_amount ?? 0),
                'split_key' => (string) ($cs->split_key ?? ''),
                'tax_bucket' => (string) ($cs->tax_bucket ?? ''),
            ])->all(),
            'transactions' => $groupTransactions->sortBy('id')->values()->map(fn ($t) => [
                'id' => $t->id,
                'code' => $t->code,
                'status' => $t->status,
                'updated_at' => (string) $t->updated_at,
            ])->all(),
            'transaction_lines' => $allTransactionLines->sortBy('id')->values()->map(function ($l) {
                $amounts = PosTransactionLineAmountResolver::resolve($l);
                return [
                    'id' => $l->id,
                    'pos_transaction_id' => $l->pos_transaction_id,
                    'product_id' => $l->product_id,
                    'qty' => (float) $l->qty,
                    'unit_price' => (float) $l->unit_price,
                    'subtotal' => (float) $l->subtotal,
                    'line_total' => (float) ($l->line_total ?? 0),
                    'line_total_minor' => (int) ($l->line_total_minor ?? 0),
                    'charged_total' => (float) $amounts['charged_total'],
                ];
            })->all(),
            'sales' => $sales->sortBy('id')->values()->map(fn ($s) => [
                'id' => $s->id,
                'total_amount' => (float) $s->total_amount,
                'paid_amount' => (float) $s->paid_amount,
                'due_amount' => (float) $s->due_amount,
                'status' => $s->status,
                'payment_status' => $s->payment_status,
                'updated_at' => (string) $s->updated_at,
                'details' => $s->saleDetails->sortBy('id')->values()->map(fn ($d) => [
                    'id' => $d->id,
                    'pos_transaction_line_id' => $d->pos_transaction_line_id,
                    'product_id' => $d->product_id,
                    'qty' => (float) $d->quantity,
                    'sub_total' => (float) $d->sub_total,
                    'price' => (float) $d->price,
                    'unit_price' => (float) $d->unit_price,
                ])->all(),
            ])->all(),
            'returns' => $completedReturns->sortBy('id')->values()->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'return_option' => $r->return_option,
                'total_amount' => (float) $r->total_amount,
                'updated_at' => (string) $r->updated_at,
                'lines' => $r->lines->sortBy('id')->values()->map(fn ($rl) => [
                    'id' => $rl->id,
                    'pos_checkout_sale_id' => $rl->pos_checkout_sale_id,
                    'pos_transaction_line_id' => $rl->pos_transaction_line_id,
                    'sale_id' => $rl->sale_id,
                    'sale_detail_id' => $rl->sale_detail_id,
                    'product_id' => $rl->product_id,
                    'resolution' => (string) $rl->resolution,
                    'quantity' => (float) $rl->quantity,
                    'unit_price' => (float) $rl->unit_price,
                    'line_total' => (float) ($rl->line_total ?? 0),
                    'expected_cash_amount' => (float) ($rl->expected_cash_amount ?? 0),
                    'stock_behavior' => (string) $rl->stock_behavior,
                ])->all(),
            ])->all(),
            'sale_returns' => $allSaleReturns->sortBy('id')->values()->map(fn ($sr) => [
                'id' => $sr->id,
                'sale_id' => $sr->sale_id,
                'pos_return_id' => $sr->pos_return_id,
                'reference' => $sr->reference,
                'total_amount' => (float) $sr->total_amount,
                'paid_amount' => (float) $sr->paid_amount,
                'due_amount' => (float) $sr->due_amount,
                'status' => (string) $sr->status,
                'approval_status' => (string) ($sr->approval_status ?? ''),
                'details' => $sr->saleReturnDetails->sortBy('id')->values()->map(fn ($srd) => [
                    'id' => $srd->id,
                    'product_id' => $srd->product_id,
                    'sale_detail_id' => $srd->sale_detail_id,
                    'quantity' => (float) $srd->quantity,
                    'price' => (float) $srd->price,
                    'unit_price' => (float) $srd->unit_price,
                    'sub_total' => (float) $srd->sub_total,
                ])->all(),
            ])->all(),
        ];
        $evidenceFingerprint = hash('sha256', json_encode($fingerprintPayload));

        // Receipt projection checks if eligible
        $currentReceiptTotal = null;
        $expectedReceiptTotal = null;
        if ($classification !== self::CLASSIFICATION_BLOCKED) {
            try {
                $settlement = $this->settlementProjectionService->project($transaction);
                $currentReceiptTotal = round((float) $settlement['total_amount'], 2);
            } catch (\Throwable $e) {
                // Receipt projection issue
            }
            $expectedReceiptTotal = round($totalProposedGrandCents / 100, 2);
        }

        return [
            'classification' => $classification,
            'reason' => $reason,
            'block_reasons' => $blockReasons,
            'transaction_id' => $transaction->id,
            'transaction_code' => $transaction->code,
            'checkout_id' => $checkout->id,
            'group_transaction_ids' => $groupTransactionIds,
            'evidence_fingerprint' => $evidenceFingerprint,
            'sales' => $salesPlan,
            'returns' => $completedReturns->map(fn ($r) => [
                'id' => $r->id,
                'reference' => $r->reference,
                'return_option' => $r->return_option,
                'status' => $r->status,
                'total_amount' => (float) $r->total_amount,
                'lines_count' => $r->lines->count(),
            ])->all(),
            'summary' => [
                'captured_original_total' => round($totalCapturedCents / 100, 2),
                'committed_cash_return_total' => round($totalCommittedReturnCents / 100, 2),
                'stored_remaining_row_total' => round($totalStoredRemainingCents / 100, 2),
                'current_grand_total' => round($totalCurrentGrandCents / 100, 2),
                'proposed_grand_total' => round($totalProposedGrandCents / 100, 2),
                'paid_total' => round($totalPaidCents / 100, 2),
                'current_due_total' => round($totalCurrentDueCents / 100, 2),
                'proposed_due_total' => round($totalProposedDueCents / 100, 2),
                'current_receipt_total' => $currentReceiptTotal,
                'expected_receipt_total' => $expectedReceiptTotal,
            ],
        ];
    }

    /**
     * Atomically apply repair under deterministic hierarchical row locks.
     *
     * @param int|string $transactionIdOrCode
     * @param int|null $settingId
     * @param int|null $actorId
     * @param string|null $reason
     * @param string|null $expectedFingerprint
     * @return array<string, mixed>
     */
    public function apply(
        int|string $transactionIdOrCode,
        ?int $settingId = null,
        ?int $actorId = null,
        ?string $reason = null,
        ?string $expectedFingerprint = null
    ): array {
        if ($actorId === null || $actorId <= 0) {
            throw new \InvalidArgumentException('Valid actor ID is strictly required to apply repair.');
        }

        $actor = User::query()->find($actorId);
        if (! $actor) {
            throw new \InvalidArgumentException("Actor user ID {$actorId} does not exist.");
        }

        if ($reason === null || trim($reason) === '') {
            throw new \InvalidArgumentException('A non-empty audit reason is strictly required to apply repair.');
        }

        // Initial preview to identify lock targets
        $previewPlan = $this->plan($transactionIdOrCode, $settingId);

        if ($expectedFingerprint !== null && ! hash_equals($previewPlan['evidence_fingerprint'], (string) $expectedFingerprint)) {
            throw new \RuntimeException("Evidence fingerprint mismatch before lock! Expected: {$expectedFingerprint}, Actual: {$previewPlan['evidence_fingerprint']}");
        }

        if ($previewPlan['classification'] === self::CLASSIFICATION_BLOCKED) {
            return $previewPlan;
        }

        if ($previewPlan['classification'] === self::CLASSIFICATION_UNCHANGED) {
            return $previewPlan;
        }

        $transactionId = (int) $previewPlan['transaction_id'];
        $checkoutId = (int) $previewPlan['checkout_id'];
        $groupTrxIds = $previewPlan['group_transaction_ids'];
        sort($groupTrxIds);

        $saleIds = array_column($previewPlan['sales'], 'sale_id');
        sort($saleIds);

        $returnIds = array_column($previewPlan['returns'], 'id');
        sort($returnIds);

        return DB::transaction(function () use (
            $transactionId,
            $checkoutId,
            $groupTrxIds,
            $saleIds,
            $returnIds,
            $settingId,
            $actorId,
            $reason,
            $expectedFingerprint
        ) {
            // 1. Lock PosTransactions in ASC order
            $lockedTransactions = PosTransaction::query()
                ->whereIn('id', $groupTrxIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var PosTransaction|null $lockedTrx */
            $lockedTrx = $lockedTransactions->get($transactionId);
            if (! $lockedTrx) {
                throw new \RuntimeException("Transaction #{$transactionId} not found under lock.");
            }

            // 2. Lock PosCheckout
            /** @var PosCheckout|null $lockedCheckout */
            $lockedCheckout = PosCheckout::query()
                ->whereKey($checkoutId)
                ->lockForUpdate()
                ->first();

            if (! $lockedCheckout) {
                throw new \RuntimeException("Checkout #{$checkoutId} not found under lock.");
            }

            // 3. Lock PosReturns in ASC order
            if (! empty($returnIds)) {
                PosReturn::query()
                    ->whereIn('id', $returnIds)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();
            }

            // 4. Lock Sales in ASC order
            $lockedSales = Sale::withoutGlobalScope(ArchivingScope::class)
                ->whereIn('id', $saleIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedSales->count() !== count($saleIds)) {
                throw new \RuntimeException("Failed to lock all reachable sales for transaction #{$transactionId}.");
            }

            // Re-read and re-evaluate complete plan under lock
            $lockedPlan = $this->reconcile($lockedTrx, $settingId);

            if ($expectedFingerprint !== null && ! hash_equals($lockedPlan['evidence_fingerprint'], (string) $expectedFingerprint)) {
                throw new \RuntimeException("Evidence changed under lock! Expected: {$expectedFingerprint}, Actual: {$lockedPlan['evidence_fingerprint']}");
            }

            if ($lockedPlan['classification'] === self::CLASSIFICATION_BLOCKED) {
                throw new \RuntimeException("Repair blocked under lock: " . $lockedPlan['reason']);
            }

            if ($lockedPlan['classification'] === self::CLASSIFICATION_UNCHANGED) {
                return $lockedPlan;
            }

            // Apply updates to sales with drift
            foreach ($lockedPlan['sales'] as $salePlan) {
                if (! $salePlan['has_drift']) {
                    continue;
                }

                /** @var Sale $saleModel */
                $saleModel = $lockedSales->get($salePlan['sale_id']);
                if (! $saleModel) {
                    throw new \RuntimeException("Sale #{$salePlan['sale_id']} missing from locked collection.");
                }

                $beforeValues = $salePlan['current'];
                $afterValues = $salePlan['proposed'];

                // Update sale header preserving paid_amount
                $saleModel->forceFill([
                    'total_amount' => $afterValues['total_amount'],
                    'due_amount' => $afterValues['due_amount'],
                    'payment_status' => $afterValues['payment_status'],
                ])->save();

                // Persist structured audit record
                PosReturnBalanceRepairAudit::create([
                    'pos_transaction_id' => $lockedTrx->id,
                    'pos_checkout_id' => $lockedCheckout->id,
                    'sale_id' => $saleModel->id,
                    'actor_id' => $actorId,
                    'reason' => $reason,
                    'evidence_fingerprint' => $lockedPlan['evidence_fingerprint'],
                    'before_values' => $beforeValues,
                    'after_values' => $afterValues,
                ]);
            }

            // Fresh receipt projection check inside transaction
            $lockedTrx->refresh();
            $lockedCheckout->refresh();

            $settlement = $this->settlementProjectionService->project($lockedTrx);
            if (! $settlement['is_valid']) {
                throw new \RuntimeException("Receipt settlement projection failed after repair.");
            }

            $expectedGrandTotal = $lockedPlan['summary']['proposed_grand_total'];
            $expectedDueTotal = $lockedPlan['summary']['proposed_due_total'];

            if (abs(round((float) $settlement['total_amount'], 2) - $expectedGrandTotal) > 0.005) {
                throw new \RuntimeException(sprintf(
                    "Receipt settlement total mismatch after repair: expected %.2f, projected %.2f",
                    $expectedGrandTotal,
                    (float) $settlement['total_amount']
                ));
            }

            if (abs(round((float) $settlement['live_due'], 2) - $expectedDueTotal) > 0.005) {
                throw new \RuntimeException(sprintf(
                    "Receipt settlement due mismatch after repair: expected %.2f, projected %.2f",
                    $expectedDueTotal,
                    (float) $settlement['live_due']
                ));
            }

            // Run actual receipt reprint projection inside transaction
            try {
                $reprintData = $this->receiptProjectionService->getCompletedReprintReceiptData($lockedCheckout);
            } catch (\Throwable $e) {
                throw new \RuntimeException("Receipt reprint projection failed after repair: " . $e->getMessage(), 0, $e);
            }

            if (abs(round((float) ($reprintData['grand_total'] ?? 0), 2) - $expectedGrandTotal) > 0.005) {
                throw new \RuntimeException(sprintf(
                    "Receipt reprint total mismatch after repair: expected %.2f, projected %.2f",
                    $expectedGrandTotal,
                    (float) ($reprintData['grand_total'] ?? 0)
                ));
            }

            if (abs(round((float) ($reprintData['outstanding_debt'] ?? 0), 2) - $expectedDueTotal) > 0.005) {
                throw new \RuntimeException(sprintf(
                    "Receipt reprint debt mismatch after repair: expected %.2f, projected %.2f",
                    $expectedDueTotal,
                    (float) ($reprintData['outstanding_debt'] ?? 0)
                ));
            }

            // Return freshly re-read plan showing repaired state
            $repairedPlan = $this->reconcile($lockedTrx, $settingId);
            $repairedPlan['classification'] = self::CLASSIFICATION_REPAIRED;
            $repairedPlan['actor_id'] = $actorId;
            $repairedPlan['reason'] = $reason;

            return $repairedPlan;
        });
    }
}
