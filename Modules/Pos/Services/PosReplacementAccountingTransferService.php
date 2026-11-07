<?php

namespace Modules\Pos\Services;

use Illuminate\Support\Collection;
use Modules\Pos\Entities\PosOwnerPaymentTransfer;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Setting\Entities\Setting;

/**
 * Plans and applies conserved commercial charges and owner payment allocation transfers
 * for cross-owner POS product replacements.
 *
 * Owner Payment Allocation Semantics:
 * - Customer money is collected once on the original Sale (`SalePayment`).
 * - When a cross-owner replacement creates a replacement-owner Sale, commercial value moves
 *   from the current source Sale to the replacement-owner Sale, and backed paid value moves via
 *   `PosOwnerPaymentTransfer` records rather than creating a new customer collection (`SalePayment`).
 * - Effective paid amount for any Sale equals:
 *     ACTIVE `SalePayment` monetary sum
 *   + ACTIVE `SalePayment` credit application sum
 *   + incoming `PosOwnerPaymentTransfer` sum (`replacement_sale_id = sale.id`)
 *   - outgoing `PosOwnerPaymentTransfer` sum (`source_sale_id = sale.id`)
 * - Raw customer collection reports count original `SalePayment` records once and expose
 *   `PosOwnerPaymentTransfer` records separately as owner allocation adjustments.
 */
class PosReplacementAccountingTransferService
{
    /**
     * Capture the pre-execution accounting state of a source Sale before detail reductions or cash-return recalculation.
     *
     * @return array<string, mixed>
     */
    public function capturePreExecutionSaleState(Sale $sale): array
    {
        $sale->loadMissing([
            'saleDetails',
            'bundleItems.saleDetail',
            'salePayments.creditApplications',
            'salePayments.paymentMethod',
            'incomingOwnerPaymentTransfers.originalSalePayment',
            'outgoingOwnerPaymentTransfers',
        ]);

        $details = [];
        foreach ($sale->saleDetails as $detail) {
            $details[(int) $detail->id] = [
                'id' => (int) $detail->id,
                'product_id' => (int) $detail->product_id,
                'pos_transaction_line_id' => $detail->pos_transaction_line_id ? (int) $detail->pos_transaction_line_id : null,
                'quantity' => (float) ($detail->quantity ?? 0),
                'unit_price' => (float) ($detail->unit_price ?? $detail->price ?? 0),
                'sub_total_minor' => $this->toMinor((float) ($detail->sub_total ?? 0)),
                'discount_minor' => $this->toMinor((float) ($detail->product_discount_amount ?? 0)),
                'tax_minor' => $this->toMinor((float) ($detail->product_tax_amount ?? 0)),
                'tax_id' => $detail->tax_id ? (int) $detail->tax_id : null,
            ];
        }

        $bundleItems = [];
        foreach ($sale->bundleItems as $bundleItem) {
            $bundleItems[(int) $bundleItem->id] = [
                'id' => (int) $bundleItem->id,
                'sale_detail_id' => $bundleItem->sale_detail_id ? (int) $bundleItem->sale_detail_id : null,
                'product_id' => (int) $bundleItem->product_id,
                'quantity' => (float) ($bundleItem->quantity ?? 0),
                'price' => (float) ($bundleItem->price ?? 0),
                'sub_total_minor' => $this->toMinor((float) ($bundleItem->sub_total ?? 0)),
                'tax_minor' => $this->toMinor((float) ($bundleItem->tax_amount ?? 0)),
                'tax_id' => ($bundleItem->inherited_tax_id ?? $bundleItem->tax_id) ? (int) ($bundleItem->inherited_tax_id ?? $bundleItem->tax_id) : null,
            ];
        }

        $paymentSlots = $this->resolveAvailablePaymentSlots($sale);
        $availablePaidMinor = array_sum(array_column($paymentSlots, 'available_minor'));
        $creditMinor = $this->resolveActiveCreditApplicationsMinor($sale);

        return [
            'sale_id' => (int) $sale->id,
            'setting_id' => (int) $sale->setting_id,
            'is_pkp' => (bool) (Setting::query()->whereKey((int) $sale->setting_id)->value('is_pkp') ?? false),
            'total_minor' => $this->toMinor((float) ($sale->total_amount ?? 0)),
            'paid_minor' => $this->toMinor((float) ($sale->paid_amount ?? 0)),
            'due_minor' => $this->toMinor((float) ($sale->due_amount ?? 0)),
            'discount_minor' => $this->toMinor((float) ($sale->discount_amount ?? 0)),
            'discount_percentage' => (float) ($sale->discount_percentage ?? 0),
            'tax_minor' => $this->toMinor((float) ($sale->tax_amount ?? 0)),
            'tax_percentage' => (float) ($sale->tax_percentage ?? 0),
            'tax_id' => $sale->tax_id ? (int) $sale->tax_id : null,
            'shipping_minor' => $this->toMinor((float) ($sale->shipping_amount ?? 0)),
            'payment_status' => (string) ($sale->payment_status ?? 'Paid'),
            'payment_method' => (string) ($sale->payment_method ?? 'CASH'),
            'details' => $details,
            'bundle_items' => $bundleItems,
            'payment_slots' => $paymentSlots,
            'available_paid_minor' => $availablePaidMinor,
            'active_credit_minor' => $creditMinor,
            'effective_paid_minor' => $availablePaidMinor + $creditMinor,
        ];
    }

