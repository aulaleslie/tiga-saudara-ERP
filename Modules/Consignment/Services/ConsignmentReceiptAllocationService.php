<?php

namespace Modules\Consignment\Services;

use Modules\Consignment\Entities\ConsignmentActiveSerialClaim;
use Modules\Consignment\Entities\ConsignmentReceiptAllocation;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Product\Entities\ProductSerialNumber;

class ConsignmentReceiptAllocationService
{
    /**
     * Resolve exact serialized receiving lineage and supplier identity.
     *
     * @param ProductSerialNumber $serial
     * @param int $settingId
     * @param int $locationId
     * @return array Lineage details or blocker reason.
     */
    public function resolveSerialLineage(
        ProductSerialNumber $serial,
        int $settingId,
        int $locationId,
        ?int $excludeConfirmationId = null
    ): array {
        $crd = null;

        // Try direct receiving detail linkage first
        if ($serial->consignment_receiving_detail_id) {
            $crd = ConsignmentReceivingDetail::with(['consignmentReceiving.receival'])->find($serial->consignment_receiving_detail_id);
        }

        // Fallback to pivot linkage
        if (! $crd) {
            $pivot = \Illuminate\Support\Facades\DB::table('consignment_receiving_detail_serial_numbers')
                ->where('product_serial_number_id', $serial->id)
                ->first();

            if ($pivot) {
                $crd = ConsignmentReceivingDetail::with(['consignmentReceiving.receival'])->find($pivot->consignment_receiving_detail_id);
            }
        }

        if (! $crd) {
            return [
                'has_blocker' => true,
                'blocker_reason' => "No approved consignment receiving detail found for serial number {$serial->serial_number}.",
            ];
        }

        $receiving = $crd->consignmentReceiving;
        if (! $receiving || $receiving->status !== \Modules\Consignment\Entities\ConsignmentReceiving::STATUS_APPROVED) {
            return [
                'has_blocker' => true,
                'blocker_reason' => "Consignment receiving for serial {$serial->serial_number} is not approved or has been reversed.",
            ];
        }

        if ($receiving->setting_id != $settingId || $receiving->location_id != $locationId || $crd->product_id != $serial->product_id) {
            return [
                'has_blocker' => true,
                'blocker_reason' => "Serial {$serial->serial_number} receiving setting/location/product mismatch.",
            ];
        }

        $receival = $receiving->receival;
        if (! $receival || ! $receival->supplier_id) {
            return [
                'has_blocker' => true,
                'blocker_reason' => "Supplier reference missing for serial {$serial->serial_number} receiving.",
            ];
        }

        // Check if serial has another active claim
        $claimQuery = ConsignmentActiveSerialClaim::where('product_serial_number_id', $serial->id);
        if ($excludeConfirmationId) {
            $claimQuery->where('consignment_billing_confirmation_id', '!=', $excludeConfirmationId);
        }
        $activeClaim = $claimQuery->first();
        if ($activeClaim) {
            return [
                'has_blocker' => true,
                'blocker_reason' => "Serial {$serial->serial_number} is already claimed in active confirmation #{$activeClaim->consignment_billing_confirmation_id}.",
                'active_claim_confirmation_id' => $activeClaim->consignment_billing_confirmation_id,
            ];
        }

        return [
            'has_blocker' => false,
            'blocker_reason' => null,
            'supplier_id' => $receival->supplier_id,
            'consignment_receiving_detail_id' => $crd->id,
            'receival_number' => $receival->receival_number ?? null,
            'receiving_number' => $receiving->receiving_number ?? null,
            'unit_cost' => (float) $crd->unit_cost,
            'unit_dpp' => (float) $crd->unit_dpp,
            'tax_id' => $crd->tax_id,
            'tax_rate' => (float) $crd->tax_rate,
            'tax_amount' => (float) $crd->tax_amount,
        ];
    }

