<?php

namespace Modules\Pos\Services;

use App\Models\User;
use App\Scopes\ArchivingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosOwnerPaymentTransfer;
use Modules\Pos\Entities\PosReplacementAccountingRepairAudit;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Setting\Entities\Setting;

/**
 * Dedicated planner, discovery engine, and atomic repair executor for historical
 * cross-owner POS product replacement accounting duplication.
 *
 * Scope & Safety Contract:
 * - Strictly separate from `PosReturnBalanceRepairService` (which repairs cash-only return header drift).
 * - Requires an explicit business setting scope (`--setting`).
 * - Discovery (`discoverCandidates`) and preview (`plan`) are 100% read-only.
 * - Apply (`apply`) requires an explicit transaction selection, valid actor, non-empty reason,
 *   and reviewed `evidence_fingerprint`, revalidates under deterministic hierarchical row locks,
 *   invalidates only proven synthetic `CROSS-OWNER-REPL/` payments, creates `PosOwnerPaymentTransfer`
 *   allocations from original customer payments, updates affected Sale headers, records a
 *   `PosReplacementAccountingRepairAudit`, and verifies normal settlement and receipt projection before commit.
 * - Preserves original customer `SalePayment` rows, `SaleDetails` quantities/subtotals/selling prices,
 *   `PosReturn`/`SaleReturn` values, checkout snapshots, stock movements, HPP/cost snapshots, and serial history.
 */
class PosReplacementAccountingRepairService
{
    /** Transaction attempts when MySQL chooses this apply as a deadlock victim (rolled back atomically before each retry). */
    protected const DEADLOCK_RETRY_ATTEMPTS = 3;

    public const CLASSIFICATION_REPAIRABLE = 'repairable';
    public const CLASSIFICATION_UNCHANGED = 'unchanged';
    public const CLASSIFICATION_BLOCKED = 'blocked';
    public const CLASSIFICATION_REPAIRED = 'repaired';

    public const INVALIDATION_SOURCE = 'POS_REPLACEMENT_ACCOUNTING_REPAIR';

    public function __construct(
        protected PosReachableSalesResolver $salesResolver = new PosReachableSalesResolver(),
        protected PosReplacementAccountingTransferService $transferService = new PosReplacementAccountingTransferService(),
        protected PosSettlementProjectionService $settlementProjectionService = new PosSettlementProjectionService(),
        protected PosReceiptReprintProjectionService $receiptProjectionService = new PosReceiptReprintProjectionService()
    ) {}

    /**
     * Read-only bounded discovery of POS transactions in the given business setting that have
     * completed non-reversed product replacement returns with cross-owner replacement evidence.
     *
     * @return array<string, mixed>
     */
    public function discoverCandidates(int $settingId, int $limit = 50): array
    {
        if ($settingId <= 0) {
            throw new \InvalidArgumentException('A valid positive business setting ID is required for discovery.');
        }

        $limit = max(1, min(500, $limit));

        $returns = PosReturn::query()
            ->with(['saleReturns.saleReturnDetails'])
            ->where('setting_id', $settingId)
            ->where('status', PosReturn::STATUS_COMPLETED)
            ->where('is_reversed', false)
            ->whereNotNull('pos_transaction_id')
            ->orderBy('id', 'asc')
            ->get();

        $candidateTransactionIds = [];
        foreach ($returns as $posReturn) {
            $hasCrossOwner = false;
            foreach ($posReturn->saleReturns as $saleReturn) {
                foreach ($saleReturn->saleReturnDetails as $detail) {
                    if ($this->isCrossOwnerReplacementDetail($detail, $saleReturn)) {
                        $hasCrossOwner = true;
                        break 2;
                    }
                }
            }

            if ($hasCrossOwner && $posReturn->pos_transaction_id) {
                $candidateTransactionIds[(int) $posReturn->pos_transaction_id] = (int) $posReturn->pos_transaction_id;
            }
        }

        $totalCandidates = count($candidateTransactionIds);
        $boundedIds = array_slice(array_values($candidateTransactionIds), 0, $limit);

        $candidates = [];
        foreach ($boundedIds as $trxId) {
            $plan = $this->plan($trxId, $settingId);
            $candidates[] = [
                'transaction_id' => $plan['transaction_id'],
                'transaction_code' => $plan['transaction_code'],
                'checkout_id' => $plan['checkout_id'] ?? null,
                'classification' => $plan['classification'],
                'reason' => $plan['reason'] ?? null,
                'evidence_fingerprint' => $plan['evidence_fingerprint'] ?? null,
                'current_grand_total' => $plan['summary']['current_grand_total'] ?? null,
                'proposed_grand_total' => $plan['summary']['proposed_grand_total'] ?? null,
                'current_effective_paid' => $plan['summary']['current_effective_paid'] ?? null,
                'proposed_effective_paid' => $plan['summary']['proposed_effective_paid'] ?? null,
            ];
        }

        return [
            'setting_id' => $settingId,
            'limit' => $limit,
            'total_discovered' => $totalCandidates,
            'returned_count' => count($candidates),
            'candidates' => $candidates,
        ];
    }

    /**
     * Plan and evaluate a single POS transaction (by ID or code) within its owning business scope.
     * Performs zero database writes.
     *
     * @return array<string, mixed>
     */
    public function plan(int|string $transactionIdOrCode, ?int $settingId = null): array
    {
        if ($settingId === null || $settingId <= 0) {
            return $this->blockedPlan(
                is_numeric($transactionIdOrCode) ? (int) $transactionIdOrCode : null,
                (string) $transactionIdOrCode,
                ['Scope unit usaha (--setting) wajib ditentukan secara eksplisit.']
            );
        }

        $query = PosTransaction::query();
        if (is_numeric($transactionIdOrCode)) {
            $query->whereKey((int) $transactionIdOrCode);
        } else {
            $query->where('code', (string) $transactionIdOrCode);
        }

        /** @var PosTransaction|null $transaction */
        $transaction = $query->first();

        if (! $transaction) {
            return $this->blockedPlan(
                is_numeric($transactionIdOrCode) ? (int) $transactionIdOrCode : null,
                (string) $transactionIdOrCode,
                ["Transaksi POS '{$transactionIdOrCode}' tidak ditemukan."]
            );
        }

        if ((int) $transaction->setting_id !== (int) $settingId) {
            return $this->blockedPlan(
                (int) $transaction->id,
                (string) $transaction->code,
                ["Transaksi POS #{$transaction->id} milik setting #{$transaction->setting_id}, bukan setting #{$settingId}."]
            );
        }

        return $this->reconcile($transaction, $settingId);
    }