    /**
     * Plan commercial charge and payment allocation transfers from pre-execution source state.
     *
     * @param array<string, mixed> $preState
     * @param Collection<int, SaleReturnDetail> $crossOwnerDetails
     * @param Collection<int, SaleReturnDetail>|null $cashReturnDetails
     * @return array<string, mixed>
     */
    public function planSourceSaleTransfers(
        Sale $sourceSale,
        array $preState,
        Collection $crossOwnerDetails,
        ?Collection $cashReturnDetails = null
    ): array {
        $cashReturnDetails = $cashReturnDetails ?? collect();

        if ($crossOwnerDetails->isEmpty()) {
            return [
                'source_sale_id' => (int) $sourceSale->id,
                'source_total_before_minor' => (int) $preState['total_minor'],
                'cash_return_minor' => 0,
                'total_transferred_charge_minor' => 0,
                'source_total_after_minor' => (int) $preState['total_minor'],
                'total_transferred_paid_minor' => 0,
                'source_paid_after_minor' => (int) $preState['effective_paid_minor'],
                'source_due_after_minor' => max(0, (int) $preState['total_minor'] - (int) $preState['effective_paid_minor']),
                'detail_plans' => [],
            ];
        }

        if ((int) ($preState['active_credit_minor'] ?? 0) > 0 && $crossOwnerDetails->isNotEmpty()) {
            throw new \RuntimeException('Alokasi penggantian lintas pemilik dari penjualan yang dibayar dengan aplikasi kredit retur belum didukung secara otomatis.');
        }

        $preTotalMinor = (int) $preState['total_minor'];
        $shippingMinor = max(0, (int) ($preState['shipping_minor'] ?? 0));
        $headerDiscountMinor = max(0, (int) ($preState['discount_minor'] ?? 0));

        // Calculate pre-execution gross commercial subtotal across all active rows
        $grossRowsMinor = $this->calculatePreExecutionGrossSubtotalMinor($preState);
        $netCommercialPoolMinor = max(0, $preTotalMinor - $shippingMinor);

        // Validate that header adjustments are explainable from persisted detail/bundle evidence
        if ($grossRowsMinor <= 0 && $netCommercialPoolMinor > 0) {
            throw new \RuntimeException(' Bukti rincian komersial sumber tidak mencukupi untuk menghitung transfer nilai penggantian lintas pemilik.');
        }

        // If header net commercial pool exceeds gross rows without persisted tax evidence, block unsupported header surcharge
        $preTaxMinor = max(0, (int) ($preState['tax_minor'] ?? 0));
        if ($netCommercialPoolMinor > $grossRowsMinor + $preTaxMinor + 1) {
            throw new \RuntimeException('Penjualan sumber memiliki selisih biaya header yang tidak dapat dialokasikan secara deterministik ke penggantian lintas pemilik.');
        }

        $cashReturnMinor = 0;
        foreach ($cashReturnDetails as $cashDetail) {
            $cashReturnMinor += $this->resolveCashReturnDetailMinor($cashDetail, $preState);
        }

        // Compute raw commercial basis for each cross-owner replacement detail from pre-execution state
        $rawDetailWeights = [];
        $detailContexts = [];

        foreach ($crossOwnerDetails as $detail) {
            $detailId = (int) $detail->id;
            $resolved = $this->resolveReplacementDetailCommercialBasis($detail, $preState, $grossRowsMinor, $netCommercialPoolMinor, $headerDiscountMinor);
            $rawDetailWeights[$detailId] = $resolved['charge_minor'];
            $detailContexts[$detailId] = $resolved;
        }

        $totalReplacementChargeMinor = array_sum($rawDetailWeights);

        if ($cashReturnMinor + $totalReplacementChargeMinor > $preTotalMinor) {
            // Check if difference is within rounding remainder when all items are consumed
            if ($this->allCommercialUnitsConsumed($preState, $crossOwnerDetails, $cashReturnDetails) && $shippingMinor === 0) {
                $availableForReplacementMinor = max(0, $preTotalMinor - $cashReturnMinor);
                $rawDetailWeights = $this->allocateMinorProportionally($availableForReplacementMinor, $rawDetailWeights);
                $totalReplacementChargeMinor = array_sum($rawDetailWeights);
            } else {
                throw new \RuntimeException('Total nilai pengembalian tunai dan penggantian lintas pemilik melebihi total tagihan penjualan sumber.');
            }
        }

        $sourceTotalAfterCashMinor = max(0, $preTotalMinor - $cashReturnMinor);
        $sourceTotalAfterAllMinor = max(0, $sourceTotalAfterCashMinor - $totalReplacementChargeMinor);

        // Determine available payment slots on the source sale AFTER cash return settlement
        // Note: Cash returns reduce unpaid debt first; only excess over new total after cash return is refunded.
        // Wait: if cash return and replacement happen together in one PosReturn, what is the available paid amount?
        // Customer's pre-execution paid amount on sourceSale is $prePaidMinor.
        // Total customer charge retained across (source remaining + replacement sales) is ($preTotalMinor - $cashReturnMinor).
        // Therefore, cash return only refunds max(0, $prePaidMinor - ($preTotalMinor - $cashReturnMinor))!
        $prePaidMinor = (int) $preState['available_paid_minor'];
        $actualCashRefundMinor = max(0, $prePaidMinor - $sourceTotalAfterCashMinor);
        $retainedPaidPoolMinor = max(0, $prePaidMinor - $actualCashRefundMinor);

        if ($prePaidMinor === 0 && (int) ($preState['paid_minor'] ?? 0) > 0) {
            throw new \RuntimeException('Penjualan sumber mencatat nominal terbayar tanpa bukti pembayaran aktif atau transfer yang valid.');
        }

        // How much of $retainedPaidPoolMinor transfers to the replacement sales vs stays on source sale?
        // Design decision #2:
        // "When payment evidence explicitly assigns a paid portion, preserve that allocation.
        // Otherwise, for a valid source sale with unassigned payments, allocate paid value proportionally to
        // transferred commercial charge relative to pre-transfer source charge, in minor units; distribute methods
        // deterministically by existing available method amounts. Transfer no paid value for an unpaid sale,
        // and move the remaining charge as debt."
        $preTransferChargeBaseMinor = $sourceTotalAfterCashMinor;
        $detailPaidTargetsMinor = [];

        if ($retainedPaidPoolMinor <= 0 || $preTransferChargeBaseMinor <= 0) {
            foreach ($rawDetailWeights as $detailId => $chargeMinor) {
                $detailPaidTargetsMinor[$detailId] = 0;
            }
        } elseif ($retainedPaidPoolMinor >= $preTransferChargeBaseMinor) {
            // Source sale (after any cash return) is fully paid: 100% of each replacement charge is paid
            foreach ($rawDetailWeights as $detailId => $chargeMinor) {
                $detailPaidTargetsMinor[$detailId] = $chargeMinor;
            }
        } else {
            // Partially paid source sale: allocate paid amount proportionally across (remaining source charge + each replacement detail charge)
            $buckets = ['__source_retained__' => $sourceTotalAfterAllMinor];
            foreach ($rawDetailWeights as $detailId => $chargeMinor) {
                $buckets[(string) $detailId] = $chargeMinor;
            }
            $allocatedBuckets = $this->allocateMinorProportionally($retainedPaidPoolMinor, $buckets);
            foreach ($rawDetailWeights as $detailId => $chargeMinor) {
                $detailPaidTargetsMinor[$detailId] = min($chargeMinor, (int) ($allocatedBuckets[(string) $detailId] ?? 0));
            }
        }

        // Deterministically allocate payment slots (preserving payment provenance & methods) across detailPaidTargetsMinor
        // Note: if actualCashRefundMinor > 0, reconcileActivePaymentsForSale invalidates/splits from latest stage_order/date/id desc,
        // so the retained payment slots are consumed from earliest (stage_order asc, date asc, id asc).
        $availableSlots = $this->trimPaymentSlotsToRetainedPool($preState['payment_slots'], $retainedPaidPoolMinor);
        $detailPlans = [];
        $totalTransferredPaidMinor = 0;

        foreach ($crossOwnerDetails as $detail) {
            $detailId = (int) $detail->id;
            $chargeMinor = (int) $rawDetailWeights[$detailId];
            $paidTargetMinor = (int) ($detailPaidTargetsMinor[$detailId] ?? 0);
            $dueMinor = max(0, $chargeMinor - $paidTargetMinor);

            $allocations = $this->consumePaymentSlotsForTarget($availableSlots, $paidTargetMinor);
            $allocatedSumMinor = array_sum(array_column($allocations, 'amount_minor'));

            if ($allocatedSumMinor !== $paidTargetMinor) {
                throw new \RuntimeException("Bukti pembayaran sumber tidak mencukupi untuk mengalokasikan Rp" . number_format($paidTargetMinor / 100, 2) . " ke penjualan pengganti.");
            }

            $totalTransferredPaidMinor += $allocatedSumMinor;

            $detailPlans[$detailId] = [
                'sale_return_detail_id' => $detailId,
                'pos_return_line_id' => $detail->pos_return_line_id ? (int) $detail->pos_return_line_id : null,
                'charge_minor' => $chargeMinor,
                'charge_amount' => $this->fromMinor($chargeMinor),
                'paid_minor' => $allocatedSumMinor,
                'paid_amount' => $this->fromMinor($allocatedSumMinor),
                'due_minor' => $dueMinor,
                'due_amount' => $this->fromMinor($dueMinor),
                'tax_minor' => (int) ($detailContexts[$detailId]['tax_minor'] ?? 0),
                'tax_amount' => $this->fromMinor((int) ($detailContexts[$detailId]['tax_minor'] ?? 0)),
                'discount_minor' => (int) ($detailContexts[$detailId]['discount_minor'] ?? 0),
                'discount_amount' => $this->fromMinor((int) ($detailContexts[$detailId]['discount_minor'] ?? 0)),
                'gross_subtotal_minor' => (int) ($detailContexts[$detailId]['gross_subtotal_minor'] ?? $chargeMinor),
                'gross_subtotal_amount' => $this->fromMinor((int) ($detailContexts[$detailId]['gross_subtotal_minor'] ?? $chargeMinor)),
                'payment_allocations' => $allocations,
            ];
        }

        $sourcePaidAfterMinor = max(0, $retainedPaidPoolMinor - $totalTransferredPaidMinor);
        $sourceDueAfterMinor = max(0, $sourceTotalAfterAllMinor - $sourcePaidAfterMinor);

        // Conservation assertion
        $preDueAfterCashMinor = max(0, $sourceTotalAfterCashMinor - $retainedPaidPoolMinor);
        $postAggregateTotalMinor = $sourceTotalAfterAllMinor + $totalReplacementChargeMinor;
        $postAggregatePaidMinor = $sourcePaidAfterMinor + $totalTransferredPaidMinor;
        $postAggregateDueMinor = $sourceDueAfterMinor + array_sum(array_column($detailPlans, 'due_minor'));

        if ($postAggregateTotalMinor !== $sourceTotalAfterCashMinor
            || $postAggregatePaidMinor !== $retainedPaidPoolMinor
            || $postAggregateDueMinor !== $preDueAfterCashMinor) {
            throw new \RuntimeException('Pelanggaran kekekalan akuntansi penggantian: total tagihan, pembayaran efektif, atau sisa utang berubah.');
        }

        return [
            'source_sale_id' => (int) $sourceSale->id,
            'source_total_before_minor' => $preTotalMinor,
            'cash_return_minor' => $cashReturnMinor,
            'actual_cash_refund_minor' => $actualCashRefundMinor,
            'source_total_after_cash_minor' => $sourceTotalAfterCashMinor,
            'total_transferred_charge_minor' => $totalReplacementChargeMinor,
            'source_total_after_minor' => $sourceTotalAfterAllMinor,
            'source_total_after_amount' => $this->fromMinor($sourceTotalAfterAllMinor),
            'retained_paid_pool_minor' => $retainedPaidPoolMinor,
            'total_transferred_paid_minor' => $totalTransferredPaidMinor,
            'source_paid_after_minor' => $sourcePaidAfterMinor,
            'source_paid_after_amount' => $this->fromMinor($sourcePaidAfterMinor),
            'source_due_after_minor' => $sourceDueAfterMinor,
            'source_due_after_amount' => $this->fromMinor($sourceDueAfterMinor),
            'detail_plans' => $detailPlans,
        ];
    }

