<?php

namespace Modules\Consignment\Services;

use Modules\Consignment\Entities\ConsignmentBillingConfirmationLine;
use Modules\Consignment\Entities\ConsignmentSoldSource;

class ConsignmentReturnEligibilityService
{
    /**
     * Get effective physically received return quantity for a dispatch detail.
     *
     * @param int $dispatchDetailId
     * @return float
     */
    public function getEffectiveReturnQuantity(int $dispatchDetailId): float
    {
        if (! class_exists(\Modules\SalesReturn\Entities\SaleReturnDetail::class)) {
            return 0.0;
        }

        $details = \Modules\SalesReturn\Entities\SaleReturnDetail::where('dispatch_detail_id', $dispatchDetailId)
            ->whereHas('saleReturn', function ($query) {
                $query->whereIn('status', [
                    'Awaiting Settlement',
                    'AWAITING SETTLEMENT',
                    'Completed',
                    'COMPLETED',
                ]);
            })
            ->get();

        $totalReturned = 0.0;
        foreach ($details as $detail) {
            $totalReturned += (float) ($detail->returned_quantity ?? $detail->quantity ?? 0);
        }

        return $totalReturned;
    }

    /**
     * Get list of returned serial IDs for a dispatch detail from physically received returns.
     *
     * @param int $dispatchDetailId
     * @return array Array of returned product_serial_number_ids
     */
    public function getEffectiveReturnedSerialIds(int $dispatchDetailId): array
    {
        if (! class_exists(\Modules\SalesReturn\Entities\SaleReturnDetail::class)) {
            return [];
        }

        $details = \Modules\SalesReturn\Entities\SaleReturnDetail::where('dispatch_detail_id', $dispatchDetailId)
            ->whereHas('saleReturn', function ($query) {
                $query->whereIn('status', [
                    'Awaiting Settlement',
                    'AWAITING SETTLEMENT',
                    'Completed',
                    'COMPLETED',
                ]);
            })
            ->get();

        $returnedSerialIds = [];
        foreach ($details as $detail) {
            $serials = $detail->serial_number_ids;
            if (is_string($serials)) {
                $serials = json_decode($serials, true) ?: [];
            }
            if (is_array($serials)) {
                foreach ($serials as $id) {
                    if (is_numeric($id)) {
                        $returnedSerialIds[] = (int) $id;
                    }
                }
            }
        }

        return array_values(array_unique($returnedSerialIds));
    }

    /**
     * Calculate sold source eligibility metrics.
     *
     * @param ConsignmentSoldSource $source
     * @param int|null $excludeConfirmationId Optional confirmation ID to exclude from pending calculation (e.g. current draft/waiting confirmation).
     * @return array
     */
    public function calculateSoldEligibility(ConsignmentSoldSource $source, ?int $excludeConfirmationId = null): array
    {
        $originalSold = (float) $source->original_base_quantity;
        $effectiveReturned = $this->getEffectiveReturnQuantity($source->dispatch_detail_id);

        $approvedQuery = ConsignmentBillingConfirmationLine::where('consignment_sold_source_id', $source->id)
            ->whereHas('confirmation', function ($q) {
                $q->where('status', \Modules\Consignment\Entities\ConsignmentBillingConfirmation::STATUS_APPROVED);
            });
        $approvedAllocated = (float) $approvedQuery->sum('allocated_base_quantity');

        $pendingQuery = ConsignmentBillingConfirmationLine::where('consignment_sold_source_id', $source->id)
            ->whereHas('confirmation', function ($q) use ($excludeConfirmationId) {
                $q->where('status', \Modules\Consignment\Entities\ConsignmentBillingConfirmation::STATUS_WAITING_APPROVAL);
                if ($excludeConfirmationId) {
                    $q->where('id', '!=', $excludeConfirmationId);
                }
            });
        $pendingReserved = (float) $pendingQuery->sum('allocated_base_quantity');

        $netEligible = max(0.0, $originalSold - $effectiveReturned);
        $remainingQuantity = max(0.0, $netEligible - $approvedAllocated - $pendingReserved);

        $hasConflict = false;
        $conflictReason = null;

        if ($source->has_reconstruction_blocker) {
            $hasConflict = true;
            $conflictReason = "Historical reconstruction blocker: {$source->blocker_reason}";
        } elseif (($approvedAllocated + $pendingReserved) > $netEligible + 0.0001) {
            $hasConflict = true;
            $conflictReason = "Over-allocation detected: Approved ({$approvedAllocated}) + Pending ({$pendingReserved}) exceeds net eligible quantity ({$netEligible}).";
        }

        return [
            'original_sold' => $originalSold,
            'effective_returned' => $effectiveReturned,
            'net_eligible' => $netEligible,
            'approved_allocated' => $approvedAllocated,
            'pending_reserved' => $pendingReserved,
            'remaining_quantity' => $remainingQuantity,
            'has_conflict' => $hasConflict,
            'conflict_reason' => $conflictReason,
        ];
    }
}
