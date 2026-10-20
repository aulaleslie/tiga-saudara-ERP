<?php

namespace Modules\Pos\Services;

use App\Scopes\ArchivingScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Exceptions\PosReprintAmbiguityException;
use Modules\Pos\Exceptions\PosReprintProjectionException;
use Modules\Sale\Entities\Sale;

class PosKasBonReviewService
{
    public function __construct(
        protected PosSettlementProjectionService $settlementProjectionService = new PosSettlementProjectionService(),
        protected PosReceiptReprintProjectionService $reprintProjectionService = new PosReceiptReprintProjectionService(),
        protected PosReachableSalesResolver $salesResolver = new PosReachableSalesResolver(),
        protected PosChildSaleEligibilityService $eligibilityService = new PosChildSaleEligibilityService()
    ) {
    }

    /**
     * Get paginated Kas Bon candidates for the active business setting.
     *
     * @param int $settingId Active business ID
     * @param string|null $search Search term over text and monetary columns
     * @param int $perPage Number of items per page
     * @param int $page Current page
     * @return LengthAwarePaginator
     */
    public function getCandidates(int $settingId, ?string $search = null, int $perPage = 15, int $page = 1): LengthAwarePaginator
    {
        $query = PosTransaction::query()
            ->where('pos_transactions.setting_id', $settingId)
            ->where('pos_transactions.status', PosTransaction::STATUS_COMPLETED)
            ->whereHas('completedCheckout', function (Builder $q) {
                $q->where('status', PosCheckout::STATUS_POSTED)
                  ->where(function (Builder $sub) {
                      $sub->where('metadata->is_debt', true)
                          ->orWhere('metadata->is_debt', 'true')
                          ->orWhere('metadata->is_debt', 1)
                          ->orWhere('metadata->is_debt', '1')
                          ->orWhere('metadata->debt_amount', '>', 0)
                          ->orWhereRaw('grand_total > paid_total');
                  });
            })
            ->with(array_merge(
                PosSettlementProjectionService::reachableSalesEagerLoad(),
                ['customer', 'completedCheckout', 'setting']
            ))
            ->orderByDesc('pos_transactions.created_at')
            ->orderByDesc('pos_transactions.id');

        $transactions = $query->get();

        $replacementContext = $this->settlementProjectionService->prepareReplacementSales($transactions);

        $candidates = collect();

        foreach ($transactions as $trx) {
            $checkout = $trx->completedCheckout;
            if (!$checkout) {
                continue;
            }

            // Authoritative debt intent verification
            $isDebtIntent = (bool) ($checkout->metadata['is_debt'] ?? false)
                || ((float) ($checkout->debt_amount ?? 0) > 0.005)
                || ((float) ($checkout->grand_total - $checkout->paid_total) > 0.005);

            if (!$isDebtIntent) {
                continue;
            }

            // Project current settlement
            $projection = $this->settlementProjectionService->project($trx, $replacementContext);

            if (!$projection['is_valid'] || !$projection['is_payable']) {
                continue;
            }

            $liveDue = round((float) $projection['live_due'], 2);
            if ($liveDue <= 0.005) {
                continue; // Paid / zero debt
            }

            // Verify child sales eligibility
            $sales = $projection['sales'] ?? collect();
            $allEligible = true;
            foreach ($sales as $sale) {
                if (!$this->eligibilityService->isEligible($sale)) {
                    $allEligible = false;
                    break;
                }
            }
            if (!$allEligible) {
                continue;
            }

            $originalTotal = round((float) $checkout->grand_total, 2);
            $currentTotal = round((float) $projection['total_amount'], 2);
            $effectivePaid = round((float) $projection['effective_paid'], 2);
            $customerName = $trx->customer ? (string) $trx->customer->customer_name : 'Pelanggan Umum';
            $customerPhone = $trx->customer ? (string) $trx->customer->customer_phone : '';
            $notes = (string) ($trx->note ?? $checkout->note ?? '');

            $item = [
                'pos_transaction_id' => $trx->id,
                'id' => $trx->id,
                'code' => $trx->code,
                'receipt_number' => $checkout->receipt_number,
                'customer_id' => $trx->customer_id,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'notes' => $notes,
                'original_total' => $originalTotal,
                'current_total' => $currentTotal,
                'effective_paid' => $effectivePaid,
                'already_paid' => $effectivePaid,
                'live_due' => $liveDue,
                'remaining_debt' => $liveDue,
                'created_at' => $trx->created_at ? $trx->created_at->toDateTimeString() : null,
                'setting_id' => (int) $trx->setting_id,
            ];

            if ($this->matchesSearch($item, $search)) {
                $candidates->push($item);
            }
        }

        $total = $candidates->count();
        $offset = ($page - 1) * $perPage;
        $itemsForPage = $candidates->slice($offset, $perPage)->values();

        return new LengthAwarePaginator(
            $itemsForPage,
            $total,
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
    }

    /**
     * Check if a candidate item matches search string across all displayed text and monetary columns.
     */
    public function matchesSearch(array $item, ?string $rawSearch): bool
    {
        $search = trim((string) $rawSearch);
        if ($search === '') {
            return true;
        }

        $searchLower = mb_strtolower($search);

        // 1. Check displayed text fields
        $textFields = [
            $item['code'] ?? '',
            $item['receipt_number'] ?? '',
            $item['customer_name'] ?? '',
            $item['customer_phone'] ?? '',
            $item['notes'] ?? '',
        ];

        foreach ($textFields as $tf) {
            if ($tf !== '' && str_contains(mb_strtolower($tf), $searchLower)) {
                return true;
            }
        }

        // 2. Check displayed monetary fields (original_total, current_total, effective_paid, live_due)
        $monetaryFields = [
            'original_total' => (float) ($item['original_total'] ?? 0),
            'current_total' => (float) ($item['current_total'] ?? 0),
            'effective_paid' => (float) ($item['effective_paid'] ?? 0),
            'live_due' => (float) ($item['live_due'] ?? 0),
        ];

        // Clean search term of currency prefixes and symbols (e.g. "Rp", "rp", spaces)
        $cleanSearch = trim(preg_replace('/^rp\.?\s*/i', '', $searchLower));

        // Digits-only search (e.g. "150.000" -> "150000")
        $digitsSearch = preg_replace('/\D/', '', $cleanSearch);

        foreach ($monetaryFields as $fieldVal) {
            $intVal = (int) round($fieldVal);
            $floatVal = round($fieldVal, 2);

            // Representations formatted as displayed in UI:
            $representations = [
                number_format($fieldVal, 0, ',', '.'),
                number_format($fieldVal, 2, ',', '.'),
                number_format($fieldVal, 0, '.', ','),
                number_format($fieldVal, 2, '.', ','),
                'rp ' . number_format($fieldVal, 0, ',', '.'),
                'rp. ' . number_format($fieldVal, 0, ',', '.'),
                'rp ' . number_format($fieldVal, 2, ',', '.'),
            ];

            foreach ($representations as $rep) {
                if (str_contains(mb_strtolower($rep), $searchLower) || ($cleanSearch !== '' && str_contains(mb_strtolower($rep), $cleanSearch))) {
                    return true;
                }
            }

            // Exact canonical integer/float match (e.g. "50000", "50000.00")
            if ($digitsSearch !== '' && ((string) $intVal === $digitsSearch || sprintf('%.2f', $floatVal) === $search)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Review one POS transaction's current bill facts, items, and fingerprint.
     *
     * @param PosTransaction $transaction
     * @return array
     * @throws ValidationException
     */
    public function review(PosTransaction $transaction): array
    {
        if ($transaction->status !== PosTransaction::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'transaction' => "Transaksi POS {$transaction->code} belum berstatus selesai (COMPLETED).",
            ]);
        }

        $checkout = $transaction->completedCheckout
            ?? PosCheckout::where('pos_transaction_id', $transaction->id)->first()
            ?? PosCheckout::find($transaction->completed_checkout_id);

        if (!$checkout || $checkout->status !== PosCheckout::STATUS_POSTED) {
            throw ValidationException::withMessages([
                'transaction' => "Transaksi POS {$transaction->code} tidak memiliki checkout yang berstatus POSTED.",
            ]);
        }

        $isDebtIntent = (bool) ($checkout->metadata['is_debt'] ?? false)
            || ((float) ($checkout->debt_amount ?? 0) > 0.005)
            || ((float) ($checkout->grand_total - $checkout->paid_total) > 0.005);

        if (!$isDebtIntent) {
            throw ValidationException::withMessages([
                'transaction' => "Transaksi POS {$transaction->code} bukan merupakan transaksi Kas Bon.",
            ]);
        }

        // Project current item monetary projection via PosReceiptReprintProjectionService
        try {
            $reprintData = $this->reprintProjectionService->getCompletedReprintReceiptData($checkout);
        } catch (PosReprintAmbiguityException|PosReprintProjectionException $e) {
            throw ValidationException::withMessages([
                'transaction' => "Pemetaan item transaksi tidak dapat diselesaikan secara jelas: " . $e->getMessage(),
            ]);
        }

        $diagnostics = $this->salesResolver->resolveWithDiagnostics($checkout);
        if (!$diagnostics['is_valid'] || $diagnostics['sales']->isEmpty()) {
            throw ValidationException::withMessages([
                'transaction' => "Pemetaan penjualan untuk transaksi {$transaction->code} tidak konsisten atau rusak.",
            ]);
        }

        $sales = $diagnostics['sales'];
        foreach ($sales as $sale) {
            if (!$this->eligibilityService->isEligible($sale)) {
                throw ValidationException::withMessages([
                    'transaction' => "Penjualan {$sale->reference} tidak lagi memenuhi syarat untuk pembayaran baru.",
                ]);
            }
        }

        $currentTotal = round((float) ($reprintData['grand_total'] ?? 0), 2);
        $effectivePaid = round((float) ($reprintData['amount_paid'] ?? 0), 2);
        $liveDue = round((float) ($reprintData['outstanding_debt'] ?? 0), 2);
        $originalTotal = round((float) $checkout->grand_total, 2);

        if ($liveDue <= 0.005) {
            throw ValidationException::withMessages([
                'transaction' => "Transaksi POS {$transaction->code} sudah lunas (sisa utang Rp 0).",
            ]);
        }

        $reviewLines = [];
        foreach ($reprintData['lines'] ?? [] as $line) {
            $adjustments = $line['price_adjustments'] ?? $line['adjustments'] ?? null;
            $subTotal = round((float) ($line['sub_total'] ?? 0), 2);
            $qty = (float) ($line['quantity'] ?? $line['qty'] ?? 0);
            $unitPrice = round((float) ($line['unit_price'] ?? $line['price'] ?? 0), 2);
            $reviewLines[] = [
                'pos_transaction_line_id' => $line['pos_transaction_line_id'] ?? $line['id'] ?? null,
                'product_id' => $line['product_id'] ?? null,
                'product_name' => $line['product_name'] ?? $line['name'] ?? '',
                'product_code' => $line['product_code'] ?? $line['code'] ?? '',
                'quantity' => $qty,
                'qty' => $qty,
                'unit_name' => $line['unit_name'] ?? '',
                'unit_price' => $unitPrice,
                'price' => $unitPrice,
                'sub_total' => $subTotal,
                'subtotal' => $subTotal,
                'adjustments' => $adjustments,
            ];
        }

        $salesContext = [];
        foreach ($sales->sortBy('id') as $sale) {
            $salesContext[] = [
                'sale_id' => $sale->id,
                'sale_reference' => $sale->reference,
                'setting_id' => (int) $sale->setting_id,
                'setting_name' => $sale->tenantSetting ? $sale->tenantSetting->company_name : "Cabang {$sale->setting_id}",
                'total_amount' => round((float) $sale->total_amount, 2),
                'paid_amount' => round((float) $sale->getEffectivePaidAmount(), 2),
                'live_due' => round((float) $sale->live_due_amount, 2),
                'status' => (string) $sale->status,
                'payment_status' => (string) $sale->payment_status,
            ];
        }

        $fingerprintPayload = [
            'pos_transaction_id' => $transaction->id,
            'completed_checkout_id' => $checkout->id,
            'current_total' => sprintf('%.2f', $currentTotal),
            'effective_paid' => sprintf('%.2f', $effectivePaid),
            'live_due' => sprintf('%.2f', $liveDue),
            'lines' => array_map(function ($l) {
                return [
                    'line_id' => $l['pos_transaction_line_id'],
                    'product_id' => $l['product_id'],
                    'quantity' => sprintf('%.4f', $l['quantity']),
                    'unit_price' => sprintf('%.2f', $l['unit_price']),
                    'sub_total' => sprintf('%.2f', $l['sub_total']),
                ];
            }, $reviewLines),
            'sales' => array_map(function ($s) {
                return [
                    'sale_id' => $s['sale_id'],
                    'total_amount' => sprintf('%.2f', $s['total_amount']),
                    'live_due' => sprintf('%.2f', $s['live_due']),
                    'paid_amount' => sprintf('%.2f', $s['paid_amount']),
                ];
            }, $salesContext),
        ];

        $fingerprint = hash('sha256', json_encode($fingerprintPayload));

        return [
            'pos_transaction_id' => $transaction->id,
            'code' => $transaction->code,
            'receipt_number' => $checkout->receipt_number,
            'customer' => [
                'id' => $transaction->customer_id,
                'name' => $transaction->customer?->customer_name ?? $reprintData['customer_name'] ?? 'Pelanggan Umum',
                'customer_name' => $transaction->customer?->customer_name ?? $reprintData['customer_name'] ?? 'Pelanggan Umum',
                'phone' => $transaction->customer?->customer_phone ?? $reprintData['customer_phone'] ?? null,
                'customer_phone' => $transaction->customer?->customer_phone ?? $reprintData['customer_phone'] ?? null,
            ],
            'notes' => $transaction->note ?? $checkout->note,
            'original_total' => $originalTotal,
            'current_total' => $currentTotal,
            'effective_paid' => $effectivePaid,
            'live_due' => $liveDue,
            'lines' => $reviewLines,
            'sales' => $salesContext,
            'fingerprint' => $fingerprint,
            'is_eligible' => true,
        ];
    }
}
