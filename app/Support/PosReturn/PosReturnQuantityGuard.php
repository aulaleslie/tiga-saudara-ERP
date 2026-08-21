<?php

namespace App\Support\PosReturn;

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

        $alreadyReturned = (float) PosReturnLine::whereHas('posReturn', function ($q) use ($excludeReturnId) {
                $q->consumesReturnQuantity();
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

        return max(0, $originalQty - $alreadyReturned);
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

        $alreadyCashReturned = (float) PosReturnLine::whereHas('posReturn', function ($q) use ($excludeReturnId) {
                $q->consumesReturnQuantity();
                if ($excludeReturnId) {
                    $q->where('id', '!=', $excludeReturnId);
                }
            })
            ->where('sale_detail_id', $saleDetailId)
            ->where('resolution', PosReturnLine::RESOLUTION_CASH_RETURN)
            ->sum('quantity');

        return max(0, $originalQty - $alreadyCashReturned);
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