    /**
     * Resolve available payment slots (direct active SalePayments + incoming PosOwnerPaymentTransfers minus already transferred outgoing amounts)
     * in deterministic order: stage_order ASC, date ASC, id ASC.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolveAvailablePaymentSlots(Sale $sale): array
    {
        $sale->loadMissing([
            'salePayments.paymentMethod',
            'incomingOwnerPaymentTransfers.originalSalePayment',
            'outgoingOwnerPaymentTransfers',
        ]);

        $outgoingRows = $sale->outgoingOwnerPaymentTransfers;

        $slots = [];

        // 1. Direct ACTIVE SalePayments on this Sale
        $activePayments = $sale->salePayments
            ->filter(fn (SalePayment $p) => $p->isActive())
            ->values()
            ->all();

        usort($activePayments, function (SalePayment $a, SalePayment $b): int {
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

        foreach ($activePayments as $payment) {
            $paymentMinor = $this->toMinor((float) $payment->amount);
            $usedMinor = (int) $outgoingRows
                ->where('original_sale_payment_id', (int) $payment->id)
                ->whereNull('predecessor_transfer_id')
                ->sum('amount_minor');

            $availableMinor = max(0, $paymentMinor - $usedMinor);
            if ($availableMinor <= 0) {
                continue;
            }

            $methodName = $this->resolvePaymentMethodLabel($payment);

            $slots[] = [
                'slot_type' => 'direct_payment',
                'original_sale_payment_id' => (int) $payment->id,
                'predecessor_transfer_id' => null,
                'payment_method_id' => $payment->payment_method_id ? (int) $payment->payment_method_id : null,
                'payment_method' => $methodName,
                'available_minor' => $availableMinor,
                'date' => (string) ($payment->getRawOriginal('date') ?? $payment->date ?? ''),
            ];
        }

        // 2. Incoming PosOwnerPaymentTransfers (when this Sale is itself a replacement Sale that later becomes a source Sale)
        $incomingTransfers = $sale->incomingOwnerPaymentTransfers
            ->values()
            ->all();

        usort($incomingTransfers, function (PosOwnerPaymentTransfer $a, PosOwnerPaymentTransfer $b): int {
            $dateCmp = strcmp((string) ($a->executed_at ?? ''), (string) ($b->executed_at ?? ''));
            if ($dateCmp !== 0) {
                return $dateCmp;
            }
            return ((int) $a->id) <=> ((int) $b->id);
        });

        foreach ($incomingTransfers as $incoming) {
            $origPayment = $incoming->originalSalePayment;
            if ($origPayment && $origPayment->isInvalidated()) {
                continue;
            }

            $incomingMinor = (int) $incoming->amount_minor;
            $usedMinor = (int) $outgoingRows
                ->where('predecessor_transfer_id', (int) $incoming->id)
                ->sum('amount_minor');

            $availableMinor = max(0, $incomingMinor - $usedMinor);
            if ($availableMinor <= 0) {
                continue;
            }

            $slots[] = [
                'slot_type' => 'incoming_transfer',
                'original_sale_payment_id' => (int) $incoming->original_sale_payment_id,
                'predecessor_transfer_id' => (int) $incoming->id,
                'payment_method_id' => $incoming->payment_method_id ? (int) $incoming->payment_method_id : null,
                'payment_method' => (string) ($incoming->payment_method ?: 'CASH'),
                'available_minor' => $availableMinor,
                'date' => $origPayment ? (string) ($origPayment->getRawOriginal('date') ?? $origPayment->date ?? '') : '',
            ];
        }

        return $slots;
    }

    /**
     * Persist planned PosOwnerPaymentTransfer rows for a replacement Sale.
     *
     * @param array<string, mixed> $detailPlan
     * @return Collection<int, PosOwnerPaymentTransfer>
     */
    public function persistPlannedTransfersForDetail(
        PosReturn $posReturn,
        SaleReturnDetail $detail,
        Sale $sourceSale,
        Sale $replacementSale,
        array $detailPlan,
        ?int $actorId = null,
        ?\Carbon\CarbonInterface $executedAt = null,
        string $transferSource = PosOwnerPaymentTransfer::SOURCE_LIVE_EXECUTION,
        ?int $invalidatedSyntheticPaymentId = null
    ): Collection {
        $executedAt = $executedAt ?? now();
        $created = collect();

        foreach ($detailPlan['payment_allocations'] ?? [] as $allocation) {
            $amountMinor = (int) ($allocation['amount_minor'] ?? 0);
            if ($amountMinor <= 0) {
                continue;
            }

            $originalPaymentId = (int) $allocation['original_sale_payment_id'];
            $predecessorId = isset($allocation['predecessor_transfer_id']) && $allocation['predecessor_transfer_id'] !== null
                ? (int) $allocation['predecessor_transfer_id']
                : null;

            $executionKey = PosOwnerPaymentTransfer::buildExecutionKey(
                (int) $detail->id,
                $originalPaymentId,
                $predecessorId
            );

            $existing = PosOwnerPaymentTransfer::query()
                ->where('execution_key', $executionKey)
                ->first();

            if ($existing) {
                $created->push($existing);
                continue;
            }

            $transfer = PosOwnerPaymentTransfer::query()->create([
                'execution_key' => $executionKey,
                'pos_transaction_id' => $posReturn->pos_transaction_id ? (int) $posReturn->pos_transaction_id : null,
                'pos_checkout_id' => $posReturn->pos_checkout_id ? (int) $posReturn->pos_checkout_id : null,
                'pos_return_id' => (int) $posReturn->id,
                'pos_return_line_id' => $detail->pos_return_line_id ? (int) $detail->pos_return_line_id : null,
                'sale_return_id' => $detail->sale_return_id ? (int) $detail->sale_return_id : null,
                'sale_return_detail_id' => (int) $detail->id,
                'source_sale_id' => (int) $sourceSale->id,
                'replacement_sale_id' => (int) $replacementSale->id,
                'original_sale_payment_id' => $originalPaymentId,
                'predecessor_transfer_id' => $predecessorId,
                'invalidated_synthetic_payment_id' => $invalidatedSyntheticPaymentId,
                'payment_method_id' => isset($allocation['payment_method_id']) ? (int) $allocation['payment_method_id'] : null,
                'payment_method' => (string) ($allocation['payment_method'] ?? 'CASH'),
                'amount' => $this->fromMinor($amountMinor),
                'amount_minor' => $amountMinor,
                'transfer_source' => $transferSource,
                'actor_id' => $actorId,
                'executed_at' => $executedAt,
                'metadata' => [
                    'charge_minor' => (int) ($detailPlan['charge_minor'] ?? 0),
                    'paid_minor' => (int) ($detailPlan['paid_minor'] ?? 0),
                    'due_minor' => (int) ($detailPlan['due_minor'] ?? 0),
                ],
            ]);

            $created->push($transfer);
        }

        return $created;
    }

