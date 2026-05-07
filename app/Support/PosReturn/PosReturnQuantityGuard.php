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
                $q->active();
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
