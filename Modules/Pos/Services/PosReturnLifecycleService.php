<?php

namespace Modules\Pos\Services;

use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\SalesOrderSerialTracking;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Exceptions\PosReturnManualCorrectionRequiredException;
use Modules\Purchase\Entities\PaymentTerm;

class PosReturnLifecycleService
{
    protected const SALE_PAYMENT_INVALIDATION_SOURCE = 'POS_RETURN_CASH_CORRECTION';

    public function executeApprovalFromPreview(int $posReturnId, ?string $returnOption = null, ?array $approvalPlan = null): void
    {
        $this->runLifecycleMutation($posReturnId, 'execute_approval_from_preview', function () use ($posReturnId, $returnOption, $approvalPlan) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($posReturnId, $returnOption, $approvalPlan) {
                $posReturn = \Modules\Pos\Entities\PosReturn::query()
                    ->whereKey($posReturnId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertManualCorrectionIsNotRequired($posReturn);

                if ($posReturn->status !== \Modules\Pos\Entities\PosReturn::STATUS_PENDING_APPROVAL) {
                    throw new \RuntimeException('Only pending approval returns can be executed from approval preview.');
                }

                if ($returnOption && ! in_array($returnOption, [
                    \Modules\Pos\Entities\PosReturn::OPTION_CASH_RETURN,
                    \Modules\Pos\Entities\PosReturn::OPTION_PRODUCT_REPLACEMENT,
                ], true)) {
                    throw new \RuntimeException("Invalid return option: {$returnOption}");
                }

                if ($approvalPlan !== null) {
                    app(PosReturnApprovalPlanPersistenceService::class)->synchronize($posReturn, $approvalPlan);
                }

                $posReturn = \Modules\Pos\Entities\PosReturn::query()
                    ->with([
                        'lines',
                        'saleReturns.saleReturnDetails.saleReturn',
                        'saleReturns.saleReturnDetails.posReturnLine',
                    ])
                    ->whereKey($posReturnId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertBundleExecutionGuards($posReturn);
                $this->lockApprovalExecutionDependencies($posReturn);

                $actorId = \Illuminate\Support\Facades\Auth::id();
                $approvedAt = now();

                $this->applyApprovalAuditToPosReturn($posReturn, $actorId, $approvedAt, $returnOption);
                $this->syncApprovedSaleReturns($posReturn, $actorId, $approvedAt);
                $this->receiveLinkedSaleReturns($posReturn);
                $this->applyReceivedDispatchQuantityAdjustments($posReturn);
                $this->executeResolvedLineEffects($posReturn, $actorId, $approvedAt);
                $this->finalizeApprovalExecution($posReturn, $actorId, $approvedAt);
            });
        });
    }