    /**
     * Resolve effective retained payment breakdown by payment method across a collection of reachable Sales,
     * accounting for active SalePayments, credit applications, and owner payment transfers.
     *
     * @param iterable<Sale> $sales
     * @return array<int, array{method_name: string, amount: float, amount_minor: int}>
     */
    public function resolveEffectivePaymentBreakdownForSales(iterable $sales): array
    {
        $salesCollection = collect($sales);
        if ($salesCollection->isEmpty()) {
            return [];
        }

        $saleIds = $salesCollection->pluck('id')->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();

        $outgoingByPaymentId = PosOwnerPaymentTransfer::query()
            ->whereIn('source_sale_id', $saleIds)
            ->get()
            ->groupBy('original_sale_payment_id');

        $incomingTransforms = PosOwnerPaymentTransfer::query()
            ->with(['originalSalePayment', 'paymentMethodRecord'])
            ->whereIn('replacement_sale_id', $saleIds)
            ->get();

        $outgoingByPredecessorId = PosOwnerPaymentTransfer::query()
            ->whereIn('source_sale_id', $saleIds)
            ->whereNotNull('predecessor_transfer_id')
            ->get()
            ->groupBy('predecessor_transfer_id');

        $methodMinorTotals = [];

        foreach ($salesCollection as $sale) {
            $activePayments = ($sale->relationLoaded('salePayments') ? $sale->salePayments : $sale->salePayments()->with('paymentMethod')->get())
                ->filter(fn (SalePayment $p) => $p->isActive());

            foreach ($activePayments as $payment) {
                $paymentMinor = $this->toMinor((float) $payment->amount);
                $directOutgoingMinor = (int) ($outgoingByPaymentId->get((int) $payment->id)?->whereNull('predecessor_transfer_id')->sum('amount_minor') ?? 0);
                $retainedOnSaleMinor = max(0, $paymentMinor - $directOutgoingMinor);

                if ($retainedOnSaleMinor <= 0) {
                    continue;
                }

                $methodName = $this->resolvePaymentMethodDisplayName($payment);
                $methodMinorTotals[$methodName] = ($methodMinorTotals[$methodName] ?? 0) + $retainedOnSaleMinor;
            }
        }

        foreach ($incomingTransforms as $incoming) {
            if ($incoming->originalSalePayment && $incoming->originalSalePayment->isInvalidated()) {
                continue;
            }

            $incomingMinor = (int) $incoming->amount_minor;
            $subsequentOutgoingMinor = (int) ($outgoingByPredecessorId->get((int) $incoming->id)?->sum('amount_minor') ?? 0);
            $retainedIncomingMinor = max(0, $incomingMinor - $subsequentOutgoingMinor);

            if ($retainedIncomingMinor <= 0) {
                continue;
            }

            $methodName = $this->resolveTransferMethodDisplayName($incoming);
            $methodMinorTotals[$methodName] = ($methodMinorTotals[$methodName] ?? 0) + $retainedIncomingMinor;
        }

        $breakdown = [];
        foreach ($methodMinorTotals as $methodName => $minor) {
            if ($minor > 0) {
                $breakdown[] = [
                    'method_name' => $methodName,
                    'amount' => $this->fromMinor($minor),
                    'amount_minor' => $minor,
                ];
            }
        }

        return $breakdown;
    }

