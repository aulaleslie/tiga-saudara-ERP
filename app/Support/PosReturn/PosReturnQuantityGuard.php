<?php

namespace App\Support\PosReturn;

use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\SaleDetails;

class PosReturnQuantityGuard
{
    /**
     * Get still-returnable quantity for a dispatch detail or sale detail.
     *
     * @param int|null $dispatchDetailId
     * @param int|null $saleDetailId
     * @param int|null $excludeReturnId
     * @return float
     */
    public function getReturnableQuantity(?int $dispatchDetailId, ?int $saleDetailId = null, ?int $excludeReturnId = null): float
    {
        $originalQty = 0;
        
        if ($dispatchDetailId) {
            $dispatchDetail = DispatchDetail::find($dispatchDetailId);
            $originalQty = (float) ($dispatchDetail->dispatched_quantity ?? 0);
        } elseif ($saleDetailId) {
            // Fallback for stockless lines or cases where dispatch detail is missing
            $saleDetail = SaleDetails::find($saleDetailId);
            $originalQty = (float) ($saleDetail->quantity ?? 0);
        }

        // If an excluded return is completed, its cash return reduction was already applied
        // to the live quantity in DB, so add it back to compute available entitlement excluding it.
        $completedCashReduction = 0;
        if ($excludeReturnId) {
            $excludedReturn = PosReturn::find($excludeReturnId);
            if ($excludedReturn && $excludedReturn->status === PosReturn::STATUS_COMPLETED && !$excludedReturn->is_reversed) {
                $completedCashReduction = (float) PosReturnLine::where('pos_return_id', $excludeReturnId)
                    ->where('resolution', PosReturnLine::RESOLUTION_CASH_RETURN)
                    ->when($dispatchDetailId, function ($q) use ($dispatchDetailId) {
                        $q->where('dispatch_detail_id', $dispatchDetailId);
                    }, function ($q) use ($saleDetailId) {
                        $q->where('sale_detail_id', $saleDetailId);
                    })
                    ->sum('quantity');
            }
        }

        // In-flight claims: active returns that have not yet executed/completed.
        // Completed returns have already reduced live quantity (for cash returns)
        // or preserved the held unit (for product replacements).
        $inFlightQty = (float) PosReturnLine::whereHas('posReturn', function ($q) use ($excludeReturnId) {
                $q->where('is_reversed', false)
                  ->whereIn('status', [
                      PosReturn::STATUS_PENDING_APPROVAL,
                      PosReturn::STATUS_APPROVED,
                      PosReturn::STATUS_AWAITING_RECEIVING,
                      PosReturn::STATUS_AWAITING_SETTLEMENT,
                      PosReturn::STATUS_AWAITING_DISPATCH,
                      PosReturn::STATUS_MANUAL_CORRECTION_REQUIRED,
                  ]);
                if ($excludeReturnId) {
                    $q->where('id', '!=', $excludeReturnId);
                }
            })
            ->when($dispatchDetailId, function ($q) use ($dispatchDetailId) {
                $q->where('dispatch_detail_id', $dispatchDetailId);
            }, function ($q) use ($saleDetailId) {
                $q->where('sale_detail_id', $saleDetailId);
            })
            ->sum('quantity');

        return max(0, ($originalQty + $completedCashReduction) - $inFlightQty);
    }

    /**
     * Get still-refundable (cash_return) quantity for a sale detail.
     *
     * Unlike getReturnableQuantity() (which counts every resolution — a
     * product_replacement still consumes a finite physical unit that can't
     * also be cash-returned, or dispatched again, twice), this variant only
     * counts quantity already consumed by an active cash_return line.
     * product_replacement never reduces refundable commercial quantity: the
     * customer is left holding an equivalent replacement unit, so a later
     * whole-bundle cash return remains eligible for that same logical
     * quantity. Only an actual cash refund (or a rejected/cancelled return,
     * which consumes nothing per PosReturn::consumesReturnQuantity()) can
     * exhaust what's still refundable.
     *
     * A bundle component attached to a split-owner "carrier" SaleDetails row
     * (a zero-quantity row keyed by the PARENT's product_id, holding the
     * component only via SaleBundleItem/DispatchDetail) has no usable own
     * SaleDetails.quantity — that column is always 0 by construction. Pass
     * $componentOriginalQuantity (the component's true original quantity,
     * e.g. quantity_per_bundle × fully-refundable parent quantity) to use
     * that instead of reading SaleDetails.quantity. Already-cash-returned
     * consumption is still scoped to $saleDetailId, which remains a valid,
     * consistent accounting key: synthesized component cash_return lines are
     * always persisted against the same carrier sale_detail_id.
     *
     * @param int|null $saleDetailId
     * @param int|null $excludeReturnId
     * @param float|null $componentOriginalQuantity
     * @return float
     */
    public function getRefundableQuantity(?int $saleDetailId, ?int $excludeReturnId = null, ?float $componentOriginalQuantity = null): float
    {
        if (!$saleDetailId) {
            return 0.0;
        }

        if ($componentOriginalQuantity !== null) {
            $originalQty = (float) $componentOriginalQuantity;
        } else {
            $saleDetail = SaleDetails::find($saleDetailId);
            $originalQty = (float) ($saleDetail->quantity ?? 0);
        }

        $completedCashReduction = 0;
        if ($excludeReturnId && $componentOriginalQuantity === null) {
            $excludedReturn = PosReturn::find($excludeReturnId);
            if ($excludedReturn && $excludedReturn->status === PosReturn::STATUS_COMPLETED && !$excludedReturn->is_reversed) {
                $completedCashReduction = (float) PosReturnLine::where('pos_return_id', $excludeReturnId)
                    ->where('sale_detail_id', $saleDetailId)
                    ->where('resolution', PosReturnLine::RESOLUTION_CASH_RETURN)
                    ->sum('quantity');
            }
        }

        $inFlightCashReturns = (float) PosReturnLine::whereHas('posReturn', function ($q) use ($excludeReturnId) {
                $q->where('is_reversed', false)
                  ->whereIn('status', [
                      PosReturn::STATUS_PENDING_APPROVAL,
                      PosReturn::STATUS_APPROVED,
                      PosReturn::STATUS_AWAITING_RECEIVING,
                      PosReturn::STATUS_AWAITING_SETTLEMENT,
                      PosReturn::STATUS_AWAITING_DISPATCH,
                      PosReturn::STATUS_MANUAL_CORRECTION_REQUIRED,
                  ]);
                if ($excludeReturnId) {
                    $q->where('id', '!=', $excludeReturnId);
                }
            })
            ->where('sale_detail_id', $saleDetailId)
            ->where('resolution', PosReturnLine::RESOLUTION_CASH_RETURN)
            ->sum('quantity');

        return max(0, ($originalQty + $completedCashReduction) - $inFlightCashReturns);
    }

    /**
     * Check if the requested return quantity is valid.
     *
     * @param int|null $dispatchDetailId
     * @param float $requestedQuantity
     * @param array $options
     * @return bool
     */
    public function isValid(?int $dispatchDetailId, float $requestedQuantity, array $options = []): bool
    {
        if ($requestedQuantity <= 0) {
            return false;
        }

        $saleDetailId = $options['sale_detail_id'] ?? null;
        $excludeReturnId = $options['exclude_return_id'] ?? null;
        $returnableQty = $this->getReturnableQuantity($dispatchDetailId, $saleDetailId, $excludeReturnId);

        // Allow a small epsilon for float comparison if needed, but standard return quantities are usually discrete or 4 decimals.
        return round($requestedQuantity, 4) <= round($returnableQty, 4);
    }
}