    /**
     * Reconcile chronological cross-owner replacement accounting evidence for a checkout group.
     *
     * @return array<string, mixed>
     */
    public function reconcile(PosTransaction $transaction, int $settingId): array
    {
        $blockReasons = [];

        if ($transaction->status !== PosTransaction::STATUS_COMPLETED) {
            $blockReasons[] = "Transaksi POS #{$transaction->id} berstatus '{$transaction->status}', bukan COMPLETED.";
        }

        /** @var PosCheckout|null $checkout */
        $checkout = $transaction->completedCheckout
            ?? PosCheckout::query()->where('pos_transaction_id', $transaction->id)->first()
            ?? ($transaction->completed_checkout_id ? PosCheckout::query()->find($transaction->completed_checkout_id) : null);

        if (! $checkout) {
            return $this->blockedPlan(
                (int) $transaction->id,
                (string) $transaction->code,
                ["Checkout untuk transaksi POS #{$transaction->id} tidak ditemukan."]
            );
        }

        if ((int) $checkout->setting_id !== (int) $settingId) {
            return $this->blockedPlan(
                (int) $transaction->id,
                (string) $transaction->code,
                ["Checkout #{$checkout->id} milik setting #{$checkout->setting_id}, bukan setting #{$settingId}."]
            );
        }

        $groupTransactions = PosTransaction::query()
            ->with(['lines.product', 'lines.conversion', 'lines.serials'])
            ->where(function ($q) use ($checkout, $transaction) {
                $q->where('completed_checkout_id', $checkout->id)
                    ->orWhere('id', $transaction->id);
                if (! empty($checkout->pos_transaction_id)) {
                    $q->orWhere('id', $checkout->pos_transaction_id);
                }
            })
            ->orderBy('id', 'asc')
            ->get();

        $groupTransactionIds = $groupTransactions->pluck('id')->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();

        // Check active/incomplete returns on this checkout group
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

        // Load completed non-reversed returns in chronological order
        $completedReturns = PosReturn::query()
            ->with([
                'lines.returnedSerial',
                'lines.replacementSerial',
                'saleReturns.saleReturnDetails',
                'saleReturns.saleReturnPayments',
            ])
            ->where(function ($q) use ($checkout, $groupTransactionIds) {
                $q->where('pos_checkout_id', $checkout->id)
                    ->orWhereIn('pos_transaction_id', $groupTransactionIds);
            })
            ->where('status', PosReturn::STATUS_COMPLETED)
            ->where('is_reversed', false)
            ->orderBy('settled_at', 'asc')
            ->orderBy('approved_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $diagnostics = $this->salesResolver->resolveWithDiagnostics($checkout);
        if (! $diagnostics['is_valid'] || $diagnostics['sales']->isEmpty()) {
            $anomalies = implode('; ', $diagnostics['anomalies'] ?: ['Pemetaan dokumen penjualan tidak valid.']);
            $blockReasons[] = "Pemetaan dokumen penjualan tidak valid: {$anomalies}";
        }

        /** @var Collection<int, Sale> $sales */
        $sales = $diagnostics['sales']->sortBy('id')->values();
        $sales->load([
            'tenantSetting',
            'saleDetails.product',
            'saleDetails.bundleItems',
            'bundleItems.saleDetail',
            'salePayments.paymentMethod',
            'salePayments.creditApplications',
            'incomingOwnerPaymentTransfers.originalSalePayment',
            'outgoingOwnerPaymentTransfers.originalSalePayment',
            'saleDispatches.details',
        ]);

        $salesById = $sales->keyBy(fn (Sale $s) => (int) $s->id);

        // Identify all cross-owner replacement events across completed returns
        $crossOwnerEvents = [];
        foreach ($completedReturns as $posReturn) {
            foreach ($posReturn->saleReturns->sortBy('id') as $saleReturn) {
                foreach ($saleReturn->saleReturnDetails->sortBy('id') as $srd) {
                    if ($this->isCrossOwnerReplacementDetail($srd, $saleReturn)) {
                        $crossOwnerEvents[] = [
                            'pos_return' => $posReturn,
                            'sale_return' => $saleReturn,
                            'sale_return_detail' => $srd,
                        ];
                    }
                }
            }
        }

        if (empty($crossOwnerEvents)) {
            $blockReasons[] = 'Transaksi ini tidak memiliki bukti penggantian barang lintas pemilik yang selesai.';
        }

        // Track simulated/proposed state per Sale (in minor units)
        $saleStates = [];
        foreach ($sales as $sale) {
            $saleId = (int) $sale->id;
            $activeDetailsMinor = $this->computeAuthoritativeActiveDetailsSubtotalMinor($sale);
            $shippingMinor = max(0, $this->transferService->toMinor((float) ($sale->shipping_amount ?? 0)));
            $taxMinor = max(0, $this->transferService->toMinor((float) ($sale->tax_amount ?? 0)));
            $rowTaxSumMinor = (int) $sale->saleDetails
                ->filter(fn (SaleDetails $d) => (float) ($d->quantity ?? 0) > 0.0001)
                ->sum(fn (SaleDetails $d) => $this->transferService->toMinor((float) ($d->product_tax_amount ?? 0)));
            $isTaxIncludedInRows = (bool) ($sale->is_tax_included ?? false)
                || ($rowTaxSumMinor > 0 && abs($taxMinor - $rowTaxSumMinor) <= 5);
            $extraHeaderTaxMinor = $isTaxIncludedInRows ? 0 : max(0, $taxMinor - $rowTaxSumMinor);
            $discountMinor = max(0, $this->transferService->toMinor((float) ($sale->discount_amount ?? 0)));
            $currentTotalMinor = $this->transferService->toMinor((float) ($sale->total_amount ?? 0));
            $currentPaidMinor = $this->transferService->toMinor((float) ($sale->paid_amount ?? 0));
            $currentDueMinor = $this->transferService->toMinor((float) ($sale->due_amount ?? 0));
            $currentEffectivePaidMinor = $this->transferService->toMinor($sale->getEffectivePaidAmount());

            // Non-synthetic active payments on this sale
            $genuineActivePayments = $sale->salePayments
                ->filter(fn (SalePayment $p) => $p->isActive() && ! $this->isSyntheticReplacementPayment($p))
                ->values();
            $syntheticActivePayments = $sale->salePayments
                ->filter(fn (SalePayment $p) => $p->isActive() && $this->isSyntheticReplacementPayment($p))
                ->values();

            $saleStates[$saleId] = [
                'sale' => $sale,
                'sale_id' => $saleId,
                'setting_id' => (int) $sale->setting_id,
                'setting_name' => (string) ($sale->tenantSetting?->company_name ?? "Setting #{$sale->setting_id}"),
                'reference' => (string) $sale->reference,
                'active_details_minor' => $activeDetailsMinor,
                'shipping_minor' => $shippingMinor,
                'tax_minor' => $taxMinor,
                'discount_minor' => $discountMinor,
                'expected_authoritative_total_minor' => max(0, $activeDetailsMinor - $discountMinor + $shippingMinor + $extraHeaderTaxMinor),
                'current_total_minor' => $currentTotalMinor,
                'current_paid_minor' => $currentPaidMinor,
                'current_due_minor' => $currentDueMinor,
                'current_effective_paid_minor' => $currentEffectivePaidMinor,
                'proposed_total_minor' => $currentTotalMinor,
                'proposed_paid_minor' => $currentPaidMinor,
                'proposed_due_minor' => $currentDueMinor,
                'proposed_effective_paid_minor' => $currentEffectivePaidMinor,
                'genuine_active_payments' => $genuineActivePayments,
                'synthetic_active_payments' => $syntheticActivePayments,
                'has_header_drift' => false,
                'has_payment_drift' => false,
            ];
        }

        // Build chronological transfer & invalidation actions
        $plannedTransfers = [];
        $plannedInvalidations = [];
        $sourceSaleAdjustmentsMinor = [];
        $sourceSaleIds = [];
        $replacementSaleIds = [];
        $originalPaymentIds = [];

        // Track available payment slots across sales during chronological simulation
        $simulatedSlotsBySaleId = [];
        $initialAvailablePaidMinorBySaleId = [];
        foreach ($sales as $sale) {
            $slots = $this->buildInitialGenuinePaymentSlots($sale);
            $simulatedSlotsBySaleId[(int) $sale->id] = $slots;
            $initialAvailablePaidMinorBySaleId[(int) $sale->id] = array_sum(array_column($slots, 'available_minor'));
        }

        foreach ($crossOwnerEvents as $event) {
            /** @var PosReturn $posReturn */
            $posReturn = $event['pos_return'];
            /** @var SaleReturn $saleReturn */
            $saleReturn = $event['sale_return'];
            /** @var SaleReturnDetail $srd */
            $srd = $event['sale_return_detail'];

            $sourceSaleId = (int) ($saleReturn->sale_id ?? 0);
            $sourceSale = $salesById->get($sourceSaleId);

            if (! $sourceSale) {
                $blockReasons[] = "Penjualan sumber #{$sourceSaleId} untuk retur {$posReturn->reference} tidak ditemukan dalam grup checkout.";
                continue;
            }

            $replacementSale = $this->resolveReplacementSaleForDetail($posReturn, $saleReturn, $srd, $sales);
            if (! $replacementSale) {
                $blockReasons[] = "Penjualan pengganti lintas pemilik untuk retur {$posReturn->reference} (baris #{$srd->id}) tidak dapat ditemukan secara unik.";
                continue;
            }

            $replacementSaleId = (int) $replacementSale->id;
            if ((int) $replacementSale->setting_id === (int) $sourceSale->setting_id) {
                $blockReasons[] = "Penjualan pengganti #{$replacementSaleId} memiliki setting yang sama (#{$sourceSale->setting_id}) dengan penjualan sumber #{$sourceSaleId}.";
                continue;
            }

            $sourceSaleIds[$sourceSaleId] = $sourceSaleId;
            $replacementSaleIds[$replacementSaleId] = $replacementSaleId;

            $effects = data_get($srd->execution_context, 'generated_replacement_sale_effects', []);
            $transferChargeMinor = $this->transferService->toMinor(
                (float) ($effects['payment_amount'] ?? data_get($srd->execution_context, 'original_sale_correction_amount', $srd->sub_total ?? 0))
            );

            if ($transferChargeMinor <= 0) {
                $blockReasons[] = "Nilai komersial transfer penggantian pada baris retur #{$srd->id} tidak valid.";
                continue;
            }

            // Replacement/intermediate sale header consistency is validated after the whole chain is replayed
            // (see header reconciliation below), because a downstream replacement may explain the stale header.

            // Check if transfers already exist for this SaleReturnDetail
            $existingTransfers = PosOwnerPaymentTransfer::query()
                ->where('sale_return_detail_id', (int) $srd->id)
                ->orderBy('id', 'asc')
                ->get();

            // Check synthetic payment on replacement sale for this return
            $syntheticPaymentsForReturn = $saleStates[$replacementSaleId]['synthetic_active_payments']
                ->filter(fn (SalePayment $p) => $this->matchesSyntheticPaymentForReturn($p, $posReturn))
                ->values();

            // Check if replacement sale has unexplained non-synthetic payments:
            // 1. If it has both synthetic payment and independent genuine payment, origin of funds is ambiguous.
            // 2. If it has genuine active payments and this event will allocate a transfer, check that genuine payments
            //    plus existing/planned transfers do not exceed the replacement sale's authoritative total.
            //    If genuine payments already cover the sale without any synthetic payment present, an owner transfer is unexpected/unexplained.
            $unexplainedPayments = $saleStates[$replacementSaleId]['genuine_active_payments'];
            if ($syntheticPaymentsForReturn->isNotEmpty() && $unexplainedPayments->isNotEmpty()) {
                $blockReasons[] = "Penjualan pengganti {$replacementSale->reference} memiliki pembayaran sintetis sekaligus pembayaran langsung lainnya sehingga asal dana menjadi ambigu.";
                continue;
            }

            if ($syntheticPaymentsForReturn->isEmpty() && $unexplainedPayments->isNotEmpty() && $existingTransfers->isEmpty()) {
                $blockReasons[] = "Penjualan pengganti {$replacementSale->reference} memiliki pembayaran langsung tanpa rekaman pembayaran sintetis penggantian sehingga asal dana tidak dapat diverifikasi.";
                continue;
            }

            foreach ($syntheticPaymentsForReturn as $synPayment) {
                $synMinor = $this->transferService->toMinor((float) $synPayment->amount);
                if ($synMinor !== $transferChargeMinor) {
                    $blockReasons[] = "Nominal pembayaran sintetis {$synPayment->reference} (" . ($synMinor / 100) . ") berbeda dari nilai komersial penggantian (" . ($transferChargeMinor / 100) . ").";
                    continue 2;
                }
                $plannedInvalidations[(int) $synPayment->id] = [
                    'payment_id' => (int) $synPayment->id,
                    'sale_id' => $replacementSaleId,
                    'sale_reference' => (string) $replacementSale->reference,
                    'reference' => (string) $synPayment->reference,
                    'amount_minor' => $synMinor,
                    'amount' => $this->transferService->fromMinor($synMinor),
                    'payment_method' => (string) ($synPayment->payment_method ?: 'CASH'),
                    'pos_return_id' => (int) $posReturn->id,
                    'sale_return_detail_id' => (int) $srd->id,
                ];
            }

            // Determine whether source sale header still includes this transferred replacement charge
            $sourceCurrentTotalMinor = $saleStates[$sourceSaleId]['current_total_minor'];
            $sourceAuthoritativeRemainingMinor = $saleStates[$sourceSaleId]['expected_authoritative_total_minor'];

            if ($sourceCurrentTotalMinor > $sourceAuthoritativeRemainingMinor) {
                $sourceSaleAdjustmentsMinor[$sourceSaleId] = ($sourceSaleAdjustmentsMinor[$sourceSaleId] ?? 0) + $transferChargeMinor;
            }

            // Plan payment allocation transfer if not already fully covered by existing PosOwnerPaymentTransfer rows
            $existingTransferredMinor = (int) $existingTransfers->sum('amount_minor');
            foreach ($existingTransfers as $ext) {
                $originalPaymentIds[(int) $ext->original_sale_payment_id] = (int) $ext->original_sale_payment_id;
            }

            if ($existingTransfers->isEmpty()) {
                // How much paid amount should transfer to the replacement sale?
                // Determine available paid pool on the source sale
                $slots = &$simulatedSlotsBySaleId[$sourceSaleId];
                $availableSourcePaidMinor = array_sum(array_column($slots, 'available_minor'));

                // Pre-transfer charge on source sale before this replacement hop
                $preTransferSourceChargeMinor = $sourceAuthoritativeRemainingMinor + $transferChargeMinor;
                $targetPaidTransferMinor = 0;

                if ($availableSourcePaidMinor <= 0) {
                    $targetPaidTransferMinor = 0;
                } elseif ($availableSourcePaidMinor >= $preTransferSourceChargeMinor) {
                    $targetPaidTransferMinor = $transferChargeMinor;
                } else {
                    $allocated = $this->transferService->allocateMinorProportionally($availableSourcePaidMinor, [
                        'source_retained' => $sourceAuthoritativeRemainingMinor,
                        'replacement' => $transferChargeMinor,
                    ]);
                    $targetPaidTransferMinor = min($transferChargeMinor, (int) ($allocated['replacement'] ?? 0));
                }

                // If there was a synthetic payment on replacement sale or the source sale recorded paid_amount > initial available slots, block when payment provenance is missing
                $sourceRecordedPaidMinor = $saleStates[$sourceSaleId]['current_paid_minor'];
                $initialSourcePaidMinor = (int) ($initialAvailablePaidMinorBySaleId[$sourceSaleId] ?? 0);
                // Proven allocations replayed earlier in this chain legitimately back the source's recorded paid value.
                $initialSourcePaidMinor += (int) collect($plannedTransfers)->where('replacement_sale_id', $sourceSaleId)->sum('amount_minor');
                if ($sourceRecordedPaidMinor > $initialSourcePaidMinor) {
                    $blockReasons[] = sprintf(
                        'Penjualan sumber %s mencatat nominal terbayar (%.2f) yang tidak didukung oleh bukti pembayaran aktif (%.2f).',
                        $sourceSale->reference,
                        $sourceRecordedPaidMinor / 100,
                        $initialSourcePaidMinor / 100
                    );
                    unset($slots);
                    continue;
                }

                if ($syntheticPaymentsForReturn->isNotEmpty() && $targetPaidTransferMinor !== $transferChargeMinor) {
                    $blockReasons[] = "Penjualan sumber {$sourceSale->reference} tidak memiliki pembayaran asli yang cukup (" . ($availableSourcePaidMinor / 100) . ") untuk mendukung pembayaran pengganti penuh (" . ($transferChargeMinor / 100) . ").";
                    unset($slots);
                    continue;
                }

                $allocations = $this->consumeSimulatedSlots($slots, $targetPaidTransferMinor);
                unset($slots);

                $allocatedSumMinor = array_sum(array_column($allocations, 'amount_minor'));
                if ($allocatedSumMinor !== $targetPaidTransferMinor) {
                    $blockReasons[] = "Bukti pembayaran asli pada penjualan sumber {$sourceSale->reference} tidak mencukupi untuk mengalokasikan " . ($targetPaidTransferMinor / 100) . " ke penjualan pengganti {$replacementSale->reference}.";
                    continue;
                }

                $firstInvalidatedId = $syntheticPaymentsForReturn->first()?->id ? (int) $syntheticPaymentsForReturn->first()->id : null;

                foreach ($allocations as $alloc) {
                    $origPayId = (int) $alloc['original_sale_payment_id'];
                    $originalPaymentIds[$origPayId] = $origPayId;
                    $rawPredId = $alloc['predecessor_transfer_id'] ?? null;
                    $predId = (is_numeric($rawPredId) && (int) $rawPredId > 0)
                        ? (int) $rawPredId
                        : (is_string($rawPredId) && $rawPredId !== '' ? $rawPredId : null);
                    $simTransferKey = 'plan:' . ((int) $srd->id) . ':' . $origPayId . ':' . ($predId ?? '0');
                    $initialPredInt = is_int($predId) ? $predId : null;

                    $plannedTransfers[] = [
                        'plan_key' => $simTransferKey,
                        'execution_key' => PosOwnerPaymentTransfer::buildExecutionKey((int) $srd->id, $origPayId, $initialPredInt),
                        'pos_transaction_id' => (int) $transaction->id,
                        'pos_checkout_id' => (int) $checkout->id,
                        'pos_return_id' => (int) $posReturn->id,
                        'pos_return_line_id' => $srd->pos_return_line_id ? (int) $srd->pos_return_line_id : null,
                        'sale_return_id' => (int) $saleReturn->id,
                        'sale_return_detail_id' => (int) $srd->id,
                        'source_sale_id' => $sourceSaleId,
                        'source_sale_reference' => (string) $sourceSale->reference,
                        'replacement_sale_id' => $replacementSaleId,
                        'replacement_sale_reference' => (string) $replacementSale->reference,
                        'original_sale_payment_id' => $origPayId,
                        'predecessor_transfer_id' => $predId,
                        'invalidated_synthetic_payment_id' => $firstInvalidatedId,
                        'payment_method_id' => $alloc['payment_method_id'],
                        'payment_method' => (string) $alloc['payment_method'],
                        'amount_minor' => (int) $alloc['amount_minor'],
                        'amount' => $this->transferService->fromMinor((int) $alloc['amount_minor']),
                        'charge_minor' => $transferChargeMinor,
                        'paid_minor' => $allocatedSumMinor,
                        'due_minor' => max(0, $transferChargeMinor - $allocatedSumMinor),
                    ];

                    // Add incoming slot to replacement sale in case of multi-hop chain (Owner A -> Owner B -> Owner C)
                    // The predecessor is this transfer (or its simulated key if predecessor not yet created in DB)
                    $simulatedSlotsBySaleId[$replacementSaleId][] = [
                        'original_sale_payment_id' => $origPayId,
                        'predecessor_transfer_id' => $simTransferKey,
                        'payment_method_id' => $alloc['payment_method_id'],
                        'payment_method' => (string) $alloc['payment_method'],
                        'available_minor' => (int) $alloc['amount_minor'],
                    ];
                }
            }
        }

        // Reconcile proposed Sale headers and validate that any header difference is 100% explained by proven cross-owner transfers
        foreach ($saleStates as $saleId => &$st) {
            /** @var Sale $saleModel */
            $saleModel = $st['sale'];
            $adjustmentMinor = (int) ($sourceSaleAdjustmentsMinor[$saleId] ?? 0);

            if ($adjustmentMinor > 0) {
                $proposedTotalMinor = max(0, $st['current_total_minor'] - $adjustmentMinor);
                if ($proposedTotalMinor !== $st['expected_authoritative_total_minor']) {
                    $blockReasons[] = sprintf(
                        "Penjualan sumber %s memiliki selisih header yang tidak dapat dijelaskan oleh bukti penggantian (Header saat ini: %.2f, Transfer penggantian: %.2f, Sisa rincian aktif: %.2f).",
                        $st['reference'],
                        $st['current_total_minor'] / 100,
                        $adjustmentMinor / 100,
                        $st['expected_authoritative_total_minor'] / 100
                    );
                }
                $st['proposed_total_minor'] = $proposedTotalMinor;
            } else {
                // For any sale without pending transfer adjustment, verify its header matches its authoritative remaining details
                if ($st['current_total_minor'] !== $st['expected_authoritative_total_minor']) {
                    $blockReasons[] = sprintf(
                        "Penjualan %s memiliki selisih header di luar penggantian lintas pemilik (Header: %.2f vs Rincian: %.2f).",
                        $st['reference'],
                        $st['current_total_minor'] / 100,
                        $st['expected_authoritative_total_minor'] / 100
                    );
                }
                $st['proposed_total_minor'] = $st['current_total_minor'];
            }

            // Compute proposed effective paid for this sale:
            // Genuine active payments on this sale + existing incoming transfers + planned incoming transfers
            // - existing outgoing transfers - planned outgoing transfers
            $genuineDirectMinor = (int) $st['genuine_active_payments']->sum(fn (SalePayment $p) => $this->transferService->toMinor((float) $p->amount));
            $creditMinor = $this->resolveSaleCreditApplicationsMinor($saleModel);

            $existingIncomingMinor = (int) $saleModel->incomingOwnerPaymentTransfers
                ->filter(fn (PosOwnerPaymentTransfer $t) => ! $t->originalSalePayment || $t->originalSalePayment->isActive())
                ->sum('amount_minor');
            $existingOutgoingMinor = (int) $saleModel->outgoingOwnerPaymentTransfers
                ->filter(fn (PosOwnerPaymentTransfer $t) => ! $t->originalSalePayment || $t->originalSalePayment->isActive())
                ->sum('amount_minor');

            $plannedIncomingMinor = (int) collect($plannedTransfers)->where('replacement_sale_id', $saleId)->sum('amount_minor');
            $plannedOutgoingMinor = (int) collect($plannedTransfers)->where('source_sale_id', $saleId)->sum('amount_minor');

            $proposedEffectivePaidMinor = max(
                0,
                $genuineDirectMinor + $creditMinor + $existingIncomingMinor + $plannedIncomingMinor - $existingOutgoingMinor - $plannedOutgoingMinor
            );
            $proposedDueMinor = max(0, $st['proposed_total_minor'] - $proposedEffectivePaidMinor);

            $st['proposed_paid_minor'] = $proposedEffectivePaidMinor;
            $st['proposed_effective_paid_minor'] = $proposedEffectivePaidMinor;
            $st['proposed_due_minor'] = $proposedDueMinor;
            $st['proposed_payment_status'] = $this->resolveSalePaymentStatus($st['proposed_total_minor'], $proposedEffectivePaidMinor, $proposedDueMinor);

            $st['has_header_drift'] = ($st['current_total_minor'] !== $st['proposed_total_minor'])
                || ($st['current_paid_minor'] !== $st['proposed_paid_minor'])
                || ($st['current_due_minor'] !== $st['proposed_due_minor']);

            $st['has_payment_drift'] = ($st['current_effective_paid_minor'] !== $st['proposed_effective_paid_minor']);
        }
        unset($st);

        // Build per-owner and per-payment-method before/after summaries
        $ownerSummaries = $this->buildOwnerSummaries($saleStates);
        $methodSummaries = $this->buildMethodSummaries($saleStates, $plannedTransfers);

        $hasAnyMutation = ! empty($plannedTransfers)
            || ! empty($plannedInvalidations)
            || collect($saleStates)->contains(fn (array $s) => $s['has_header_drift'] || $s['has_payment_drift']);

        if (! empty($blockReasons)) {
            $classification = self::CLASSIFICATION_BLOCKED;
            $reason = implode(' ', array_unique($blockReasons));
        } elseif ($hasAnyMutation) {
            $classification = self::CLASSIFICATION_REPAIRABLE;
            $reason = null;
        } else {
            $classification = self::CLASSIFICATION_UNCHANGED;
            $reason = null;
        }

        // Compute deterministic evidence fingerprint
        $fingerprintPayload = $this->buildFingerprintPayload(
            $settingId,
            $transaction,
            $checkout,
            $sales,
            $completedReturns,
            $plannedInvalidations,
            $plannedTransfers,
            $saleStates
        );
        $evidenceFingerprint = hash('sha256', json_encode($fingerprintPayload));

        $currentGrandTotalMinor = (int) array_sum(array_column($saleStates, 'current_total_minor'));
        $proposedGrandTotalMinor = (int) array_sum(array_column($saleStates, 'proposed_total_minor'));
        $currentEffectivePaidMinor = (int) array_sum(array_column($saleStates, 'current_effective_paid_minor'));
        $proposedEffectivePaidMinor = (int) array_sum(array_column($saleStates, 'proposed_effective_paid_minor'));
        $currentDueTotalMinor = (int) array_sum(array_column($saleStates, 'current_due_minor'));
        $proposedDueTotalMinor = (int) array_sum(array_column($saleStates, 'proposed_due_minor'));

        // Build expected receipt line preview
        $expectedReceiptLines = $this->buildExpectedReceiptLinesPreview($groupTransactions, $saleStates, $completedReturns);

        $salesOutput = [];
        foreach ($saleStates as $saleId => $st) {
            $salesOutput[] = [
                'sale_id' => $saleId,
                'setting_id' => $st['setting_id'],
                'setting_name' => $st['setting_name'],
                'reference' => $st['reference'],
                'has_drift' => $st['has_header_drift'] || $st['has_payment_drift'],
                'current' => [
                    'total_amount' => $this->transferService->fromMinor($st['current_total_minor']),
                    'paid_amount' => $this->transferService->fromMinor($st['current_paid_minor']),
                    'effective_paid_amount' => $this->transferService->fromMinor($st['current_effective_paid_minor']),
                    'due_amount' => $this->transferService->fromMinor($st['current_due_minor']),
                    'payment_status' => (string) $st['sale']->payment_status,
                ],
                'proposed' => [
                    'total_amount' => $this->transferService->fromMinor($st['proposed_total_minor']),
                    'paid_amount' => $this->transferService->fromMinor($st['proposed_paid_minor']),
                    'effective_paid_amount' => $this->transferService->fromMinor($st['proposed_effective_paid_minor']),
                    'due_amount' => $this->transferService->fromMinor($st['proposed_due_minor']),
                    'payment_status' => $st['proposed_payment_status'],
                ],
            ];
        }

        return [
            'classification' => $classification,
            'reason' => $reason,
            'block_reasons' => array_values(array_unique($blockReasons)),
            'setting_id' => $settingId,
            'transaction_id' => (int) $transaction->id,
            'transaction_code' => (string) $transaction->code,
            'checkout_id' => (int) $checkout->id,
            'receipt_number' => (string) $checkout->receipt_number,
            'group_transaction_ids' => $groupTransactionIds,
            'evidence_fingerprint' => $evidenceFingerprint,
            'source_sale_ids' => array_values($sourceSaleIds),
            'replacement_sale_ids' => array_values($replacementSaleIds),
            'original_payment_ids' => array_values($originalPaymentIds),
            'planned_invalidations' => array_values($plannedInvalidations),
            'planned_transfers' => $plannedTransfers,
            'sales' => $salesOutput,
            'owner_summaries' => $ownerSummaries,
            'method_summaries' => $methodSummaries,
            'expected_receipt_lines' => $expectedReceiptLines,
            'summary' => [
                'current_grand_total' => $this->transferService->fromMinor($currentGrandTotalMinor),
                'proposed_grand_total' => $this->transferService->fromMinor($proposedGrandTotalMinor),
                'duplicated_charge_amount' => $this->transferService->fromMinor(max(0, $currentGrandTotalMinor - $proposedGrandTotalMinor)),
                'current_effective_paid' => $this->transferService->fromMinor($currentEffectivePaidMinor),
                'proposed_effective_paid' => $this->transferService->fromMinor($proposedEffectivePaidMinor),
                'duplicated_paid_amount' => $this->transferService->fromMinor(max(0, $currentEffectivePaidMinor - $proposedEffectivePaidMinor)),
                'current_due_total' => $this->transferService->fromMinor($currentDueTotalMinor),
                'proposed_due_total' => $this->transferService->fromMinor($proposedDueTotalMinor),
            ],
        ];
    }

    /**
     * Atomically apply a reviewed replacement accounting repair under deterministic checkout-group locks.
     *
     * @return array<string, mixed>
     */
    public function apply(
        int|string $transactionIdOrCode,
        int $settingId,
        int $actorId,
        string $reason,
        string $expectedFingerprint
    ): array {
        if ($settingId <= 0) {
            throw new \InvalidArgumentException('A valid positive business setting ID is required.');
        }

        if ($actorId <= 0 || ! User::query()->whereKey($actorId)->exists()) {
            throw new \InvalidArgumentException("Actor user ID {$actorId} does not exist.");
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('A non-empty audit reason is required to apply replacement accounting repair.');
        }

        $expectedFingerprint = trim($expectedFingerprint);
        if ($expectedFingerprint === '') {
            throw new \InvalidArgumentException('A reviewed evidence fingerprint is required to apply replacement accounting repair.');
        }

        $previewPlan = $this->plan($transactionIdOrCode, $settingId);

        if ($previewPlan['classification'] === self::CLASSIFICATION_BLOCKED) {
            throw new \RuntimeException('Repair blocked: ' . ($previewPlan['reason'] ?: 'Transaction is not eligible for repair.'));
        }

        if (! hash_equals((string) ($previewPlan['evidence_fingerprint'] ?? ''), $expectedFingerprint)) {
            throw new \RuntimeException(sprintf(
                'Evidence fingerprint mismatch before lock! Expected: %s, Actual: %s',
                $expectedFingerprint,
                (string) ($previewPlan['evidence_fingerprint'] ?? '')
            ));
        }

        if ($previewPlan['classification'] === self::CLASSIFICATION_UNCHANGED) {
            return $previewPlan;
        }

        $transactionId = (int) $previewPlan['transaction_id'];
        $checkoutId = (int) $previewPlan['checkout_id'];
        $groupTrxIds = $previewPlan['group_transaction_ids'];
        sort($groupTrxIds);

        $saleIds = array_map(fn (array $s) => (int) $s['sale_id'], $previewPlan['sales']);
        sort($saleIds);

        return DB::transaction(function () use (
            $transactionId,
            $checkoutId,
            $groupTrxIds,
            $saleIds,
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
                throw new \RuntimeException("POS Transaction #{$transactionId} not found under lock.");
            }

            // 2. Lock PosCheckout
            /** @var PosCheckout|null $lockedCheckout */
            $lockedCheckout = PosCheckout::query()
                ->whereKey($checkoutId)
                ->lockForUpdate()
                ->first();

            if (! $lockedCheckout) {
                throw new \RuntimeException("POS Checkout #{$checkoutId} not found under lock.");
            }

            // 3. Lock PosReturns for this checkout group in ASC order
            $lockedReturns = PosReturn::query()
                ->where(function ($q) use ($checkoutId, $groupTrxIds) {
                    $q->where('pos_checkout_id', $checkoutId)
                        ->orWhereIn('pos_transaction_id', $groupTrxIds);
                })
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

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

            // 5. Lock SalePayments on reachable sales in ASC order
            $lockedPayments = SalePayment::query()
                ->whereIn('sale_id', $saleIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // 6. Lock existing PosOwnerPaymentTransfers on reachable sales in ASC order
            PosOwnerPaymentTransfer::query()
                ->where(function ($q) use ($saleIds) {
                    $q->whereIn('source_sale_id', $saleIds)
                        ->orWhereIn('replacement_sale_id', $saleIds);
                })
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            // Re-run reconciliation under lock and verify fingerprint matches
            $lockedPlan = $this->reconcile($lockedTrx, $settingId);

            if (! hash_equals((string) ($lockedPlan['evidence_fingerprint'] ?? ''), $expectedFingerprint)) {
                throw new \RuntimeException(sprintf(
                    'Evidence changed under lock! Expected: %s, Actual: %s',
                    $expectedFingerprint,
                    (string) ($lockedPlan['evidence_fingerprint'] ?? '')
                ));
            }

            if ($lockedPlan['classification'] === self::CLASSIFICATION_BLOCKED) {
                throw new \RuntimeException('Repair blocked under lock: ' . ($lockedPlan['reason'] ?: 'Unknown block reason.'));
            }

            if ($lockedPlan['classification'] === self::CLASSIFICATION_UNCHANGED) {
                return $lockedPlan;
            }

            $now = now();
            $beforeValues = [
                'summary' => $lockedPlan['summary'],
                'sales' => array_map(fn (array $s) => [
                    'sale_id' => $s['sale_id'],
                    'setting_id' => $s['setting_id'],
                    'reference' => $s['reference'],
                    'values' => $s['current'],
                ], $lockedPlan['sales']),
                'owner_summaries' => $lockedPlan['owner_summaries'],
                'method_summaries' => $lockedPlan['method_summaries'],
            ];

            // Step A: Invalidate proven synthetic `CROSS-OWNER-REPL/` payments
            $invalidatedPaymentIds = [];
            foreach ($lockedPlan['planned_invalidations'] as $inv) {
                $paymentId = (int) $inv['payment_id'];
                /** @var SalePayment|null $paymentModel */
                $paymentModel = $lockedPayments->get($paymentId);
                if (! $paymentModel) {
                    throw new \RuntimeException("Synthetic SalePayment #{$paymentId} missing under lock.");
                }

                if ($paymentModel->isActive()) {
                    $existingNote = trim((string) ($paymentModel->note ?? ''));
                    $repairNote = sprintf(
                        '[INVALIDATED BY %s] %s',
                        self::INVALIDATION_SOURCE,
                        $reason
                    );
                    $paymentModel->forceFill([
                        'status' => SalePayment::STATUS_INVALIDATED,
                        'invalidated_at' => $now,
                        'invalidated_by' => $actorId,
                        'invalidation_source' => self::INVALIDATION_SOURCE,
                        'note' => $existingNote !== '' ? ($existingNote . ' | ' . $repairNote) : $repairNote,
                    ])->save();
                }

                $invalidatedPaymentIds[] = $paymentId;
            }

            // Step B: Create PosOwnerPaymentTransfer rows
            $createdTransferIds = [];
            $transferIdByPlanKey = [];
            foreach ($lockedPlan['planned_transfers'] as $transferPlan) {
                $predId = $transferPlan['predecessor_transfer_id'];
                if (is_string($predId) && isset($transferIdByPlanKey[$predId])) {
                    $predId = (int) $transferIdByPlanKey[$predId];
                } elseif (is_string($predId)) {
                    $predId = null;
                }

                $executionKey = $transferPlan['execution_key'];
                if (! empty($transferPlan['plan_key']) && $predId !== null) {
                    $executionKey = PosOwnerPaymentTransfer::buildExecutionKey(
                        (int) $transferPlan['sale_return_detail_id'],
                        (int) $transferPlan['original_sale_payment_id'],
                        $predId
                    );
                }

                $transfer = PosOwnerPaymentTransfer::query()->create([
                    'execution_key' => $executionKey,
                    'pos_transaction_id' => $transferPlan['pos_transaction_id'],
                    'pos_checkout_id' => $transferPlan['pos_checkout_id'],
                    'pos_return_id' => $transferPlan['pos_return_id'],
                    'pos_return_line_id' => $transferPlan['pos_return_line_id'],
                    'sale_return_id' => $transferPlan['sale_return_id'],
                    'sale_return_detail_id' => $transferPlan['sale_return_detail_id'],
                    'source_sale_id' => $transferPlan['source_sale_id'],
                    'replacement_sale_id' => $transferPlan['replacement_sale_id'],
                    'original_sale_payment_id' => $transferPlan['original_sale_payment_id'],
                    'predecessor_transfer_id' => $predId,
                    'invalidated_synthetic_payment_id' => $transferPlan['invalidated_synthetic_payment_id'],
                    'payment_method_id' => $transferPlan['payment_method_id'],
                    'payment_method' => $transferPlan['payment_method'],
                    'amount' => $transferPlan['amount'],
                    'amount_minor' => $transferPlan['amount_minor'],
                    'transfer_source' => PosOwnerPaymentTransfer::SOURCE_HISTORICAL_REPAIR,
                    'actor_id' => $actorId,
                    'executed_at' => $now,
                    'metadata' => [
                        'repair_reason' => $reason,
                        'evidence_fingerprint' => $expectedFingerprint,
                        'charge_minor' => $transferPlan['charge_minor'],
                        'paid_minor' => $transferPlan['paid_minor'],
                        'due_minor' => $transferPlan['due_minor'],
                    ],
                ]);
                $createdTransferIds[] = (int) $transfer->id;
                if (! empty($transferPlan['plan_key'])) {
                    $transferIdByPlanKey[$transferPlan['plan_key']] = (int) $transfer->id;
                }
            }

            // Step C: Update Sale headers where drift exists
            foreach ($lockedPlan['sales'] as $salePlan) {
                if (! $salePlan['has_drift']) {
                    continue;
                }

                /** @var Sale|null $saleModel */
                $saleModel = $lockedSales->get((int) $salePlan['sale_id']);
                if (! $saleModel) {
                    throw new \RuntimeException("Sale #{$salePlan['sale_id']} missing under lock.");
                }

                $proposed = $salePlan['proposed'];
                $saleModel->forceFill([
                    'total_amount' => $proposed['total_amount'],
                    'paid_amount' => $proposed['paid_amount'],
                    'due_amount' => $proposed['due_amount'],
                    'payment_status' => $proposed['payment_status'],
                ])->save();
            }

            // Step D: Verify settlement and receipt reprint projections succeed and match proposed totals
            $lockedTrx->refresh();
            $lockedCheckout->refresh();

            $settlement = $this->settlementProjectionService->project($lockedTrx);
            if (! $settlement['is_valid']) {
                throw new \RuntimeException('PosSettlementProjectionService failed validation after repair.');
            }

            $expectedGrandTotal = (float) $lockedPlan['summary']['proposed_grand_total'];
            $expectedEffectivePaid = (float) $lockedPlan['summary']['proposed_effective_paid'];
            $expectedDueTotal = (float) $lockedPlan['summary']['proposed_due_total'];

            if (abs(round((float) $settlement['total_amount'], 2) - $expectedGrandTotal) > 0.005) {
                throw new \RuntimeException(sprintf(
                    'Settlement total mismatch after repair: expected %.2f, got %.2f',
                    $expectedGrandTotal,
                    (float) $settlement['total_amount']
                ));
            }

            if (abs(round((float) $settlement['effective_paid'], 2) - $expectedEffectivePaid) > 0.005) {
                throw new \RuntimeException(sprintf(
                    'Settlement effective paid mismatch after repair: expected %.2f, got %.2f',
                    $expectedEffectivePaid,
                    (float) $settlement['effective_paid']
                ));
            }

            if (abs(round((float) $settlement['live_due'], 2) - $expectedDueTotal) > 0.005) {
                throw new \RuntimeException(sprintf(
                    'Settlement live due mismatch after repair: expected %.2f, got %.2f',
                    $expectedDueTotal,
                    (float) $settlement['live_due']
                ));
            }

            $receiptData = $this->receiptProjectionService->getCompletedReceiptData($lockedCheckout);

            if (abs(round((float) ($receiptData['grand_total'] ?? 0), 2) - $expectedGrandTotal) > 0.005) {
                throw new \RuntimeException(sprintf(
                    'Receipt reprint grand_total mismatch after repair: expected %.2f, got %.2f',
                    $expectedGrandTotal,
                    (float) ($receiptData['grand_total'] ?? 0)
                ));
            }

            if (abs(round((float) ($receiptData['outstanding_debt'] ?? 0), 2) - $expectedDueTotal) > 0.005) {
                throw new \RuntimeException(sprintf(
                    'Receipt reprint outstanding_debt mismatch after repair: expected %.2f, got %.2f',
                    $expectedDueTotal,
                    (float) ($receiptData['outstanding_debt'] ?? 0)
                ));
            }

            // Re-run plan to capture clean post-repair state
            $repairedPlan = $this->reconcile($lockedTrx, $settingId);

            $afterValues = [
                'summary' => $repairedPlan['summary'],
                'sales' => array_map(fn (array $s) => [
                    'sale_id' => $s['sale_id'],
                    'setting_id' => $s['setting_id'],
                    'reference' => $s['reference'],
                    'values' => $s['current'],
                ], $repairedPlan['sales']),
                'owner_summaries' => $repairedPlan['owner_summaries'],
                'method_summaries' => $repairedPlan['method_summaries'],
            ];

            $primaryReturnId = $lockedReturns->first()?->id ? (int) $lockedReturns->first()->id : null;

            $audit = PosReplacementAccountingRepairAudit::query()->create([
                'setting_id' => $settingId,
                'pos_transaction_id' => $lockedTrx->id,
                'pos_checkout_id' => $lockedCheckout->id,
                'pos_return_id' => $primaryReturnId,
                'actor_id' => $actorId,
                'reason' => $reason,
                'status' => PosReplacementAccountingRepairAudit::STATUS_APPLIED,
                'evidence_fingerprint' => $expectedFingerprint,
                'source_sale_ids' => $lockedPlan['source_sale_ids'],
                'replacement_sale_ids' => $lockedPlan['replacement_sale_ids'],
                'original_payment_ids' => $lockedPlan['original_payment_ids'],
                'invalidated_payment_ids' => $invalidatedPaymentIds,
                'transfer_ids' => $createdTransferIds,
                'before_values' => $beforeValues,
                'after_values' => $afterValues,
            ]);

            $repairedPlan['classification'] = self::CLASSIFICATION_REPAIRED;
            $repairedPlan['audit_id'] = (int) $audit->id;
            $repairedPlan['applied_fingerprint'] = $expectedFingerprint;
            $repairedPlan['invalidated_payment_ids'] = $invalidatedPaymentIds;
            $repairedPlan['created_transfer_ids'] = $createdTransferIds;

            return $repairedPlan;
        }, self::DEADLOCK_RETRY_ATTEMPTS); // MySQL deadlock victims retry; the fingerprint is revalidated under lock on each attempt.
    }

    protected function isCrossOwnerReplacementDetail(SaleReturnDetail $detail, SaleReturn $saleReturn): bool
    {
        $ctx = is_array($detail->execution_context) ? $detail->execution_context : [];
        if ((string) ($ctx['execution_mode'] ?? '') === 'cross_owner_replacement') {
            return true;
        }

        $replOwnerId = (int) ($ctx['replacement_serial_owner_setting_id'] ?? data_get($ctx, 'generated_replacement_sale_effects.setting_id', 0));
        if ($replOwnerId > 0 && $replOwnerId !== (int) $saleReturn->setting_id) {
            return true;
        }

        return false;
    }

    protected function computeAuthoritativeActiveDetailsSubtotalMinor(Sale $sale): int
    {
        $totalMinor = 0;
        $activeDetailIds = [];

        foreach ($sale->saleDetails as $detail) {
            if ((float) ($detail->quantity ?? 0) > 0.0001) {
                $totalMinor += $this->transferService->toMinor((float) ($detail->sub_total ?? 0));
                $activeDetailIds[(int) $detail->id] = true;
            }
        }

        if ($sale->relationLoaded('bundleItems')) {
            foreach ($sale->bundleItems as $bundleItem) {
                $sdId = $bundleItem->sale_detail_id ? (int) $bundleItem->sale_detail_id : null;
                if ((! $sdId || empty($activeDetailIds[$sdId]))
                    && ((float) ($bundleItem->quantity ?? 0) > 0.0001 || (float) ($bundleItem->sub_total ?? 0) > 0.005)) {
                    $totalMinor += $this->transferService->toMinor((float) ($bundleItem->sub_total ?? 0));
                }
            }
        }

        return $totalMinor;
    }

    protected function isSyntheticReplacementPayment(SalePayment $payment): bool
    {
        $ref = strtoupper(trim((string) ($payment->reference ?? '')));
        return str_starts_with($ref, 'CROSS-OWNER-REPL/');
    }

    protected function matchesSyntheticPaymentForReturn(SalePayment $payment, PosReturn $posReturn): bool
    {
        $ref = strtoupper(trim((string) ($payment->reference ?? '')));
        $returnRef = strtoupper(trim((string) ($posReturn->reference ?? '')));

        if ($returnRef === '') {
            return false;
        }

        return $ref === ('CROSS-OWNER-REPL/' . $returnRef)
            || str_starts_with($ref, 'CROSS-OWNER-REPL/' . $returnRef . '/');
    }

    /**
     * Resolve the replacement Sale created for a cross-owner SaleReturnDetail using dispatch lineage,
     * execution_context metadata, and POS return note provenance.
     *
     * @param Collection<int, Sale> $sales
     */
    protected function resolveReplacementSaleForDetail(
        PosReturn $posReturn,
        SaleReturn $saleReturn,
        SaleReturnDetail $srd,
        Collection $sales
    ): ?Sale {
        $targetSettingId = (int) (
            data_get($srd->execution_context, 'generated_replacement_sale_effects.setting_id')
            ?? data_get($srd->execution_context, 'replacement_serial_owner_setting_id')
            ?? 0
        );

        $returnLineId = $srd->pos_return_line_id ? (int) $srd->pos_return_line_id : null;

        // 1. Match by dispatch_details.pos_return_line_id
        if ($returnLineId) {
            $byDispatchLine = $sales->filter(function (Sale $sale) use ($returnLineId, $saleReturn, $targetSettingId) {
                if ((int) $sale->id === (int) $saleReturn->sale_id) {
                    return false;
                }
                if ($targetSettingId > 0 && (int) $sale->setting_id !== $targetSettingId) {
                    return false;
                }
                foreach ($sale->saleDispatches as $dispatch) {
                    foreach ($dispatch->details as $dd) {
                        if ((int) ($dd->pos_return_line_id ?? 0) === $returnLineId) {
                            return true;
                        }
                    }
                }
                return false;
            })->values();

            if ($byDispatchLine->count() === 1) {
                return $byDispatchLine->first();
            }
        }

        // 2. Match by POSReturn reference in Sale note + target setting + product
        $expectedNoteFragment = 'POS Return ' . $posReturn->reference;
        $byNote = $sales->filter(function (Sale $sale) use ($expectedNoteFragment, $saleReturn, $targetSettingId) {
            if ((int) $sale->id === (int) $saleReturn->sale_id) {
                return false;
            }
            if ($targetSettingId > 0 && (int) $sale->setting_id !== $targetSettingId) {
                return false;
            }
            return str_contains((string) ($sale->note ?? ''), $expectedNoteFragment);
        })->values();

        if ($byNote->count() === 1) {
            return $byNote->first();
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildInitialGenuinePaymentSlots(Sale $sale): array
    {
        $outgoingRows = $sale->outgoingOwnerPaymentTransfers;
        $slots = [];

        $genuinePayments = $sale->salePayments
            ->filter(fn (SalePayment $p) => $p->isActive() && ! $this->isSyntheticReplacementPayment($p))
            ->values()
            ->all();

        usort($genuinePayments, function (SalePayment $a, SalePayment $b): int {
            $stageCmp = ((int) ($a->stage_order ?? 0)) <=> ((int) ($b->stage_order ?? 0));
            if ($stageCmp !== 0) {
                return $stageCmp;
            }
            $dateA = (string) ($a->getRawOriginal('date') ?? $a->date ?? '');
            $dateB = (string) ($b->getRawOriginal('date') ?? $b->date ?? '');
            $dateCmp = strcmp($dateA, $dateB);
            if ($dateCmp !== 0) {
                return $dateCmp;
            }
            return ((int) $a->id) <=> ((int) $b->id);
        });

        foreach ($genuinePayments as $payment) {
            $paymentMinor = $this->transferService->toMinor((float) $payment->amount);
            $usedMinor = (int) $outgoingRows
                ->where('original_sale_payment_id', (int) $payment->id)
                ->whereNull('predecessor_transfer_id')
                ->sum('amount_minor');

            $avail = max(0, $paymentMinor - $usedMinor);
            if ($avail <= 0) {
                continue;
            }

            $slots[] = [
                'original_sale_payment_id' => (int) $payment->id,
                'predecessor_transfer_id' => null,
                'payment_method_id' => $payment->payment_method_id ? (int) $payment->payment_method_id : null,
                'payment_method' => (string) ($payment->payment_method ?: 'CASH'),
                'available_minor' => $avail,
            ];
        }

        foreach ($sale->incomingOwnerPaymentTransfers->sortBy('id') as $incoming) {
            if ($incoming->originalSalePayment && $incoming->originalSalePayment->isInvalidated()) {
                continue;
            }
            $usedMinor = (int) $outgoingRows
                ->where('predecessor_transfer_id', (int) $incoming->id)
                ->sum('amount_minor');
            $avail = max(0, (int) $incoming->amount_minor - $usedMinor);
            if ($avail <= 0) {
                continue;
            }

            $slots[] = [
                'original_sale_payment_id' => (int) $incoming->original_sale_payment_id,
                'predecessor_transfer_id' => (int) $incoming->id,
                'payment_method_id' => $incoming->payment_method_id ? (int) $incoming->payment_method_id : null,
                'payment_method' => (string) ($incoming->payment_method ?: 'CASH'),
                'available_minor' => $avail,
            ];
        }

        return $slots;
    }

    /**
     * @param array<int, array<string, mixed>> $slots
     * @return array<int, array<string, mixed>>
     */
    protected function consumeSimulatedSlots(array &$slots, int $targetMinor): array
    {
        if ($targetMinor <= 0) {
            return [];
        }

        $allocations = [];
        $remaining = $targetMinor;

        foreach ($slots as &$slot) {
            if ($remaining <= 0) {
                break;
            }

            $avail = (int) ($slot['available_minor'] ?? 0);
            if ($avail <= 0) {
                continue;
            }

            $take = min($remaining, $avail);
            $slot['available_minor'] -= $take;
            $remaining -= $take;

            $allocations[] = [
                'original_sale_payment_id' => (int) $slot['original_sale_payment_id'],
                'predecessor_transfer_id' => $slot['predecessor_transfer_id'],
                'payment_method_id' => $slot['payment_method_id'],
                'payment_method' => (string) $slot['payment_method'],
                'amount_minor' => $take,
            ];
        }
        unset($slot);

        return $allocations;
    }

    protected function resolveSaleCreditApplicationsMinor(Sale $sale): int
    {
        $minor = 0;
        foreach ($sale->salePayments->filter(fn (SalePayment $p) => $p->isActive()) as $payment) {
            $credits = $payment->relationLoaded('creditApplications')
                ? $payment->creditApplications
                : $payment->creditApplications()->get();
            foreach ($credits as $credit) {
                $minor += $this->transferService->toMinor((float) $credit->amount);
            }
        }
        return $minor;
    }

    protected function resolveSalePaymentStatus(int $totalMinor, int $paidMinor, int $dueMinor): string
    {
        if ($dueMinor <= 0 && ($totalMinor > 0 || $paidMinor >= 0)) {
            return 'Paid';
        }

        if ($paidMinor > 0) {
            return 'Partial';
        }

        return 'Unpaid';
    }

    /**
     * @param array<int, array<string, mixed>> $saleStates
     * @return array<int, array<string, mixed>>
     */
    protected function buildOwnerSummaries(array $saleStates): array
    {
        $bySetting = [];
        foreach ($saleStates as $st) {
            $sid = (int) $st['setting_id'];
            if (! isset($bySetting[$sid])) {
                $bySetting[$sid] = [
                    'setting_id' => $sid,
                    'setting_name' => $st['setting_name'],
                    'current_total_minor' => 0,
                    'proposed_total_minor' => 0,
                    'current_effective_paid_minor' => 0,
                    'proposed_effective_paid_minor' => 0,
                    'current_due_minor' => 0,
                    'proposed_due_minor' => 0,
                ];
            }

            $bySetting[$sid]['current_total_minor'] += $st['current_total_minor'];
            $bySetting[$sid]['proposed_total_minor'] += $st['proposed_total_minor'];
            $bySetting[$sid]['current_effective_paid_minor'] += $st['current_effective_paid_minor'];
            $bySetting[$sid]['proposed_effective_paid_minor'] += $st['proposed_effective_paid_minor'];
            $bySetting[$sid]['current_due_minor'] += $st['current_due_minor'];
            $bySetting[$sid]['proposed_due_minor'] += $st['proposed_due_minor'];
        }

        $result = [];
        foreach ($bySetting as $sid => $row) {
            $result[] = [
                'setting_id' => $sid,
                'setting_name' => $row['setting_name'],
                'current_total' => $this->transferService->fromMinor($row['current_total_minor']),
                'proposed_total' => $this->transferService->fromMinor($row['proposed_total_minor']),
                'current_effective_paid' => $this->transferService->fromMinor($row['current_effective_paid_minor']),
                'proposed_effective_paid' => $this->transferService->fromMinor($row['proposed_effective_paid_minor']),
                'current_due' => $this->transferService->fromMinor($row['current_due_minor']),
                'proposed_due' => $this->transferService->fromMinor($row['proposed_due_minor']),
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $saleStates
     * @param array<int, array<string, mixed>> $plannedTransfers
     * @return array<int, array<string, mixed>>
     */
    protected function buildMethodSummaries(array $saleStates, array $plannedTransfers): array
    {
        $currentByMethod = [];
        $proposedByMethod = [];

        foreach ($saleStates as $st) {
            /** @var Sale $sale */
            $sale = $st['sale'];
            foreach ($sale->salePayments->filter(fn (SalePayment $p) => $p->isActive()) as $payment) {
                $method = (string) ($payment->payment_method ?: 'CASH');
                $minor = $this->transferService->toMinor((float) $payment->amount);
                $currentByMethod[$method] = ($currentByMethod[$method] ?? 0) + $minor;

                if (! $this->isSyntheticReplacementPayment($payment)) {
                    $proposedByMethod[$method] = ($proposedByMethod[$method] ?? 0) + $minor;
                }
            }
        }

        $methods = array_unique(array_merge(array_keys($currentByMethod), array_keys($proposedByMethod)));
        sort($methods);

        $summaries = [];
        foreach ($methods as $method) {
            $currMinor = (int) ($currentByMethod[$method] ?? 0);
            $propMinor = (int) ($proposedByMethod[$method] ?? 0);
            $transferredMinor = (int) collect($plannedTransfers)->where('payment_method', $method)->sum('amount_minor');

            $summaries[] = [
                'payment_method' => $method,
                'current_collection_total' => $this->transferService->fromMinor($currMinor),
                'proposed_collection_total' => $this->transferService->fromMinor($propMinor),
                'owner_transfer_allocation_total' => $this->transferService->fromMinor($transferredMinor),
            ];
        }

        return $summaries;
    }

    /**
     * @param Collection<int, PosTransaction> $groupTransactions
     * @param array<int, array<string, mixed>> $saleStates
     * @param Collection<int, PosReturn> $completedReturns
     * @return array<int, array<string, mixed>>
     */
    protected function buildExpectedReceiptLinesPreview(
        Collection $groupTransactions,
        array $saleStates,
        Collection $completedReturns
    ): array {
        $allTxnLines = $groupTransactions->flatMap(fn (PosTransaction $t) => $t->lines)->unique('id')->sortBy('id')->values();
        $allDetails = collect($saleStates)->flatMap(fn (array $st) => $st['sale']->saleDetails)->values();

        $previewLines = [];
        foreach ($allTxnLines as $txnLine) {
            $mappedDetails = $allDetails->filter(function (SaleDetails $d) use ($txnLine) {
                if (! empty($d->pos_transaction_line_id)) {
                    return (int) $d->pos_transaction_line_id === (int) $txnLine->id;
                }
                return (int) $d->product_id === (int) $txnLine->product_id;
            })->filter(fn (SaleDetails $d) => (float) ($d->quantity ?? 0) > 0.0001)->values();

            $qty = (float) $mappedDetails->sum(fn (SaleDetails $d) => (float) ($d->quantity ?? 0));
            $subTotalMinor = (int) $mappedDetails->sum(fn (SaleDetails $d) => $this->transferService->toMinor((float) ($d->sub_total ?? 0)));

            $previewLines[] = [
                'pos_transaction_line_id' => (int) $txnLine->id,
                'product_id' => (int) $txnLine->product_id,
                'product_name' => (string) $txnLine->product_name_snapshot,
                'qty' => $qty,
                'sub_total' => $this->transferService->fromMinor($subTotalMinor),
            ];
        }

        return $previewLines;
    }

    /**
     * @param Collection<int, Sale> $sales
     * @param Collection<int, PosReturn> $completedReturns
     * @param array<int, array<string, mixed>> $plannedInvalidations
     * @param array<int, array<string, mixed>> $plannedTransfers
     * @param array<int, array<string, mixed>> $saleStates
     * @return array<string, mixed>
     */
    protected function buildFingerprintPayload(
        int $settingId,
        PosTransaction $transaction,
        PosCheckout $checkout,
        Collection $sales,
        Collection $completedReturns,
        array $plannedInvalidations,
        array $plannedTransfers,
        array $saleStates
    ): array {
        return [
            'setting_id' => $settingId,
            'transaction_id' => (int) $transaction->id,
            'checkout_id' => (int) $checkout->id,
            'sales' => $sales->sortBy('id')->values()->map(fn (Sale $s) => [
                'id' => (int) $s->id,
                'setting_id' => (int) $s->setting_id,
                'reference' => (string) $s->reference,
                'total_minor' => $this->transferService->toMinor((float) $s->total_amount),
                'paid_minor' => $this->transferService->toMinor((float) $s->paid_amount),
                'due_minor' => $this->transferService->toMinor((float) $s->due_amount),
                'details' => $s->saleDetails->sortBy('id')->values()->map(fn (SaleDetails $d) => [
                    'id' => (int) $d->id,
                    'product_id' => (int) $d->product_id,
                    'qty' => (float) $d->quantity,
                    'sub_total_minor' => $this->transferService->toMinor((float) $d->sub_total),
                ])->all(),
                'payments' => $s->salePayments->sortBy('id')->values()->map(fn (SalePayment $p) => [
                    'id' => (int) $p->id,
                    'reference' => (string) $p->reference,
                    'status' => (string) $p->status,
                    'amount_minor' => $this->transferService->toMinor((float) $p->amount),
                ])->all(),
                'incoming_transfers' => $s->incomingOwnerPaymentTransfers->sortBy('id')->values()->map(fn (PosOwnerPaymentTransfer $t) => [
                    'id' => (int) $t->id,
                    'execution_key' => (string) $t->execution_key,
                    'amount_minor' => (int) $t->amount_minor,
                ])->all(),
                'outgoing_transfers' => $s->outgoingOwnerPaymentTransfers->sortBy('id')->values()->map(fn (PosOwnerPaymentTransfer $t) => [
                    'id' => (int) $t->id,
                    'execution_key' => (string) $t->execution_key,
                    'amount_minor' => (int) $t->amount_minor,
                ])->all(),
            ])->all(),
            'returns' => $completedReturns->sortBy('id')->values()->map(fn (PosReturn $r) => [
                'id' => (int) $r->id,
                'reference' => (string) $r->reference,
                'status' => (string) $r->status,
                'total_minor' => $this->transferService->toMinor((float) $r->total_amount),
            ])->all(),
            'planned_invalidations' => array_values($plannedInvalidations),
            'planned_transfers' => $plannedTransfers,
            'proposed_sales' => array_values(array_map(fn (array $st) => [
                'sale_id' => $st['sale_id'],
                'proposed_total_minor' => $st['proposed_total_minor'],
                'proposed_paid_minor' => $st['proposed_paid_minor'],
                'proposed_due_minor' => $st['proposed_due_minor'],
            ], $saleStates)),
        ];
    }

    /**
     * @param array<int, string> $blockReasons
     * @return array<string, mixed>
     */
    protected function blockedPlan(?int $transactionId, string $transactionCode, array $blockReasons): array
    {
        return [
            'classification' => self::CLASSIFICATION_BLOCKED,
            'reason' => implode(' ', $blockReasons),
            'block_reasons' => $blockReasons,
            'transaction_id' => $transactionId,
            'transaction_code' => $transactionCode,
            'checkout_id' => null,
            'evidence_fingerprint' => null,
            'sales' => [],
            'owner_summaries' => [],
            'method_summaries' => [],
            'expected_receipt_lines' => [],
            'planned_invalidations' => [],
            'planned_transfers' => [],
            'summary' => [],
        ];
    }
}