    /**
     * Allocate an integer minor-unit amount proportionally across weighted buckets using largest remainder,
     * with deterministic tie-breaking by bucket key order.
     *
     * @param array<int|string, int> $weights
     * @return array<int|string, int>
     */
    public function allocateMinorProportionally(int $totalMinor, array $weights): array
    {
        if ($totalMinor <= 0 || empty($weights)) {
            return array_fill_keys(array_keys($weights), 0);
        }

        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0) {
            $keys = array_keys($weights);
            $count = count($keys);
            $base = intdiv($totalMinor, $count);
            $rem = $totalMinor - ($base * $count);
            $result = [];
            foreach ($keys as $idx => $key) {
                $result[$key] = $base + ($idx < $rem ? 1 : 0);
            }
            return $result;
        }

        $allocated = [];
        $remainders = [];
        $distributed = 0;
        $orderIndex = 0;

        foreach ($weights as $key => $weight) {
            $weight = max(0, (int) $weight);
            $exact = ($totalMinor * $weight) / $totalWeight;
            $floor = (int) floor($exact);
            $allocated[$key] = $floor;
            $distributed += $floor;
            $remainders[] = [
                'key' => $key,
                'remainder' => $exact - $floor,
                'weight' => $weight,
                'order' => $orderIndex++,
            ];
        }