    /**
     * Approve a POS return.
     *
     * @param int $posReturnId
     * @param string|null $returnOption
     * @return void
     */
    public function approve(int $posReturnId, ?string $returnOption = null, ?array $approvalPlan = null): void
    {
        $this->runLifecycleMutation($posReturnId, 'approve', function () use ($posReturnId, $returnOption, $approvalPlan) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($posReturnId, $returnOption, $approvalPlan) {
                $posReturn = \Modules\Pos\Entities\PosReturn::query()
                    ->whereKey($posReturnId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertManualCorrectionIsNotRequired($posReturn);

                if ($posReturn->status !== \Modules\Pos\Entities\PosReturn::STATUS_PENDING_APPROVAL) {
                    throw new \Exception("Only pending approval returns can be approved.");
                }

                $actorId = \Illuminate\Support\Facades\Auth::id();
                $approvedAt = now();

                $updateData = [
                    'status' => \Modules\Pos\Entities\PosReturn::STATUS_APPROVED,
                    'approval_status' => \Modules\Pos\Entities\PosReturn::APPROVAL_STATUS_APPROVED,
                    'approved_by' => $actorId,
                    'approved_at' => $approvedAt,
                ];

                if ($returnOption) {
                    if (!in_array($returnOption, [\Modules\Pos\Entities\PosReturn::OPTION_CASH_RETURN, \Modules\Pos\Entities\PosReturn::OPTION_PRODUCT_REPLACEMENT])) {
                        throw new \Exception("Invalid return option: {$returnOption}");
                    }
                    $updateData['return_option'] = $returnOption;
                }

                if ($approvalPlan !== null) {
                    app(PosReturnApprovalPlanPersistenceService::class)->synchronize($posReturn, $approvalPlan);
                }

                $posReturn->update($updateData);

                $this->syncApprovedSaleReturns($posReturn, $actorId, $approvedAt);
            });
        });
    }

    protected function syncApprovedSaleReturns(\Modules\Pos\Entities\PosReturn $posReturn, ?int $actorId, \Illuminate\Support\Carbon $approvedAt): void
    {
        foreach ($posReturn->saleReturns as $saleReturn) {
            $saleReturn->update([
                'status' => 'AWAITING RECEIVING',
                'approval_status' => 'APPROVED',
                'approved_by' => $actorId,
                'approved_at' => $approvedAt,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);
        }
    }

    protected function lockApprovalExecutionDependencies(\Modules\Pos\Entities\PosReturn $posReturn): void
    {
        $lineIds = $posReturn->lines
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        if (! empty($lineIds)) {
            \Modules\Pos\Entities\PosReturnLine::query()
                ->whereIn('id', $lineIds)
                ->lockForUpdate()
                ->get();
        }

        $saleReturns = $posReturn->saleReturns;
        $saleReturnIds = $saleReturns
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        if (! empty($saleReturnIds)) {
            \Modules\SalesReturn\Entities\SaleReturn::query()
                ->whereIn('id', $saleReturnIds)
                ->lockForUpdate()
                ->get();
        }

        $saleReturnDetails = $saleReturns->flatMap(fn ($saleReturn) => $saleReturn->saleReturnDetails);
        $saleReturnDetailIds = $saleReturnDetails
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        if (! empty($saleReturnDetailIds)) {
            \Modules\SalesReturn\Entities\SaleReturnDetail::query()
                ->whereIn('id', $saleReturnDetailIds)
                ->lockForUpdate()
                ->get();
        }

        $saleIds = $posReturn->lines
            ->pluck('sale_id')
            ->merge($saleReturns->pluck('sale_id'))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! empty($saleIds)) {
            \Modules\Sale\Entities\Sale::query()
                ->whereIn('id', $saleIds)
                ->lockForUpdate()
                ->get();

            \Modules\Sale\Entities\SalePayment::query()
                ->whereIn('sale_id', $saleIds)
                ->lockForUpdate()
                ->get();
        }

        $saleDetailIds = $posReturn->lines
            ->pluck('sale_detail_id')
            ->merge($saleReturnDetails->pluck('sale_detail_id'))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! empty($saleDetailIds)) {
            \Modules\Sale\Entities\SaleDetails::query()
                ->whereIn('id', $saleDetailIds)
                ->lockForUpdate()
                ->get();
        }

        $saleBundleItemIds = $saleReturnDetails
            ->map(fn ($detail) => (int) data_get($detail->execution_context, 'component_sale_bundle_item_id', 0))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! empty($saleBundleItemIds)) {
            \Modules\Sale\Entities\SaleBundleItem::query()
                ->whereIn('id', $saleBundleItemIds)
                ->lockForUpdate()
                ->get();
        }

        $dispatchDetailIds = $posReturn->lines
            ->pluck('dispatch_detail_id')
            ->merge($saleReturnDetails->pluck('dispatch_detail_id'))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! empty($dispatchDetailIds)) {
            \Modules\Sale\Entities\DispatchDetail::query()
                ->whereIn('id', $dispatchDetailIds)
                ->lockForUpdate()
                ->get();
        }

        $locationIds = $saleReturnDetails
            ->pluck('location_id')
            ->merge($posReturn->lines->pluck('source_location_id'))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $productIds = $saleReturnDetails
            ->pluck('product_id')
            ->merge($posReturn->lines->pluck('product_id'))
            ->merge($posReturn->lines->pluck('replacement_product_id'))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! empty($locationIds) && ! empty($productIds)) {
            \Modules\Product\Entities\ProductStock::query()
                ->whereIn('product_id', $productIds)
                ->whereIn('location_id', $locationIds)
                ->lockForUpdate()
                ->get();
        }

        $serialIds = collect();

        foreach ($posReturn->lines as $line) {
            $serialIds = $serialIds
                ->merge(collect($line->serial_number_ids ?? [])->map(fn ($id) => (int) $id))
                ->push((int) ($line->returned_serial_id ?? 0))
                ->push((int) ($line->replacement_serial_id ?? 0));
        }

        $serialIds = $serialIds
            ->merge($saleReturnDetails->flatMap(fn ($detail) => collect($detail->serial_number_ids ?? [])->map(fn ($id) => (int) $id)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! empty($serialIds)) {
            \Modules\Product\Entities\ProductSerialNumber::query()
                ->whereIn('id', $serialIds)
                ->lockForUpdate()
                ->get();
        }
    }

    protected function applyApprovalAuditToPosReturn(
        \Modules\Pos\Entities\PosReturn $posReturn,
        ?int $actorId,
        \Illuminate\Support\Carbon $approvedAt,
        ?string $returnOption = null
    ): void {
        $attributes = [
            'status' => \Modules\Pos\Entities\PosReturn::STATUS_APPROVED,
            'approval_status' => \Modules\Pos\Entities\PosReturn::APPROVAL_STATUS_APPROVED,
            'approved_by' => $actorId,
            'approved_at' => $approvedAt,
        ];

        if ($returnOption !== null) {
            $attributes['return_option'] = $returnOption;
        }

        $posReturn->update($attributes);
    }

    protected function executeResolvedLineEffects(
        \Modules\Pos\Entities\PosReturn $posReturn,
        ?int $actorId,
        \Illuminate\Support\Carbon $executedAt
    ): void {
        $this->applyEffectiveCostReversals($posReturn, $executedAt);

        foreach ($posReturn->saleReturns as $saleReturn) {
            $details = $saleReturn->saleReturnDetails;

            $cashReturnDetails = $details
                ->filter(fn ($detail) => $this->resolveDetailResolution($detail) === \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN)
                ->values();

            $commercialCashReturnDetails = $cashReturnDetails
                ->reject(fn ($detail) => $this->shouldSkipCommercialCashCorrection($detail))
                ->values();

            if ($commercialCashReturnDetails->isNotEmpty()) {
                $this->applyCashReturnSaleDetailCorrections($commercialCashReturnDetails);
                $this->recalculateCashCorrectedSourceSale($saleReturn);
            }

            $cashSettlementAmount = $commercialCashReturnDetails
                ->sum(fn ($detail) => $this->resolveCashSettlementAmount($detail));

            if ($cashSettlementAmount > 0) {
                $this->applyCashSettlementForSaleReturn($saleReturn, $cashSettlementAmount, $executedAt, $actorId);
            }

            $replacementDetails = $details
                ->filter(fn ($detail) => $this->resolveDetailResolution($detail) === \Modules\Pos\Entities\PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT)
                ->reject(fn ($detail) => $this->isReplacementInformationalBundleComponentDetail($detail))
                ->values();

            $sameOwnerReplacementDetails = $replacementDetails
                ->reject(fn ($detail) => $this->isCrossOwnerReplacementDetail($detail))
                ->values();

            $crossOwnerReplacementDetails = $replacementDetails
                ->filter(fn ($detail) => $this->isCrossOwnerReplacementDetail($detail))
                ->values();

            if ($sameOwnerReplacementDetails->isNotEmpty()) {
                $this->dispatchReplacementForSaleReturnDetails($saleReturn, $sameOwnerReplacementDetails, $actorId, $executedAt);
            }

            if ($crossOwnerReplacementDetails->isNotEmpty()) {
                $this->applyCrossOwnerReplacementCashCorrections($crossOwnerReplacementDetails);
                $this->recalculateCashCorrectedSourceSale($saleReturn);

                foreach ($crossOwnerReplacementDetails as $detail) {
                    $this->executeCrossOwnerReplacementForDetail($posReturn, $saleReturn, $detail, $actorId, $executedAt);
                }
            }
        }
    }

    /**
     * Persist immutable HPP reversal effects for every physically effective return
     * detail in this POS return, exactly once. Draft/rejected/cancelled/rolled-back
     * work never reaches this method (executeResolvedLineEffects only runs from
     * within the guarded final-approval transaction), and each detail is skipped
     * once cost_effective_at is already set, so retries cannot double-apply.
     *
     * The reversal copies the ORIGINAL persisted unit cost snapshot (never a
     * recomputed current average) multiplied by the received quantity, per
     * openspec/changes/harden-product-bundle-hpp design.md decision #6.
     */
    protected function applyEffectiveCostReversals(
        \Modules\Pos\Entities\PosReturn $posReturn,
        \Illuminate\Support\Carbon $executedAt
    ): void {
        foreach ($posReturn->saleReturns as $saleReturn) {
            foreach ($saleReturn->saleReturnDetails as $detail) {
                if ($detail->cost_effective_at !== null) {
                    continue;
                }

                $resolution = $this->resolveDetailResolution($detail);
                $isEffectiveResolution = $resolution === \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN
                    || $resolution === \Modules\Pos\Entities\PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT;

                if (! $isEffectiveResolution) {
                    continue;
                }

                if ($resolution === \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN
                    && $this->shouldSkipCommercialCashCorrection($detail)) {
                    continue;
                }

                if ($resolution === \Modules\Pos\Entities\PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT
                    && $this->isReplacementInformationalBundleComponentDetail($detail)) {
                    continue;
                }

                // Cash returns and cross-owner replacements both route through
                // applyCashReturnSaleDetailCorrections(), which proportionally
                // reduces the underlying SaleDetails/SaleBundleItem quantity and
                // sub_total by the returned amount. Same-owner replacements never
                // do this. Recording it here lets report aggregation avoid
                // double-subtracting: once implicitly (via the already-reduced
                // live quantity) and once explicitly (via this reversal row).
                $commercialQuantityAlsoReduced = $resolution === \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN
                    || ($resolution === \Modules\Pos\Entities\PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT
                        && $this->isCrossOwnerReplacementDetail($detail));

                $this->persistEffectiveCostReversal($detail, $executedAt, $commercialQuantityAlsoReduced);
            }
        }
    }

    protected function persistEffectiveCostReversal(
        \Modules\SalesReturn\Entities\SaleReturnDetail $detail,
        \Illuminate\Support\Carbon $executedAt,
        bool $commercialQuantityAlsoReduced
    ): void {
        $returnedQuantity = (float) ($detail->quantity ?? 0);
        if ($returnedQuantity <= 0) {
            return;
        }

        $isComponentOrigin = $detail->component_sale_bundle_item_id !== null
            && $detail->cost_origin === \Modules\SalesReturn\Entities\SaleReturnDetail::COST_ORIGIN_BUNDLE_ITEM;

        if ($isComponentOrigin) {
            $origin = $detail->componentSaleBundleItem()->lockForUpdate()->first();
        } else {
            $origin = $detail->saleDetail()->lockForUpdate()->first();
        }

        if (! $origin) {
            // No original snapshot to copy: nothing to reverse, nothing to warn about
            // (mirrors the non-blocking-warning rule — this is not a missing-cost case).
            return;
        }

        $unitCost = (float) ($origin->cost_unit_snapshot ?? 0);

        $detail->forceFill([
            'cost_origin' => $isComponentOrigin
                ? \Modules\SalesReturn\Entities\SaleReturnDetail::COST_ORIGIN_BUNDLE_ITEM
                : \Modules\SalesReturn\Entities\SaleReturnDetail::COST_ORIGIN_SALE_DETAIL,
            'cost_unit_snapshot' => $unitCost,
            'cost_quantity' => $returnedQuantity,
            'cost_total_snapshot' => round($unitCost * $returnedQuantity, 2),
            'cost_snapshot_source' => $origin->cost_snapshot_source,
            'cost_snapshot_setting_id' => $isComponentOrigin ? $origin->cost_snapshot_setting_id : null,
            'cost_snapshot_setting_is_pkp' => $isComponentOrigin ? $origin->cost_snapshot_setting_is_pkp : null,
            'cost_snapshot_at' => $origin->cost_snapshot_at,
            'cost_effective_at' => $executedAt,
            'commercial_quantity_also_reduced' => $commercialQuantityAlsoReduced,
        ])->save();
    }

    protected function assertBundleExecutionGuards(\Modules\Pos\Entities\PosReturn $posReturn): void
    {
        foreach ($posReturn->lines as $line) {
            if (! $this->isBundleComponentLine($line)) {
                continue;
            }

            $parentSaleDetailId = (int) ($line->bundle_parent_sale_detail_id ?? 0);
            $hasParentLine = $posReturn->lines->contains(function (\Modules\Pos\Entities\PosReturnLine $candidate) use ($line, $parentSaleDetailId) {
                if ((int) $candidate->id === (int) $line->id) {
                    return false;
                }

                if ((int) ($candidate->sale_detail_id ?? 0) !== $parentSaleDetailId) {
                    return false;
                }

                if ((int) ($candidate->bundle_parent_sale_detail_id ?? 0) !== $parentSaleDetailId) {
                    return false;
                }

                if ($this->isBundleComponentLine($candidate)) {
                    return false;
                }

                $lineGroupKey = (string) ($line->bundle_group_key ?? '');
                $candidateGroupKey = (string) ($candidate->bundle_group_key ?? '');

                return $lineGroupKey === '' || $candidateGroupKey === '' || $lineGroupKey === $candidateGroupKey;
            });

            if (! $hasParentLine) {
                throw new \RuntimeException('Bundle component lines require their parent bundle return line before final approval can execute.');
            }
        }
    }

    protected function applyCashSettlementForSaleReturn(
        \Modules\SalesReturn\Entities\SaleReturn $saleReturn,
        float $settlementAmount,
        \Illuminate\Support\Carbon $settledAt,
        ?int $actorId
    ): void {
        if ($settlementAmount <= 0) {
            return;
        }

        \Modules\SalesReturn\Entities\SaleReturnPayment::create([
            'sale_return_id' => $saleReturn->id,
            'amount' => $settlementAmount,
            'date' => $settledAt->toDateString(),
            'reference' => 'SRPAY/' . $saleReturn->reference,
            'payment_method' => 'CASH',
            'note' => 'Penyelesaian final approval retur POS',
        ]);

        $newPaidAmount = (float) $saleReturn->paid_amount + $settlementAmount;
        $newDueAmount = max(0, (float) $saleReturn->total_amount - $newPaidAmount);

        $saleReturn->update([
            'paid_amount' => $newPaidAmount,
            'due_amount' => $newDueAmount,
            'payment_status' => $newDueAmount <= 0 ? 'Paid' : 'Partial',
            'settled_at' => $settledAt,
            'settled_by' => $actorId,
        ]);
    }

    protected function applyCashReturnSaleDetailCorrections(\Illuminate\Support\Collection $cashReturnDetails): void
    {
        $cashReturnDetails
            ->reject(fn ($detail) => $this->detailUsesBundleItemCommercialSource($detail))
            ->groupBy(fn ($detail) => (int) ($detail->sale_detail_id ?? 0))
            ->each(function (\Illuminate\Support\Collection $detailGroup, int $saleDetailId): void {
                if ($saleDetailId <= 0) {
                    throw new \RuntimeException('Cash return detail is missing a source sale detail reference.');
                }

                $saleDetail = \Modules\Sale\Entities\SaleDetails::query()
                    ->whereKey($saleDetailId)
                    ->lockForUpdate()
                    ->first();

                if (! $saleDetail) {
                    throw new \RuntimeException('Cash return source sale detail could not be found.');
                }

                $returnedQuantity = (float) $detailGroup->sum(fn ($detail) => (float) ($detail->quantity ?? 0));
                if ($returnedQuantity <= 0) {
                    return;
                }

                $currentQuantity = (float) ($saleDetail->quantity ?? 0);
                if ($currentQuantity <= 0) {
                    throw new \RuntimeException('Cash return source sale detail has no remaining quantity to reduce.');
                }

                if ($returnedQuantity > $currentQuantity) {
                    throw new \RuntimeException('Cash return quantity exceeds the remaining source sale detail quantity.');
                }

                $reductionRatio = min(1, $returnedQuantity / $currentQuantity);
                $quantityReduction = (int) round($returnedQuantity);
                $subtotalReduction = round((float) $detailGroup->sum(
                    fn ($detail) => $this->resolveCashSettlementAmount($detail)
                ), 2);

                if ($subtotalReduction <= 0) {
                    $subtotalReduction = round((float) ($saleDetail->sub_total ?? 0) * $reductionRatio, 2);
                }

                $saleDetail->forceFill([
                    'quantity' => max(0, (int) $saleDetail->quantity - $quantityReduction),
                    'sub_total' => round(max(0, (float) ($saleDetail->sub_total ?? 0) - $subtotalReduction), 2),
                    'product_discount_amount' => $this->reduceProratedMoney((float) ($saleDetail->product_discount_amount ?? 0), $reductionRatio),
                    'product_tax_amount' => $this->reduceProratedMoney((float) ($saleDetail->product_tax_amount ?? 0), $reductionRatio),
                ])->save();
            });

        $cashReturnDetails
            ->filter(fn ($detail) => $this->detailUsesBundleItemCommercialSource($detail))
            ->groupBy(fn ($detail) => (int) data_get($detail->execution_context, 'component_sale_bundle_item_id', 0))
            ->each(function (\Illuminate\Support\Collection $detailGroup, int $bundleItemId): void {
                if ($bundleItemId <= 0) {
                    throw new \RuntimeException('Cash return component detail is missing a source sale bundle item reference.');
                }

                $bundleItem = \Modules\Sale\Entities\SaleBundleItem::query()
                    ->whereKey($bundleItemId)
                    ->lockForUpdate()
                    ->first();

                if (! $bundleItem) {
                    throw new \RuntimeException('Cash return source sale bundle item could not be found.');
                }

                $returnedQuantity = (float) $detailGroup->sum(fn ($detail) => (float) ($detail->quantity ?? 0));
                if ($returnedQuantity <= 0) {
                    return;
                }

                $currentQuantity = (float) ($bundleItem->quantity ?? 0);
                if ($currentQuantity <= 0) {
                    throw new \RuntimeException('Cash return source sale bundle item has no remaining quantity to reduce.');
                }

                if ($returnedQuantity > $currentQuantity) {
                    throw new \RuntimeException('Cash return quantity exceeds the remaining source sale bundle item quantity.');
                }

                $reductionRatio = min(1, $returnedQuantity / $currentQuantity);
                $quantityReduction = (int) round($returnedQuantity);
                $subtotalReduction = round((float) $detailGroup->sum(
                    fn ($detail) => $this->resolveCashSettlementAmount($detail)
                ), 2);

                if ($subtotalReduction <= 0) {
                    $subtotalReduction = round((float) ($bundleItem->sub_total ?? 0) * $reductionRatio, 2);
                }

                $remainingQuantity = max(0, (int) $bundleItem->quantity - $quantityReduction);
                $remainingSubTotal = round(max(0, (float) ($bundleItem->sub_total ?? 0) - $subtotalReduction), 2);

                $bundleItem->forceFill([
                    'quantity' => $remainingQuantity,
                    'sub_total' => $remainingSubTotal,
                    'tax_amount' => $this->reduceProratedMoney((float) ($bundleItem->tax_amount ?? 0), $reductionRatio),
                    'price' => $remainingQuantity > 0
                        ? round($remainingSubTotal / $remainingQuantity, 2)
                        : 0,
                ])->save();
            });
    }

    protected function reduceProratedMoney(float $currentAmount, float $reductionRatio): float
    {
        if ($currentAmount <= 0 || $reductionRatio <= 0) {
            return round(max(0, $currentAmount), 2);
        }

        $reductionAmount = round($currentAmount * $reductionRatio, 2);

        return round(max(0, $currentAmount - $reductionAmount), 2);
    }

    protected function recalculateCashCorrectedSourceSale(\Modules\SalesReturn\Entities\SaleReturn $saleReturn): void
    {
        $saleId = (int) ($saleReturn->sale_id ?? 0);
        if ($saleId <= 0) {
            return;
        }

        $sale = \Modules\Sale\Entities\Sale::query()
            ->with(['saleDetails', 'salePayments', 'bundleItems.saleDetail'])
            ->whereKey($saleId)
            ->lockForUpdate()
            ->first();

        if (! $sale) {
            throw new \RuntimeException('Cash return source sale could not be found.');
        }

        $isPkp = (bool) (\Modules\Setting\Entities\Setting::query()
            ->whereKey((int) $sale->setting_id)
            ->value('is_pkp') ?? false);

        $normalizedSale = app(\Modules\Sale\Services\SaleNormalizer::class)->normalize([
            'tax_id' => $sale->tax_id,
            'tax_percentage' => $sale->tax_percentage,
            'discount_percentage' => $sale->discount_percentage,
            'discount_amount' => $sale->discount_amount,
            'shipping_amount' => $sale->shipping_amount,
            'paid_amount' => 0,
        ], $this->buildCashCorrectionNormalizerInputs($sale), $isPkp);

        $header = $normalizedSale['header'];
        $activePayments = $sale->salePayments
            ->filter(fn (SalePayment $payment) => $payment->isActive());

        $activePaidAmount = round((float) $activePayments
            ->sum(fn (SalePayment $payment) => (float) $payment->amount), 2);

        if ($activePaidAmount <= 0 && $activePayments->isEmpty()) {
            $activePaidAmount = round((float) ($sale->paid_amount ?? 0), 2);
        }

        $correctedPaidAmount = round(min($activePaidAmount, (float) $header['total_amount']), 2);

        if ($activePayments->isNotEmpty()) {
            $correctedPaidAmount = SalePayment::reconcileActivePaymentsForSale(
                (int) $sale->id,
                $correctedPaidAmount,
                self::SALE_PAYMENT_INVALIDATION_SOURCE,
                (int) $saleReturn->id,
            );
        }

        $correctedDueAmount = round(max((float) $header['total_amount'] - $correctedPaidAmount, 0), 2);

        $sale->update([
            'tax_id' => $header['tax_id'],
            'tax_percentage' => $header['tax_percentage'],
            'tax_amount' => $header['tax_amount'],
            'discount_percentage' => $header['discount_percentage'],
            'discount_amount' => $header['discount_amount'],
            'shipping_amount' => $header['shipping_amount'],
            'total_amount' => $header['total_amount'],
            'paid_amount' => $correctedPaidAmount,
            'due_amount' => $correctedDueAmount,
            'payment_status' => $this->resolveSalePaymentStatus(
                $header['total_amount'],
                $correctedDueAmount,
                (string) ($sale->payment_status ?? '')
            ),
        ]);
    }

    protected function resolveSalePaymentStatus(float $totalAmount, float $dueAmount, string $existingStatus = ''): string
    {
        $baseStatus = 'Paid';

        if (round($totalAmount, 2) <= 0 && round($dueAmount, 2) <= 0) {
            $baseStatus = 'Paid';
        } elseif (round($dueAmount, 2) >= round($totalAmount, 2)) {
            $baseStatus = 'Unpaid';
        } elseif ($dueAmount > 0) {
            $baseStatus = 'Partial';
        }

        if ($existingStatus !== '' && strtoupper($existingStatus) === $existingStatus) {
            return strtoupper($baseStatus);
        }

        if ($existingStatus !== '' && strtolower($existingStatus) === $existingStatus) {
            return strtolower($baseStatus);
        }

        return $baseStatus;
    }

    protected function buildCashCorrectionNormalizerInputs(\Modules\Sale\Entities\Sale $sale): \Illuminate\Support\Collection
    {
        $supplementalBundleItems = $sale->bundleItems
            ->filter(function ($bundleItem) {
                $saleDetail = $bundleItem->saleDetail;

                return ! $saleDetail || (float) ($saleDetail->quantity ?? 0) <= 0;
            })
            ->map(function ($bundleItem) {
                $quantity = (float) ($bundleItem->quantity ?? 0);
                $subTotal = (float) ($bundleItem->sub_total ?? 0);
                $unitPrice = $quantity > 0 ? round($subTotal / $quantity, 2) : (float) ($bundleItem->price ?? 0);

                return [
                    'product_id' => (int) ($bundleItem->product_id ?? 0),
                    'product_name' => (string) ($bundleItem->name ?? ''),
                    'product_code' => '',
                    'quantity' => $quantity,
                    'price' => $unitPrice,
                    'unit_price' => $unitPrice,
                    'sub_total' => $subTotal,
                    'product_discount_amount' => 0,
                    'product_discount_type' => 'fixed',
                    'product_tax_amount' => (float) ($bundleItem->tax_amount ?? 0),
                    'tax_id' => $bundleItem->inherited_tax_id ?? $bundleItem->tax_id,
                ];
            })
            ->filter(fn (array $detail) => (float) $detail['quantity'] > 0 || (float) $detail['sub_total'] > 0 || (float) $detail['product_tax_amount'] > 0)
            ->values();

        return collect($sale->saleDetails)->concat($supplementalBundleItems)->values();
    }

    protected function dispatchReplacementForSaleReturnDetails(
        \Modules\SalesReturn\Entities\SaleReturn $saleReturn,
        \Illuminate\Support\Collection $details,
        ?int $actorId,
        \Illuminate\Support\Carbon $settledAt
    ): void {
        $details->loadMissing(['saleReturn', 'dispatchDetail', 'posReturnLine.replacementSerial']);

        $dispatch = \Modules\Sale\Entities\Dispatch::create([
            'sale_id' => $saleReturn->sale_id,
            'dispatch_date' => $settledAt,
            'status' => \Modules\Sale\Entities\Dispatch::STATUS_APPROVED,
            'approved_by' => $actorId,
            'approved_at' => $settledAt,
        ]);

        foreach ($details as $detail) {
            $this->assertReplacementDispatchEligibility($detail);

            $dispatchDetail = \Modules\Sale\Entities\DispatchDetail::create([
                'dispatch_id' => $dispatch->id,
                'sale_id' => $saleReturn->sale_id,
                'product_id' => $detail->product_id,
                'dispatched_quantity' => (int) $detail->quantity,
                'location_id' => $detail->location_id,
                'serial_numbers' => $this->encodeReplacementDispatchSerialNumbers($detail),
                'tax_id' => $detail->tax_id,
                'bundle_id' => $this->resolveReplacementDispatchBundleId($detail),
                'pos_return_line_id' => $detail->pos_return_line_id,
                'replacement_of_dispatch_detail_id' => $detail->dispatch_detail_id,
                'replacement_returned_serial_id' => $detail->posReturnLine?->returned_serial_id,
            ]);

            $this->applyReplacementSerialDispatch($saleReturn, $detail, $dispatchDetail, $dispatch);

            if ($this->shouldDispatchReplacementAffectStock($detail)) {
                $this->adjustStockForReplacement($detail, $actorId ?? 0);
            }

            $this->snapshotReplacementDispatchCost($dispatchDetail, (int) $saleReturn->setting_id, $settledAt);
        }
    }

    /**
     * Outgoing replacement HPP is snapshotted independently from the replacement
     * owner/current average resolver — never inherited from the reversed return.
     */
    protected function snapshotReplacementDispatchCost(
        \Modules\Sale\Entities\DispatchDetail $dispatchDetail,
        int $replacementOwnerSettingId,
        \Illuminate\Support\Carbon $snapshotAt
    ): void {
        $product = \Modules\Product\Entities\Product::query()->find((int) $dispatchDetail->product_id);

        if (! $product || ! $product->stock_managed) {
            $dispatchDetail->forceFill([
                'replacement_cost_unit_snapshot' => 0,
                'replacement_cost_total_snapshot' => 0,
                'replacement_cost_snapshot_source' => \Modules\Sale\Services\SalesCostSnapshotService::SOURCE_NON_STOCK_MANAGED,
                'replacement_cost_snapshot_setting_id' => null,
                'replacement_cost_snapshot_at' => $snapshotAt,
            ])->save();

            return;
        }

        $result = app(\Modules\Sale\Services\AverageCostResolver::class)->resolve($product, $replacementOwnerSettingId);
        $qty = (float) ($dispatchDetail->dispatched_quantity ?? 0);

        $dispatchDetail->forceFill([
            'replacement_cost_unit_snapshot' => round($result['unit_cost'], 6),
            'replacement_cost_total_snapshot' => round($result['unit_cost'] * $qty, 2),
            'replacement_cost_snapshot_source' => $result['is_missing']
                ? \Modules\Sale\Services\SalesCostSnapshotService::SOURCE_MISSING_AVERAGE_PRICE
                : \Modules\Sale\Services\SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE,
            'replacement_cost_snapshot_setting_id' => $result['setting_id'],
            'replacement_cost_snapshot_at' => $snapshotAt,
        ])->save();
    }

    protected function encodeReplacementDispatchSerialNumbers(
        \Modules\SalesReturn\Entities\SaleReturnDetail $detail
    ): ?string {
        $replacementSerial = $detail->posReturnLine?->replacementSerial;

        if (! $replacementSerial) {
            return null;
        }

        return json_encode([$replacementSerial->serial_number]);
    }

    protected function resolveReplacementDispatchBundleId(
        \Modules\SalesReturn\Entities\SaleReturnDetail $detail
    ): ?int {
        $bundleId = $detail->dispatchDetail?->bundle_id
            ?? $detail->posReturnLine?->line_meta['bundle_id']
            ?? null;

        return $bundleId !== null ? (int) $bundleId : null;
    }

    protected function shouldDispatchReplacementAffectStock(
        \Modules\SalesReturn\Entities\SaleReturnDetail $detail
    ): bool {
        return (string) ($detail->stock_behavior ?? '') !== \Modules\Pos\Entities\PosReturnLine::STOCK_BEHAVIOR_STOCKLESS;
    }

    protected function applyReplacementSerialDispatch(
        \Modules\SalesReturn\Entities\SaleReturn $saleReturn,
        \Modules\SalesReturn\Entities\SaleReturnDetail $detail,
        \Modules\Sale\Entities\DispatchDetail $dispatchDetail,
        \Modules\Sale\Entities\Dispatch $dispatch
    ): void {
        $line = $detail->posReturnLine;
        $replacementSerialId = (int) ($line->replacement_serial_id ?? 0);

        if ($replacementSerialId <= 0) {
            return;
        }

        $replacementSerial = $line->replacementSerial
            ?? \Modules\Product\Entities\ProductSerialNumber::query()->find($replacementSerialId);

        if (! $replacementSerial) {
            throw new \RuntimeException('Serial pengganti tidak ditemukan saat eksekusi pengiriman pengganti.');
        }

        if ((int) $replacementSerial->product_id !== (int) $detail->product_id) {
            throw new \RuntimeException('Serial pengganti harus memiliki SKU yang sama dengan barang retur.');
        }

        $replacementSerial->update([
            'dispatch_detail_id' => $dispatchDetail->id,
            'location_id' => $dispatchDetail->location_id,
            'status' => \Modules\Product\Entities\ProductSerialNumber::STATUS_SOLD,
        ]);

        \App\Services\SerialNumberHistoryService::record(
            $replacementSerial->id,
            \Modules\Product\Entities\SerialNumberHistory::EVENT_SOLD,
            (int) $dispatchDetail->location_id,
            $dispatchDetail
        );

        \Modules\Sale\Entities\SalesOrderSerialTracking::query()->upsert([
            [
                'sale_id' => (int) $saleReturn->sale_id,
                'product_serial_number_id' => $replacementSerial->id,
                'quantity_allocated' => 1,
                'dispatch_date' => $dispatch->dispatch_date ?? now(),
                'return_date' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], [
            'sale_id',
            'product_serial_number_id',
        ], [
            'quantity_allocated',
            'dispatch_date',
            'return_date',
            'updated_at',
        ]);
    }

    protected function isCrossOwnerReplacementDetail(\Modules\SalesReturn\Entities\SaleReturnDetail $detail): bool
    {
        return (string) data_get($detail->execution_context, 'execution_mode', '') === 'cross_owner_replacement';
    }

    protected function applyCrossOwnerReplacementCashCorrections(\Illuminate\Support\Collection $crossOwnerDetails): void
    {
        $this->applyCashReturnSaleDetailCorrections($crossOwnerDetails);
    }

    protected function executeCrossOwnerReplacementForDetail(
        \Modules\Pos\Entities\PosReturn $posReturn,
        \Modules\SalesReturn\Entities\SaleReturn $saleReturn,
        \Modules\SalesReturn\Entities\SaleReturnDetail $detail,
        ?int $actorId,
        \Illuminate\Support\Carbon $executedAt
    ): void {
        $executionContext = $this->detailExecutionContext($detail);
        $replacementSettingId = (int) ($executionContext['replacement_serial_owner_setting_id'] ?? 0);
        $replacementLocationId = (int) ($executionContext['replacement_serial_location_id'] ?? 0);

        if ($replacementSettingId <= 0 || $replacementLocationId <= 0) {
            throw new \RuntimeException('Konteks eksekusi cross-owner replacement tidak memiliki replacement_serial_owner_setting_id atau replacement_serial_location_id yang valid.');
        }

        $generatedEffects = $executionContext['generated_replacement_sale_effects'] ?? null;
        if (! is_array($generatedEffects)) {
            throw new \RuntimeException('Konteks eksekusi cross-owner replacement tidak memiliki generated_replacement_sale_effects yang valid.');
        }

        $customerId = (int) ($generatedEffects['customer_id'] ?? 0);
        if ($customerId <= 0) {
            throw new \RuntimeException('Customer untuk replacement-owner Sale tidak dapat ditentukan dari execution context.');
        }

        $line = $detail->posReturnLine;
        $replacementSerialId = (int) ($line->replacement_serial_id ?? 0);

        $replacementSerial = $replacementSerialId > 0
            ? (ProductSerialNumber::query()->with('location')->find($replacementSerialId))
            : null;

        if ($replacementSerialId > 0 && ! $replacementSerial) {
            throw new \RuntimeException('Serial pengganti untuk cross-owner replacement tidak ditemukan.');
        }

        if ($replacementSerial && (int) $replacementSerial->product_id !== (int) $detail->product_id) {
            throw new \RuntimeException('Serial pengganti harus memiliki product_id yang sama dengan barang yang diretur (cross-owner).');
        }

        $paymentAmount = round((float) ($generatedEffects['payment_amount'] ?? 0), 2);
        $dispatchQuantity = (int) round((float) ($generatedEffects['dispatch_quantity'] ?? $detail->quantity ?? 1));

        $originalSale = \Modules\Sale\Entities\Sale::query()->find((int) $saleReturn->sale_id);

        $replacementOwnerSaleReference = $this->generateCrossOwnerReplacementSaleReference($replacementSettingId);

        $customerRecord = \Modules\People\Entities\Customer::query()->find($customerId);
        $customerName = $customerRecord?->customer_name ?? ($originalSale?->customer_name ?? '');

        $replacementSale = Sale::query()->create([
            'date' => $originalSale?->date ?? $executedAt->toDateString(),
            'due_date' => $originalSale?->due_date ?? $executedAt->toDateString(),
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'tax_id' => null,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => $paymentAmount,
            'paid_amount' => $paymentAmount,
            'due_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Paid',
            'payment_term_id' => PaymentTerm::defaultCodTermId(),
            'note' => 'Generated from POS Return ' . $posReturn->reference . ' cross-owner replacement. Original Sale: ' . ($originalSale?->reference ?? ''),
            'setting_id' => $replacementSettingId,
            'reference' => $replacementOwnerSaleReference,
            'is_tax_included' => false,
            'payment_method' => $originalSale?->payment_method ?? 'CASH',
        ]);

        $product = \Modules\Product\Entities\Product::query()->find((int) $detail->product_id);

        $replacementSaleDetail = SaleDetails::query()->create([
            'sale_id' => $replacementSale->id,
            'product_id' => $detail->product_id,
            'product_name' => $product?->product_name ?? $detail->product_name ?? '',
            'product_code' => $product?->product_code ?? $detail->product_code ?? '',
            'quantity' => $dispatchQuantity,
            'price' => $dispatchQuantity > 0 ? round($paymentAmount / $dispatchQuantity, 2) : 0,
            'unit_price' => $dispatchQuantity > 0 ? round($paymentAmount / $dispatchQuantity, 2) : 0,
            'sub_total' => $paymentAmount,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        // Outgoing replacement HPP is snapshotted independently from the replacement
        // owner/current average resolver, never inherited from the original return.
        app(\Modules\Sale\Services\SalesCostSnapshotService::class)->snapshotSaleDetailCost(
            $replacementSaleDetail,
            $executedAt,
            $replacementSettingId
        );
        $replacementSaleDetail->save();

        if ($paymentAmount > 0) {
            SalePayment::query()->create([
                'sale_id' => $replacementSale->id,
                'amount' => $paymentAmount,
                'date' => $originalSale?->date ?? $executedAt->toDateString(),
                'reference' => 'CROSS-OWNER-REPL/' . $posReturn->reference,
                'payment_method' => $originalSale?->payment_method ?? 'CASH',
                'note' => 'Generated cross-owner replacement payment from POS Return ' . $posReturn->reference,
            ]);
        }

        $replacementDispatch = Dispatch::query()->create([
            'sale_id' => $replacementSale->id,
            'dispatch_date' => $executedAt,
            'status' => Dispatch::STATUS_APPROVED,
            'approved_by' => $actorId,
            'approved_at' => $executedAt,
        ]);

        $serialNumbers = $replacementSerial ? json_encode([$replacementSerial->serial_number]) : null;

        $replacementDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $replacementDispatch->id,
            'sale_id' => $replacementSale->id,
            'product_id' => $detail->product_id,
            'dispatched_quantity' => $dispatchQuantity,
            'location_id' => $replacementLocationId,
            'serial_numbers' => $serialNumbers,
            'tax_id' => $detail->tax_id,
            'bundle_id' => null,
            'pos_return_line_id' => $detail->pos_return_line_id,
        ]);

        if ($this->shouldDispatchReplacementAffectStock($detail)) {
            $this->adjustCrossOwnerReplacementStock($detail, $replacementSettingId, $replacementLocationId, $dispatchQuantity, $actorId, $saleReturn->reference ?? '');
        }

        if ($replacementSerial) {
            $replacementSerial->update([
                'dispatch_detail_id' => $replacementDispatchDetail->id,
                'location_id' => $replacementLocationId,
                'status' => ProductSerialNumber::STATUS_SOLD,
            ]);

            \App\Services\SerialNumberHistoryService::record(
                $replacementSerial->id,
                SerialNumberHistory::EVENT_SOLD,
                $replacementLocationId,
                $replacementDispatchDetail
            );

            SalesOrderSerialTracking::query()->upsert([
                [
                    'sale_id' => (int) $replacementSale->id,
                    'product_serial_number_id' => $replacementSerial->id,
                    'quantity_allocated' => 1,
                    'dispatch_date' => $replacementDispatch->dispatch_date ?? now(),
                    'return_date' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ], [
                'sale_id',
                'product_serial_number_id',
            ], [
                'quantity_allocated',
                'dispatch_date',
                'return_date',
                'updated_at',
            ]);
        }
    }

    protected function adjustCrossOwnerReplacementStock(
        \Modules\SalesReturn\Entities\SaleReturnDetail $detail,
        int $settingId,
        int $locationId,
        int $quantity,
        ?int $actorId,
        string $saleReturnReference
    ): void {
        $product = \Modules\Product\Entities\Product::query()->findOrFail((int) $detail->product_id);

        $productStock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if (! $productStock) {
            throw new \RuntimeException("Stok tidak ditemukan untuk produk {$product->product_name} di lokasi replacement owner.");
        }

        if ((int) $productStock->quantity < $quantity) {
            throw new \RuntimeException('Stok produk pengganti tidak mencukupi di lokasi replacement owner.');
        }

        $previousQuantity = (int) $product->product_quantity;
        $previousQuantityAtLocation = (int) $productStock->quantity;

        $productStock->decrement('quantity', $quantity);
        $product->decrement('product_quantity', $quantity);

        $taxId = $productStock->tax_id ?? null;

        \Modules\Product\Entities\Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $settingId,
            'quantity' => -$quantity,
            'current_quantity' => (int) $product->product_quantity,
            'broken_quantity' => (int) ($productStock->broken_quantity ?? 0),
            'location_id' => $locationId,
            'user_id' => $actorId,
            'reason' => 'Cross-owner replacement dispatch for POS Return #' . $saleReturnReference,
            'type' => 'DISPATCH_RETURN',
            'previous_quantity' => $previousQuantity,
            'after_quantity' => (int) $product->product_quantity,
            'previous_quantity_at_location' => $previousQuantityAtLocation,
            'after_quantity_at_location' => (int) ($productStock->quantity ?? 0),
            'quantity_non_tax' => $taxId ? 0 : $quantity,
            'quantity_tax' => $taxId ? $quantity : 0,
            'broken_quantity_non_tax' => (int) ($productStock->broken_quantity_non_tax ?? 0),
            'broken_quantity_tax' => (int) ($productStock->broken_quantity_tax ?? 0),
        ]);
    }

    protected function generateCrossOwnerReplacementSaleReference(int $settingId): string
    {
        $prefix = 'POSREPL';
        $date = now()->format('Ymd');

        $lastRef = \Modules\Sale\Entities\Sale::query()
            ->where('setting_id', $settingId)
            ->where('reference', 'like', "{$prefix}/{$date}/%")
            ->orderByDesc('reference')
            ->value('reference');

        $sequence = 1;
        if ($lastRef) {
            $parts = explode('/', $lastRef);
            $sequence = (int) ($parts[2] ?? 0) + 1;
        }

        return sprintf('%s/%s/%04d', $prefix, $date, $sequence);
    }

    protected function finalizeApprovalExecution(
        \Modules\Pos\Entities\PosReturn $posReturn,
        ?int $actorId,
        \Illuminate\Support\Carbon $completedAt
    ): void {
        foreach ($posReturn->saleReturns as $saleReturn) {
            $saleReturn->update([
                'status' => 'COMPLETED',
                'approval_status' => 'APPROVED',
                'approved_by' => $actorId,
                'approved_at' => $completedAt,
                'settled_by' => $saleReturn->settled_by ?? $actorId,
                'settled_at' => $saleReturn->settled_at ?? $completedAt,
            ]);

            app(\App\Support\SalesReturn\SaleReturnLifecycleSyncService::class)
                ->archiveSourceSaleIfFullyReturnedAndCompleted($saleReturn->fresh(), (int) $actorId);
        }

        $posReturn->update([
            'status' => \Modules\Pos\Entities\PosReturn::STATUS_COMPLETED,
            'approval_status' => \Modules\Pos\Entities\PosReturn::APPROVAL_STATUS_APPROVED,
            'approved_by' => $actorId,
            'approved_at' => $completedAt,
            'received_by' => $posReturn->received_by ?? $actorId,
            'received_at' => $posReturn->received_at ?? $completedAt,
            'settled_by' => $actorId,
            'settled_at' => $completedAt,
        ]);
    }

    protected function resolveDetailResolution(\Modules\SalesReturn\Entities\SaleReturnDetail $detail): string
    {
        return (string) ($detail->posReturnLine->resolution ?? \Modules\Pos\Entities\PosReturnLine::RESOLUTION_NONE);
    }

    protected function resolveCashSettlementAmount(\Modules\SalesReturn\Entities\SaleReturnDetail $detail): float
    {
        $executionContext = $this->detailExecutionContext($detail);

        if ($this->isComponentSaleReturnDetail($detail)) {
            $plannedAmount = (float) ($executionContext['planned_amount'] ?? 0);

            if ($plannedAmount > 0) {
                return $plannedAmount;
            }
        }

        $line = $detail->posReturnLine;
        $expectedCashAmount = (float) ($line->expected_cash_amount ?? 0);

        if ($expectedCashAmount > 0 && ! $this->isComponentSaleReturnDetail($detail)) {
            return $expectedCashAmount;
        }

        if ($detail->sub_total !== null) {
            return (float) $detail->sub_total;
        }

        return (float) ($detail->quantity ?? 0) * (float) ($detail->unit_price ?? $detail->price ?? 0);
    }

    protected function shouldSkipCommercialCashCorrection(\Modules\SalesReturn\Entities\SaleReturnDetail $detail): bool
    {
        if ($this->isComponentSaleReturnDetail($detail)) {
            if ((string) ($detail->stock_behavior ?? '') === \Modules\Pos\Entities\PosReturnLine::STOCK_BEHAVIOR_STOCKLESS) {
                return true;
            }

            return ! $this->detailHasCommercialCorrectionTarget($detail);
        }

        return false;
    }

    protected function isReplacementInformationalBundleComponentDetail(\Modules\SalesReturn\Entities\SaleReturnDetail $detail): bool
    {
        $line = $detail->posReturnLine;

        if (! $line || (string) $line->resolution !== \Modules\Pos\Entities\PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT) {
            return false;
        }

        if ($this->isBundleComponentLine($line)) {
            return true;
        }

        $bundleTrace = collect(data_get($line->line_meta, 'bundle_trace', []));

        return $bundleTrace->isNotEmpty()
            && (int) ($detail->product_id ?? 0) !== (int) ($line->product_id ?? 0);
    }

    protected function detailExecutionContext(\Modules\SalesReturn\Entities\SaleReturnDetail $detail): array
    {
        return is_array($detail->execution_context) ? $detail->execution_context : [];
    }

    protected function isComponentSaleReturnDetail(\Modules\SalesReturn\Entities\SaleReturnDetail $detail): bool
    {
        if ((string) data_get($detail->execution_context, 'row_type', '') === 'component') {
            return true;
        }

        $line = $detail->posReturnLine;

        if (! $line) {
            return false;
        }

        if ($this->isBundleComponentLine($line)) {
            return true;
        }

        $bundleTrace = collect(data_get($line->line_meta, 'bundle_trace', []));

        return $bundleTrace->isNotEmpty()
            && (int) ($detail->product_id ?? 0) !== (int) ($line->product_id ?? 0);
    }

    protected function detailHasCommercialCorrectionTarget(\Modules\SalesReturn\Entities\SaleReturnDetail $detail): bool
    {
        if ($this->detailUsesBundleItemCommercialSource($detail)) {
            return (int) data_get($detail->execution_context, 'component_sale_bundle_item_id', 0) > 0;
        }

        return (int) ($detail->sale_detail_id ?? 0) > 0;
    }

    protected function detailUsesBundleItemCommercialSource(\Modules\SalesReturn\Entities\SaleReturnDetail $detail): bool
    {
        return (string) data_get($detail->execution_context, 'commercial_value_source', '') === 'sale_bundle_item';
    }

    protected function isBundleComponentLine(?\Modules\Pos\Entities\PosReturnLine $line): bool
    {
        if (! $line) {
            return false;
        }

        $parentSaleDetailId = (int) ($line->bundle_parent_sale_detail_id ?? 0);
        $saleDetailId = (int) ($line->sale_detail_id ?? 0);

        if ($parentSaleDetailId <= 0 || $saleDetailId <= 0 || $parentSaleDetailId === $saleDetailId) {
            return false;
        }

        return (float) ($line->component_quantity_per_bundle ?? 0) > 0 || (string) ($line->bundle_group_key ?? '') !== '';
    }

    /**
     * Receive a POS return.
     *
     * @param int $posReturnId
     * @return void
     */
    public function receive(int $posReturnId): void
    {
        $this->runLifecycleMutation($posReturnId, 'receive', function () use ($posReturnId) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($posReturnId) {
            $posReturn = \Modules\Pos\Entities\PosReturn::with(['saleReturns.saleReturnDetails.posReturnLine', 'lines'])->findOrFail($posReturnId);

            $this->assertManualCorrectionIsNotRequired($posReturn);

            if ($posReturn->status !== \Modules\Pos\Entities\PosReturn::STATUS_APPROVED) {
                throw new \Exception("Only approved returns can be received.");
            }

            $this->receiveLinkedSaleReturns($posReturn);
            $this->applyReceivedDispatchQuantityAdjustments($posReturn);

            $nextStatus = $posReturn->return_option === \Modules\Pos\Entities\PosReturn::OPTION_CASH_RETURN
                ? \Modules\Pos\Entities\PosReturn::STATUS_AWAITING_SETTLEMENT
                : \Modules\Pos\Entities\PosReturn::STATUS_AWAITING_DISPATCH;

            $posReturn->update([
                'status' => $nextStatus,
                'received_by' => \Illuminate\Support\Facades\Auth::id(),
                'received_at' => now(),
            ]);
            });
        });
    }

    protected function receiveLinkedSaleReturns(\Modules\Pos\Entities\PosReturn $posReturn): void
    {
        foreach ($posReturn->saleReturns as $saleReturn) {
            $this->processSaleReturnReceiving($saleReturn);
        }
    }

    protected function applyReceivedDispatchQuantityAdjustments(\Modules\Pos\Entities\PosReturn $posReturn): void
    {
        $dispatchAdjustments = $posReturn->saleReturns
            ->flatMap(fn ($saleReturn) => $saleReturn->saleReturnDetails)
            ->filter(fn ($detail) => $this->resolveDetailResolution($detail) === \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN)
            ->filter(fn ($detail) => (int) ($detail->dispatch_detail_id ?? 0) > 0)
            ->groupBy(fn ($detail) => (int) $detail->dispatch_detail_id);

        foreach ($dispatchAdjustments as $dispatchDetailId => $detailGroup) {
            $dispatchDetail = \Modules\Sale\Entities\DispatchDetail::query()
                ->whereKey((int) $dispatchDetailId)
                ->lockForUpdate()
                ->first();

            if (! $dispatchDetail) {
                continue;
            }

            $reductionQuantity = (int) round((float) $detailGroup->sum(fn ($detail) => (float) ($detail->quantity ?? 0)));
            if ($reductionQuantity <= 0) {
                continue;
            }

            $dispatchDetail->update([
                'dispatched_quantity' => max(0, (int) ($dispatchDetail->dispatched_quantity ?? 0) - $reductionQuantity),
            ]);
        }
    }

    /**
     * Process receiving for a single SaleReturn.
     *
     * @param SaleReturn $saleReturn
     * @return void
     */
    protected function processSaleReturnReceiving(\Modules\SalesReturn\Entities\SaleReturn $saleReturn): void
    {
        $receivedAt = now();
        $actorId = \Illuminate\Support\Facades\Auth::id();

        $details = \Modules\SalesReturn\Entities\SaleReturnDetail::with(['dispatchDetail', 'posReturnLine'])
            ->where('sale_return_id', $saleReturn->id)
            ->get();

        foreach ($details as $detail) {
            if ($this->isReplacementInformationalBundleComponentDetail($detail)) {
                continue;
            }

            $quantity = (int) ($detail->quantity ?? 0);
            if ($quantity <= 0) {
                continue;
            }


            // Skip inventory mutation if stockless
            if ($detail->stock_behavior === \Modules\Pos\Entities\PosReturnLine::STOCK_BEHAVIOR_STOCKLESS) {
                continue;
            }

            $dispatchDetail = $detail->dispatchDetail;
            if (!$dispatchDetail) {
                continue;
            }

            $locationId = $detail->location_id
                ?? $saleReturn->location_id
                ?? $dispatchDetail->location_id;

            if (!$locationId) {
                throw new \RuntimeException('Lokasi penerimaan retur tidak dapat ditentukan.');
            }

            $product = \Modules\Product\Entities\Product::findOrFail($detail->product_id);

            $productStock = \Modules\Product\Entities\ProductStock::where('product_id', $detail->product_id)
                ->where('location_id', $locationId)
                ->first();

            if (!$productStock) {
                $productStock = \Modules\Product\Entities\ProductStock::create([
                    'product_id' => $detail->product_id,
                    'location_id' => $locationId,
                    'quantity' => 0,
                    'quantity_tax' => 0,
                    'quantity_non_tax' => 0,
                    'broken_quantity_non_tax' => 0,
                    'broken_quantity_tax' => 0,
                    'broken_quantity' => 0,
                    'tax_id' => $dispatchDetail->tax_id,
                ]);
            }

            $previousProductQuantity = (int) ($product->product_quantity ?? 0);
            $previousQuantityAtLocation = (int) ($productStock->quantity ?? 0);
            $taxId = $dispatchDetail->tax_id;

            if ($taxId) {
                $productStock->quantity_tax = (int) ($productStock->quantity_tax ?? 0) + $quantity;
            } else {
                $productStock->quantity_non_tax = (int) ($productStock->quantity_non_tax ?? 0) + $quantity;
            }

            $productStock->quantity = (int) ($productStock->quantity_non_tax ?? 0)
                + (int) ($productStock->quantity_tax ?? 0)
                + (int) ($productStock->broken_quantity_non_tax ?? 0)
                + (int) ($productStock->broken_quantity_tax ?? 0);
            
            $productStock->save();

            $product->product_quantity = (int) $product->product_quantity + $quantity;
            $product->save();

            \Illuminate\Support\Facades\DB::table('transactions')->insert([
                'product_id' => $product->id,
                'setting_id' => (int) ($saleReturn->setting_id ?? \Illuminate\Support\Facades\Auth::user()->setting_id),
                'type' => $taxId ? 'SALE_RETURN_GOOD_TAX' : 'SALE_RETURN_GOOD_NON_TAX',
                'quantity' => $quantity,
                'current_quantity' => (int) $product->product_quantity,
                'location_id' => $locationId,
                'user_id' => $actorId,
                'reason' => 'PENERIMAAN RETUR PENJUALAN (POS): ' . $saleReturn->reference,
                'previous_quantity' => $previousProductQuantity,
                'after_quantity' => (int) $product->product_quantity,
                'previous_quantity_at_location' => $previousQuantityAtLocation,
                'after_quantity_at_location' => (int) ($productStock->quantity ?? 0),
                'quantity_tax' => $taxId ? $quantity : 0,
                'quantity_non_tax' => $taxId ? 0 : $quantity,
                'broken_quantity' => (int) ($productStock->broken_quantity ?? 0),
                'broken_quantity_tax' => (int) ($productStock->broken_quantity_tax ?? 0),
                'broken_quantity_non_tax' => (int) ($productStock->broken_quantity_non_tax ?? 0),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $serialIds = $detail->serial_number_ids ?? [];
            if (!empty($serialIds)) {
                \Modules\Product\Entities\ProductSerialNumber::whereIn('id', $serialIds)
                    ->update([
                        'dispatch_detail_id' => null,
                        'location_id' => $locationId,
                        'status' => \Modules\Product\Entities\ProductSerialNumber::STATUS_ACTIVE,
                    ]);

                \Modules\Sale\Entities\SalesOrderSerialTracking::where('sale_id', $saleReturn->sale_id)
                    ->whereIn('product_serial_number_id', $serialIds)
                    ->update([
                        'return_date' => $receivedAt,
                    ]);

                foreach ($serialIds as $serialId) {
                    \App\Services\SerialNumberHistoryService::record(
                        $serialId,
                        \Modules\Product\Entities\SerialNumberHistory::EVENT_SALE_RETURNED,
                        $locationId,
                        $detail
                    );
                }
            }
        }

        $saleReturn->update([
            'status' => 'Awaiting Settlement',
            'received_by' => $actorId,
            'received_at' => $receivedAt,
        ]);

        app(\App\Support\SalesReturn\SaleReturnLifecycleSyncService::class)
            ->syncSourceSaleReturnStatusFromReceivedReturns($saleReturn);
    }

    /**
     * Settle a POS return (Cash Return).
     *
     * @param int $posReturnId
     * @return void
     */
    public function settlePaymentReturn(int $posReturnId): void
    {
        $this->runLifecycleMutation($posReturnId, 'settle_payment_return', function () use ($posReturnId) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($posReturnId) {
            $posReturn = \Modules\Pos\Entities\PosReturn::query()
                ->with(['saleReturns.saleReturnPayments'])
                ->whereKey($posReturnId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertManualCorrectionIsNotRequired($posReturn);

            if ($posReturn->return_option !== \Modules\Pos\Entities\PosReturn::OPTION_CASH_RETURN) {
                throw new \Exception('Hanya retur dengan opsi kembali uang yang dapat diproses sebagai pengembalian tunai.');
            }

            if ($posReturn->status !== \Modules\Pos\Entities\PosReturn::STATUS_AWAITING_SETTLEMENT) {
                throw new \Exception("Hanya retur yang menunggu penyelesaian pembayaran yang dapat diselesaikan.");
            }

            $actorId = \Illuminate\Support\Facades\Auth::id();
            $settledAt = now();
            $remainingPosReturnAmount = (float) $posReturn->total_amount;
            $processedAmount = 0.0;

            foreach ($posReturn->saleReturns as $saleReturn) {
                $remainingRefundable = $this->remainingRefundableAmount($saleReturn);
                if ($remainingRefundable <= 0) {
                    continue;
                }

                $settlementAmount = min($remainingRefundable, $remainingPosReturnAmount);
                if ($settlementAmount <= 0) {
                    break;
                }

                $this->settleLinkedCashReturn($saleReturn, $settlementAmount, $settledAt, $actorId);

                $processedAmount += $settlementAmount;
                $remainingPosReturnAmount = max(0, $remainingPosReturnAmount - $settlementAmount);
            }

            if ($processedAmount <= 0) {
                throw new \RuntimeException('Tidak ada sisa nominal pengembalian tunai yang dapat diproses.');
            }

            $posReturn->update([
                'status' => \Modules\Pos\Entities\PosReturn::STATUS_COMPLETED,
                'settled_at' => $settledAt,
                'settled_by' => $actorId,
            ]);
            });
        });
    }

    protected function settleLinkedCashReturn(
        \Modules\SalesReturn\Entities\SaleReturn $saleReturn,
        float $settlementAmount,
        \Illuminate\Support\Carbon $settledAt,
        ?int $actorId
    ): void {
        \Modules\SalesReturn\Entities\SaleReturnPayment::create([
            'sale_return_id' => $saleReturn->id,
            'amount' => $settlementAmount,
            'date' => $settledAt->toDateString(),
            'reference' => 'SRPAY/' . $saleReturn->reference,
            'payment_method' => 'CASH',
            'note' => 'Penyelesaian Retur POS',
        ]);

        $newPaidAmount = (float) $saleReturn->paid_amount + $settlementAmount;
        $newDueAmount = max(0, (float) $saleReturn->due_amount - $settlementAmount);

        $saleReturn->update([
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'paid_amount' => $newPaidAmount,
            'due_amount' => $newDueAmount,
            'settled_at' => $settledAt,
            'settled_by' => $actorId,
        ]);

        app(\App\Support\SalesReturn\SaleReturnLifecycleSyncService::class)
            ->archiveSourceSaleIfFullyReturnedAndCompleted($saleReturn, $actorId);
    }

    /**
     * Settle a POS return (Product Replacement).
     *
     * @param int $posReturnId
     * @return void
     */
    public function dispatchReplacement(int $posReturnId): void
    {
        $this->runLifecycleMutation($posReturnId, 'dispatch_replacement', function () use ($posReturnId) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($posReturnId) {
            $posReturn = \Modules\Pos\Entities\PosReturn::query()
                ->with(['saleReturns.saleReturnDetails.posReturnLine'])
                ->whereKey($posReturnId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertManualCorrectionIsNotRequired($posReturn);

            if ($posReturn->return_option !== \Modules\Pos\Entities\PosReturn::OPTION_PRODUCT_REPLACEMENT) {
                throw new \Exception('Hanya retur dengan opsi ganti produk yang dapat diproses sebagai pengiriman pengganti.');
            }

            if ($posReturn->status !== \Modules\Pos\Entities\PosReturn::STATUS_AWAITING_DISPATCH) {
                throw new \Exception("Hanya retur yang menunggu pengiriman pengganti yang dapat diselesaikan.");
            }

            $actorId = \Illuminate\Support\Facades\Auth::id();
            $settledAt = now();

            foreach ($posReturn->saleReturns as $saleReturn) {
                $this->dispatchReplacementForSaleReturn($saleReturn, $actorId, $settledAt);
            }

            $posReturn->update([
                'status' => \Modules\Pos\Entities\PosReturn::STATUS_COMPLETED,
                'settled_at' => $settledAt,
                'settled_by' => $actorId,
            ]);
            });
        });
    }

    protected function dispatchReplacementForSaleReturn(
        \Modules\SalesReturn\Entities\SaleReturn $saleReturn,
        ?int $actorId,
        \Illuminate\Support\Carbon $settledAt
    ): void {
        $this->dispatchReplacementForSaleReturnDetails($saleReturn, $saleReturn->saleReturnDetails, $actorId, $settledAt);

        $saleReturn->update([
            'status' => 'COMPLETED',
            'settled_at' => $settledAt,
            'settled_by' => $actorId,
        ]);

        app(\App\Support\SalesReturn\SaleReturnLifecycleSyncService::class)
            ->archiveSourceSaleIfFullyReturnedAndCompleted($saleReturn, $actorId);
    }

    /**
     * Adjust stock for replacement items.
     */
    protected function adjustStockForReplacement(\Modules\SalesReturn\Entities\SaleReturnDetail $detail, int $actorId): void
    {
        $product = \Modules\Product\Entities\Product::findOrFail($detail->product_id);
        $qty = $detail->quantity;
        $locationId = $detail->location_id;

        $productStock = \Modules\Product\Entities\ProductStock::where('product_id', $product->id)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if (!$productStock) {
            throw new \Exception("Stok tidak ditemukan untuk produk {$product->product_name} di lokasi selected.");
        }

        if ((int) $productStock->quantity < (int) $qty) {
            throw new \RuntimeException('Stok produk pengganti tidak mencukupi di lokasi sumber asli retur.');
        }

        $previousQuantity = (int) $product->product_quantity;
        $previousQuantityAtLocation = (int) $productStock->quantity;

        // Decrement stock
        $productStock->decrement('quantity', $qty);
        $product->decrement('product_quantity', $qty);

        $taxId = $productStock->tax_id ?? null;

        \Modules\Product\Entities\Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $product->setting_id,
            'quantity' => -$qty,
            'current_quantity' => (int) $product->product_quantity,
            'broken_quantity' => (int) ($productStock->broken_quantity ?? 0),
            'location_id' => $locationId,
            'user_id' => $actorId,
            'reason' => 'Dispatch replacement for Sale Return #' . $detail->sale_return_id,
            'type' => 'DISPATCH_RETURN',
            'previous_quantity' => $previousQuantity,
            'after_quantity' => (int) $product->product_quantity,
            'previous_quantity_at_location' => $previousQuantityAtLocation,
            'after_quantity_at_location' => (int) ($productStock->quantity ?? 0),
            'quantity_non_tax' => $taxId ? 0 : $qty,
            'quantity_tax' => $taxId ? $qty : 0,
            'broken_quantity_non_tax' => (int) ($productStock->broken_quantity_non_tax ?? 0),
            'broken_quantity_tax' => (int) ($productStock->broken_quantity_tax ?? 0),
        ]);
    }

    private function remainingRefundableAmount(\Modules\SalesReturn\Entities\SaleReturn $saleReturn): float
    {
        $totalAmount = (float) $saleReturn->total_amount;
        $paidAmount = (float) $saleReturn->paid_amount;
        $dueAmount = $saleReturn->due_amount !== null
            ? (float) $saleReturn->due_amount
            : max(0, $totalAmount - $paidAmount);

        return max(0, min($dueAmount, $totalAmount - $paidAmount));
    }

    private function assertReplacementDispatchEligibility(\Modules\SalesReturn\Entities\SaleReturnDetail $detail): void
    {
        $line = $detail->posReturnLine;

        if (! $line) {
            throw new \RuntimeException('Detail retur POS tidak memiliki referensi baris sumber untuk pengiriman pengganti.');
        }

        $expectedReplacementProductId = (int) ($line->replacement_product_id ?: $line->product_id);

        if ($expectedReplacementProductId !== (int) $detail->product_id) {
            throw new \RuntimeException('Produk pengganti harus menggunakan SKU yang sama dengan barang yang diretur.');
        }

        $replacementQuantity = $this->resolveReplacementQuantityForDispatch($detail, $line);

        if (! $this->quantitiesMatch($replacementQuantity, (float) $detail->quantity)) {
            throw new \RuntimeException('Jumlah barang pengganti harus sama dengan jumlah barang retur yang sudah diterima.');
        }

        if ((int) $line->source_setting_id !== (int) $detail->saleReturn->setting_id) {
            throw new \RuntimeException('Pengiriman pengganti harus berasal dari owner atau setting sumber asli retur.');
        }

        if ((int) $line->source_location_id !== (int) $detail->location_id) {
            throw new \RuntimeException('Pengiriman pengganti harus berasal dari lokasi sumber asli retur.');
        }
    }

    private function resolveReplacementQuantityForDispatch(
        \Modules\SalesReturn\Entities\SaleReturnDetail $detail,
        \Modules\Pos\Entities\PosReturnLine $line
    ): float {
        if ($line->replacement_quantity !== null) {
            return (float) $line->replacement_quantity;
        }

        if ((string) $line->resolution !== \Modules\Pos\Entities\PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT) {
            return 0.0;
        }

        $lineQuantity = (float) $line->quantity;
        $detailQuantity = (float) $detail->quantity;

        if ($lineQuantity > 0 && $this->quantitiesMatch($lineQuantity, $detailQuantity)) {
            $line->forceFill([
                'replacement_quantity' => $line->quantity,
            ])->save();

            return $lineQuantity;
        }

        return 0.0;
    }

    private function quantitiesMatch(float $left, float $right): bool
    {
        return abs($left - $right) < 0.0001;
    }

    public function archive(int $posReturnId, ?string $reason = null): void
    {
        $this->runLifecycleMutation($posReturnId, 'archive', function () use ($posReturnId, $reason) {
            $this->transitionToAuditedReversalState($posReturnId, \Modules\Pos\Entities\PosReturn::STATUS_ARCHIVED, $reason);
        });
    }

    public function cancel(int $posReturnId, ?string $reason = null): void
    {
        $this->runLifecycleMutation($posReturnId, 'cancel', function () use ($posReturnId, $reason) {
            $this->transitionToAuditedReversalState($posReturnId, \Modules\Pos\Entities\PosReturn::STATUS_CANCELLED, $reason);
        });
    }

    private function transitionToAuditedReversalState(int $posReturnId, string $targetStatus, ?string $reason = null): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($posReturnId, $targetStatus, $reason) {
            $posReturn = \Modules\Pos\Entities\PosReturn::query()
                ->with('saleReturns')
                ->whereKey($posReturnId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertManualCorrectionIsNotRequired($posReturn);

            $this->assertCanBeReversedBeforeReceiving($posReturn, $targetStatus);

            $actorId = \Illuminate\Support\Facades\Auth::id();
            $timestamp = now();

            $attributes = [
                'status' => $targetStatus,
                'is_reversed' => true,
                'updated_by' => $actorId,
            ];

            if ($targetStatus === \Modules\Pos\Entities\PosReturn::STATUS_ARCHIVED) {
                $attributes['archived_by'] = $actorId;
                $attributes['archived_at'] = $timestamp;
                $attributes['archive_reason'] = $reason;
            } else {
                $attributes['cancelled_by'] = $actorId;
                $attributes['cancelled_at'] = $timestamp;
                $attributes['cancel_reason'] = $reason;
            }

            $posReturn->update($attributes);

            foreach ($posReturn->saleReturns as $saleReturn) {
                $this->applyAuditedReversalToLinkedSaleReturn($saleReturn, $targetStatus, $actorId, $timestamp, $reason);
            }
        });
    }

    protected function applyAuditedReversalToLinkedSaleReturn(
        \Modules\SalesReturn\Entities\SaleReturn $saleReturn,
        string $targetStatus,
        ?int $actorId,
        \Illuminate\Support\Carbon $timestamp,
        ?string $reason = null
    ): void {
        if ($targetStatus === \Modules\Pos\Entities\PosReturn::STATUS_ARCHIVED) {
            $saleReturn->forceFill([
                'archived_by' => $actorId,
                'archived_at' => $timestamp,
            ])->save();

            return;
        }

        $saleReturn->update([
            'status' => 'Cancelled',
            'rejected_by' => $actorId,
            'rejected_at' => $timestamp,
            'rejection_reason' => $reason,
        ]);
    }

    private function assertCanBeReversedBeforeReceiving(\Modules\Pos\Entities\PosReturn $posReturn, string $targetStatus): void
    {
        $isBlocked = $posReturn->received_at !== null
            || in_array($posReturn->status, [
                \Modules\Pos\Entities\PosReturn::STATUS_AWAITING_SETTLEMENT,
                \Modules\Pos\Entities\PosReturn::STATUS_AWAITING_DISPATCH,
                \Modules\Pos\Entities\PosReturn::STATUS_COMPLETED,
            ], true);

        if ($isBlocked) {
            if ($targetStatus === \Modules\Pos\Entities\PosReturn::STATUS_ARCHIVED) {
                throw new \RuntimeException('Retur POS yang sudah diterima, diselesaikan, atau dikirim tidak dapat diarsipkan.');
            }

            throw new \RuntimeException('Retur POS yang sudah diterima, diselesaikan, atau dikirim tidak dapat dibatalkan.');
        }

        if (! in_array($posReturn->status, [
            \Modules\Pos\Entities\PosReturn::STATUS_APPROVED,
            \Modules\Pos\Entities\PosReturn::STATUS_AWAITING_RECEIVING,
        ], true)) {
            throw new \RuntimeException('Hanya retur POS yang sudah disetujui dan belum diterima yang dapat dibalik secara audit.');
        }
    }

    /**
     * Reject a POS return.
     *
     * @param int $posReturnId
     * @param string|null $reason
     * @return void
     */
    public function reject(int $posReturnId, ?string $reason = null): void
    {
        $this->runLifecycleMutation($posReturnId, 'reject', function () use ($posReturnId, $reason) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($posReturnId, $reason) {
            $posReturn = \Modules\Pos\Entities\PosReturn::query()
                ->whereKey($posReturnId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertManualCorrectionIsNotRequired($posReturn);

            if ($posReturn->status !== \Modules\Pos\Entities\PosReturn::STATUS_PENDING_APPROVAL) {
                throw new \Exception("Only pending approval returns can be rejected.");
            }

            $actorId = \Illuminate\Support\Facades\Auth::id();
            $rejectedAt = now();

            $posReturn->update([
                'status' => \Modules\Pos\Entities\PosReturn::STATUS_REJECTED,
                'approval_status' => \Modules\Pos\Entities\PosReturn::APPROVAL_STATUS_REJECTED,
                'rejected_by' => $actorId,
                'rejected_at' => $rejectedAt,
                'rejection_reason' => $reason,
            ]);

            $this->syncRejectedSaleReturns($posReturn, $actorId, $rejectedAt, $reason);
            });
        });
    }

    protected function runLifecycleMutation(int $posReturnId, string $lifecycleAction, callable $callback): void
    {
        try {
            $callback();
        } catch (PosReturnManualCorrectionRequiredException $exception) {
            $this->markManualCorrectionRequired($posReturnId, $exception->lifecycleAction(), $exception->auditReason());

            throw new \RuntimeException(
                'Retur POS memerlukan koreksi manual teraudit sebelum proses berikutnya dapat dijalankan. ' . $exception->auditReason(),
                0,
                $exception
            );
        }
    }

    protected function markManualCorrectionRequired(int $posReturnId, string $lifecycleAction, string $reason): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($posReturnId, $lifecycleAction, $reason) {
            $posReturn = PosReturn::query()
                ->whereKey($posReturnId)
                ->lockForUpdate()
                ->firstOrFail();

            $posReturn->forceFill([
                'status' => PosReturn::STATUS_MANUAL_CORRECTION_REQUIRED,
                'manual_correction_action' => $lifecycleAction,
                'manual_correction_reason' => $reason,
                'manual_correction_required_by' => \Illuminate\Support\Facades\Auth::id(),
                'manual_correction_required_at' => now(),
                'updated_by' => \Illuminate\Support\Facades\Auth::id(),
            ])->save();
        });
    }

    protected function assertManualCorrectionIsNotRequired(PosReturn $posReturn): void
    {
        if (! $posReturn->requiresManualCorrection()) {
            return;
        }

        throw new \RuntimeException('Retur POS ini sedang diblokir dan memerlukan koreksi manual teraudit sebelum aksi lifecycle lain dijalankan.');
    }

    protected function syncRejectedSaleReturns(
        \Modules\Pos\Entities\PosReturn $posReturn,
        ?int $actorId,
        \Illuminate\Support\Carbon $rejectedAt,
        ?string $reason = null
    ): void {
        foreach ($posReturn->saleReturns as $saleReturn) {
            $saleReturn->update([
                'status' => 'REJECTED',
                'approval_status' => 'REJECTED',
                'rejected_by' => $actorId,
                'rejected_at' => $rejectedAt,
                'rejection_reason' => $reason,
                'approved_by' => null,
                'approved_at' => null,
            ]);
        }
    }
}