    /**
     * Query eligible receipt pools for a given setting, supplier, product, and location.
     *
     * @param int $settingId
     * @param int $supplierId
     * @param int $productId
     * @param int $locationId
     * @param int|null $excludeConfirmationId
     * @return array
     */
    public function getEligibleReceiptPools(
        int $settingId,
        int $supplierId,
        int $productId,
        int $locationId,
        ?int $excludeConfirmationId = null
    ): array {
        $details = ConsignmentReceivingDetail::where('product_id', $productId)
            ->whereHas('consignmentReceiving', function ($q) use ($settingId, $locationId, $supplierId) {
                $q->where('setting_id', $settingId)
                    ->where('location_id', $locationId)
                    ->where('status', \Modules\Consignment\Entities\ConsignmentReceiving::STATUS_APPROVED)
                    ->whereHas('receival', function ($rq) use ($supplierId) {
                        $rq->where('supplier_id', $supplierId);
                    });
            })
            ->with(['consignmentReceiving.receival'])
            ->get();

        $pools = [];
        foreach ($details as $detail) {
            $receivedQty = (float) $detail->quantity_received;

            $approvedQuery = ConsignmentReceiptAllocation::where('consignment_receiving_detail_id', $detail->id)
                ->whereHas('line.confirmation', function ($q) {
                    $q->where('status', \Modules\Consignment\Entities\ConsignmentBillingConfirmation::STATUS_APPROVED);
                });
            $approvedAllocated = (float) $approvedQuery->sum('allocated_base_quantity');

            $pendingQuery = ConsignmentReceiptAllocation::where('consignment_receiving_detail_id', $detail->id)
                ->whereHas('line.confirmation', function ($q) use ($excludeConfirmationId) {
                    $q->where('status', \Modules\Consignment\Entities\ConsignmentBillingConfirmation::STATUS_WAITING_APPROVAL);
                    if ($excludeConfirmationId) {
                        $q->where('id', '!=', $excludeConfirmationId);
                    }
                });
            $pendingReserved = (float) $pendingQuery->sum('allocated_base_quantity');

            $remainingQty = max(0.0, $receivedQty - $approvedAllocated - $pendingReserved);

            $pools[] = [
                'consignment_receiving_detail_id' => $detail->id,
                'receiving_number' => $detail->consignmentReceiving->receiving_number ?? null,
                'receival_number' => $detail->consignmentReceiving->receival->receival_number ?? null,
                'unit_cost' => (float) $detail->unit_cost,
                'unit_dpp' => (float) $detail->unit_dpp,
                'tax_id' => $detail->tax_id,
                'tax_rate' => (float) $detail->tax_rate,
                'tax_amount' => (float) $detail->tax_amount,
                'quantity_received' => $receivedQty,
                'approved_allocated' => $approvedAllocated,
                'pending_reserved' => $pendingReserved,
                'remaining_quantity' => $remainingQty,
            ];
        }

        return $pools;
    }

    /**
     * Validate manual receipt allocations for non-serialized items.
     *
     * @param array $requestedAllocations List of ['consignment_receiving_detail_id' => int, 'allocated_base_quantity' => float]
     * @param float $expectedTotalQty
     * @param int $settingId
     * @param int $supplierId
     * @param int $productId
     * @param int $locationId
     * @param int|null $excludeConfirmationId
     * @return array Validation result
     */
    public function validateReceiptAllocations(
        array $requestedAllocations,
        float $expectedTotalQty,
        int $settingId,
        int $supplierId,
        int $productId,
        int $locationId,
        ?int $excludeConfirmationId = null
    ): array {
        $allocatedSum = 0.0;
        $errors = [];

        $pools = $this->getEligibleReceiptPools($settingId, $supplierId, $productId, $locationId, $excludeConfirmationId);
        $poolsById = collect($pools)->keyBy('consignment_receiving_detail_id');

        // Aggregate requested quantities by consignment_receiving_detail_id to prevent duplicate row exploits
        $aggregatedRequests = [];
        foreach ($requestedAllocations as $alloc) {
            $crdId = $alloc['consignment_receiving_detail_id'] ?? null;
            $qty = (float) ($alloc['allocated_base_quantity'] ?? 0);
            if ($crdId && $qty > 0) {
                $aggregatedRequests[$crdId] = ($aggregatedRequests[$crdId] ?? 0.0) + $qty;
            }
        }

        foreach ($aggregatedRequests as $crdId => $qty) {
            $allocatedSum += $qty;

            if (! isset($poolsById[$crdId])) {
                $errors[] = "Receipt detail #{$crdId} is not an eligible receipt pool for supplier #{$supplierId} and location #{$locationId}.";
                continue;
            }

            $pool = $poolsById[$crdId];
            if ($qty > $pool['remaining_quantity'] + 0.0001) {
                $errors[] = "Requested quantity ({$qty}) exceeds remaining pool capacity ({$pool['remaining_quantity']}) for receiving #{$pool['receiving_number']}.";
            }
        }

        if (abs($allocatedSum - $expectedTotalQty) > 0.0001) {
            $errors[] = "Receipt allocations sum ({$allocatedSum}) does not match required total sold quantity ({$expectedTotalQty}).";
        }

        return [
            'is_valid' => empty($errors),
            'allocated_sum' => $allocatedSum,
            'errors' => $errors,
        ];
    }
}