        $diff = $totalMinor - $distributed;
        if ($diff > 0) {
            usort($remainders, function (array $a, array $b): int {
                if (abs($a['remainder'] - $b['remainder']) > 1e-9) {
                    return $b['remainder'] <=> $a['remainder'];
                }
                if ($a['weight'] !== $b['weight']) {
                    return $b['weight'] <=> $a['weight'];
                }
                return $a['order'] <=> $b['order'];
            });

            foreach ($remainders as $item) {
                if ($diff <= 0) {
                    break;
                }
                $allocated[$item['key']]++;
                $diff--;
            }
        }

        return $allocated;
    }

    public function toMinor(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public function fromMinor(int $minor): float
    {
        return round($minor / 100, 2);
    }

    protected function resolveReplacementDetailCommercialBasis(
        SaleReturnDetail $detail,
        array $preState,
        int $grossRowsMinor,
        int $netCommercialPoolMinor,
        int $headerDiscountMinor
    ): array {
        $returnedQty = (float) ($detail->quantity ?? 0);
        if ($returnedQty <= 0) {
            throw new \RuntimeException('Kuantitas rincian penggantian lintas pemilik harus lebih besar dari nol.');
        }

        $execCtx = is_array($detail->execution_context) ? $detail->execution_context : [];
        $usesBundleItem = (string) ($execCtx['commercial_value_source'] ?? '') === 'sale_bundle_item';

        if ($usesBundleItem) {
            $bundleItemId = (int) ($execCtx['component_sale_bundle_item_id'] ?? 0);
            $rowSnap = $preState['bundle_items'][$bundleItemId] ?? null;
            if (! $rowSnap) {
                throw new \RuntimeException("Rincian komponen bundel sumber #{$bundleItemId} tidak ditemukan untuk penggantian lintas pemilik.");
            }
        } else {
            $saleDetailId = (int) ($detail->sale_detail_id ?? 0);
            $rowSnap = $preState['details'][$saleDetailId] ?? null;
            if (! $rowSnap) {
                throw new \RuntimeException("Rincian penjualan sumber #{$saleDetailId} tidak ditemukan untuk penggantian lintas pemilik.");
            }
        }

        $preQty = (float) ($rowSnap['quantity'] ?? 0);
        if ($preQty <= 0.0001 || $returnedQty > $preQty + 0.0001) {
            throw new \RuntimeException('Kuantitas penggantian lintas pemilik melebihi kuantitas tersisa pada penjualan sumber.');
        }

        $rowSubtotalMinor = (int) ($rowSnap['sub_total_minor'] ?? 0);
        $rowTaxMinor = (int) ($rowSnap['tax_minor'] ?? 0);
        $rowDiscountMinor = (int) ($rowSnap['discount_minor'] ?? 0);

        // Calculate gross commercial portion for the returned quantity from persisted row subtotal
        if (abs($returnedQty - $preQty) < 0.0001) {
            $grossShareMinor = $rowSubtotalMinor;
            $taxShareMinor = $rowTaxMinor;
            $rowDiscountShareMinor = $rowDiscountMinor;
        } else {
            $ratio = $returnedQty / $preQty;
            $grossShareMinor = (int) round($rowSubtotalMinor * $ratio);
            $taxShareMinor = (int) round($rowTaxMinor * $ratio);
            $rowDiscountShareMinor = (int) round($rowDiscountMinor * $ratio);
        }

        // Apply proportional share of Sale header discount if present
        $headerDiscountShareMinor = 0;
        if ($headerDiscountMinor > 0 && $grossRowsMinor > 0) {
            $allocatedDiscounts = $this->allocateMinorProportionally($headerDiscountMinor, [
                'affected' => $grossShareMinor,
                'other' => max(0, $grossRowsMinor - $grossShareMinor),
            ]);
            $headerDiscountShareMinor = (int) ($allocatedDiscounts['affected'] ?? 0);
        }

        $netChargeMinor = max(0, $grossShareMinor - $headerDiscountShareMinor);

        // Check if header has order-level exclusive tax on top of row subtotals
        $headerTaxMinor = max(0, (int) ($preState['tax_minor'] ?? 0));
        if ($netCommercialPoolMinor > $grossRowsMinor - $headerDiscountMinor && $headerTaxMinor > 0 && $grossRowsMinor > 0) {
            $extraHeaderTaxMinor = max(0, $netCommercialPoolMinor - max(0, $grossRowsMinor - $headerDiscountMinor));
            if ($extraHeaderTaxMinor > 0) {
                $allocatedTax = $this->allocateMinorProportionally($extraHeaderTaxMinor, [
                    'affected' => $grossShareMinor,
                    'other' => max(0, $grossRowsMinor - $grossShareMinor),
                ]);
                $extraShareMinor = (int) ($allocatedTax['affected'] ?? 0);
                $netChargeMinor += $extraShareMinor;
                $taxShareMinor += $extraShareMinor;
            }
        }

        return [
            'gross_subtotal_minor' => $grossShareMinor,
            'discount_minor' => $rowDiscountShareMinor + $headerDiscountShareMinor,
            'tax_minor' => $taxShareMinor,
            'charge_minor' => $netChargeMinor,
        ];
    }

    protected function resolveCashReturnDetailMinor(SaleReturnDetail $detail, array $preState): int
    {
        $execCtx = is_array($detail->execution_context) ? $detail->execution_context : [];
        $planned = (float) ($execCtx['planned_amount'] ?? 0);
        if ($planned > 0) {
            return $this->toMinor($planned);
        }

        if ($detail->sub_total !== null && (float) $detail->sub_total > 0) {
            return $this->toMinor((float) $detail->sub_total);
        }

        $expected = (float) ($detail->posReturnLine?->expected_cash_amount ?? 0);
        if ($expected > 0) {
            return $this->toMinor($expected);
        }

        return $this->toMinor((float) ($detail->quantity ?? 0) * (float) ($detail->unit_price ?? $detail->price ?? 0));
    }

    protected function calculatePreExecutionGrossSubtotalMinor(array $preState): int
    {
        $total = 0;
        $activeDetailIds = [];
        foreach ($preState['details'] ?? [] as $detailId => $detail) {
            if ((float) ($detail['quantity'] ?? 0) > 0.0001) {
                $total += (int) ($detail['sub_total_minor'] ?? 0);
                $activeDetailIds[(int) $detailId] = true;
            }
        }

        foreach ($preState['bundle_items'] ?? [] as $bundleItem) {
            $sdId = $bundleItem['sale_detail_id'] ?? null;
            if ((! $sdId || empty($activeDetailIds[(int) $sdId]))
                && ((float) ($bundleItem['quantity'] ?? 0) > 0.0001 || (int) ($bundleItem['sub_total_minor'] ?? 0) > 0)) {
                $total += (int) ($bundleItem['sub_total_minor'] ?? 0);
            }
        }

        return $total;
    }

    protected function allCommercialUnitsConsumed(
        array $preState,
        Collection $crossOwnerDetails,
        Collection $cashReturnDetails
    ): bool {
        $allDetails = $crossOwnerDetails->concat($cashReturnDetails);

        foreach ($preState['details'] ?? [] as $detailId => $snap) {
            $preQty = (float) ($snap['quantity'] ?? 0);
            if ($preQty <= 0.0001) {
                continue;
            }
            $returnedQty = (float) $allDetails
                ->filter(fn ($d) => (string) data_get($d->execution_context, 'commercial_value_source', '') !== 'sale_bundle_item')
                ->where('sale_detail_id', (int) $detailId)
                ->sum('quantity');

            if ($returnedQty + 0.0001 < $preQty) {
                return false;
            }
        }

        return true;
    }

    protected function resolveActiveCreditApplicationsMinor(Sale $sale): int
    {
        $activePayments = $sale->relationLoaded('salePayments')
            ? $sale->salePayments->filter(fn (SalePayment $p) => $p->isActive())
            : $sale->salePayments()->with('creditApplications')->active()->get();

        $minor = 0;
        foreach ($activePayments as $payment) {
            $credits = $payment->relationLoaded('creditApplications')
                ? $payment->creditApplications
                : $payment->creditApplications()->get();
            foreach ($credits as $credit) {
                $minor += $this->toMinor((float) $credit->amount);
            }
        }

        return $minor;
    }

    /**
     * @param array<int, array<string, mixed>> $slots
     * @return array<int, array<string, mixed>>
     */
    protected function trimPaymentSlotsToRetainedPool(array $slots, int $retainedPoolMinor): array
    {
        if ($retainedPoolMinor <= 0) {
            return [];
        }

        $trimmed = [];
        $remaining = $retainedPoolMinor;

        foreach ($slots as $slot) {
            if ($remaining <= 0) {
                break;
            }

            $avail = min($remaining, (int) ($slot['available_minor'] ?? 0));
            if ($avail <= 0) {
                continue;
            }

            $slotCopy = $slot;
            $slotCopy['available_minor'] = $avail;
            $trimmed[] = $slotCopy;
            $remaining -= $avail;
        }

        return $trimmed;
    }

    /**
     * @param array<int, array<string, mixed>> $slots
     * @return array<int, array<string, mixed>>
     */
    protected function consumePaymentSlotsForTarget(array &$slots, int $targetMinor): array
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
                'predecessor_transfer_id' => $slot['predecessor_transfer_id'] !== null ? (int) $slot['predecessor_transfer_id'] : null,
                'payment_method_id' => $slot['payment_method_id'] !== null ? (int) $slot['payment_method_id'] : null,
                'payment_method' => (string) $slot['payment_method'],
                'amount_minor' => $take,
                'amount' => $this->fromMinor($take),
            ];
        }
        unset($slot);

        return $allocations;
    }

    protected function resolvePaymentMethodLabel(SalePayment $payment): string
    {
        if (! empty($payment->payment_method)) {
            return (string) $payment->payment_method;
        }

        if ($payment->relationLoaded('paymentMethod') && $payment->paymentMethod) {
            return (string) $payment->paymentMethod->name;
        }

        return 'CASH';
    }

    protected function resolvePaymentMethodDisplayName(SalePayment $payment): string
    {
        if ($payment->relationLoaded('paymentMethod') && $payment->paymentMethod) {
            return (string) $payment->paymentMethod->name;
        }

        if (! empty($payment->payment_method_id)) {
            $method = \Modules\Setting\Entities\PaymentMethod::find($payment->payment_method_id);
            if ($method) {
                return (string) $method->name;
            }
        }

        return (string) ($payment->payment_method ?: 'Tunai');
    }

    protected function resolveTransferMethodDisplayName(PosOwnerPaymentTransfer $transfer): string
    {
        if ($transfer->relationLoaded('paymentMethodRecord') && $transfer->paymentMethodRecord) {
            return (string) $transfer->paymentMethodRecord->name;
        }

        if (! empty($transfer->payment_method_id)) {
            $method = \Modules\Setting\Entities\PaymentMethod::find($transfer->payment_method_id);
            if ($method) {
                return (string) $method->name;
            }
        }

        if ($transfer->relationLoaded('originalSalePayment') && $transfer->originalSalePayment) {
            return $this->resolvePaymentMethodDisplayName($transfer->originalSalePayment);
        }

        return (string) ($transfer->payment_method ?: 'Tunai');
    }
}
