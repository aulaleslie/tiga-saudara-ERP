<?php

namespace Modules\Adjustment\Services;

use Modules\Product\Entities\ProductStock;
use Modules\Adjustment\DTOs\AllocationPreview;

class TransferAllocationPreviewService
{
    /**
     * Preview the allocation of non-serialized stock.
     * Consumes non-tax stock before tax stock for the specified mode.
     *
     * @param ProductStock $stock
     * @param float $requestedQuantity Base unit quantity
     * @param bool $isBrokenMode Whether to consume broken stock instead of normal stock
     * @return AllocationPreview
     */
    public function previewAllocation(ProductStock $stock, float $requestedQuantity, bool $isBrokenMode): AllocationPreview
    {
        if ($isBrokenMode) {
            $availableNonTax = (float) $stock->broken_quantity_non_tax;
            $availableTax = (float) $stock->broken_quantity_tax;
            $totalAvailable = (float) $stock->broken_quantity;
        } else {
            $availableNonTax = (float) $stock->quantity_non_tax;
            $availableTax = (float) $stock->quantity_tax;
            $totalAvailable = (float) $stock->quantity;
        }

        $isInsufficient = $requestedQuantity > $totalAvailable;
        
        // We only allocate up to the requested amount, or whatever is available if it's insufficient.
        // Actually, if it's insufficient, the preview might still allocate what it can.
        $allocatedNonTax = min($requestedQuantity, $availableNonTax);
        $remainingRequest = max(0.0, $requestedQuantity - $allocatedNonTax);
        
        $allocatedTax = min($remainingRequest, $availableTax);
        
        return new AllocationPreview(
            allocatedNonTax: $allocatedNonTax,
            allocatedTax: $allocatedTax,
            isInsufficient: $isInsufficient,
            mandatoryReturnQuantity: $allocatedTax
        );
    }
}
