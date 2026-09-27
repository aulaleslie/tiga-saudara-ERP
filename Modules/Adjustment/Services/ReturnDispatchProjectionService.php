<?php

namespace Modules\Adjustment\Services;

use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementReturnObligation;

class ReturnDispatchProjectionService
{
    public function __construct(
        private ReturnDispatchComparatorService $comparator,
    ) {
    }

    /**
     * Build preparation projection for a return-dispatch batch draft. Product identities and the
     * operator's own observations are always visible; obligation/requested quantities, stock,
     * and differences require system-stock visibility.
     */
    public function getPreparationProjection(Transfer $transfer, TransferMovement $movement, bool $canViewSystemStock): array
    {
        $movement->loadMissing(['lines.product', 'lines.serials']);

        $obligationsByProduct = TransferMovementReturnObligation::where('transfer_id', $transfer->id)
            ->where('stock_condition', $movement->stock_condition)
            ->get()
            ->keyBy('product_id');

        $lines = [];
        foreach ($movement->lines as $line) {
            $product = $line->product;
            $isSerialized = (bool) ($product?->serial_number_required ?? false);

            $serials = [];
            foreach ($line->serials as $ser) {
                $serials[] = [
                    'id'            => (int) $ser->product_serial_number_id,
                    'serial_number' => (string) $ser->serial_number,
                ];
            }

            $lineData = [
                'line_id'         => (int) $line->id,
                'product_id'      => (int) $line->product_id,
                'product_name'    => (string) ($product?->product_name ?? ''),
                'product_code'    => (string) ($product?->product_code ?? ''),
                'is_serialized'   => $isSerialized,
                'quantity'        => (string) $line->quantity,
                'count_confirmed' => (bool) $line->count_confirmed,
                'serials'         => $serials,
            ];

            if ($canViewSystemStock) {
                $obligation = $obligationsByProduct->get((int) $line->product_id);
                if ($obligation) {
                    $obligation->loadMissing('activeReservations');
                    $lineData['obligation_available_capacity'] = $obligation->availableCapacity();
                    $lineData['obligation_outstanding_quantity'] = $obligation->outstandingQuantity();
                }
            }

            $lines[] = $lineData;
        }

        $projection = [
            'transfer_id'     => (int) $transfer->id,
            'movement_id'     => (int) $movement->id,
            'return_batch_id' => (string) $movement->return_batch_id,
            'revision'        => (int) $movement->revision,
            'lock_version'    => (int) $movement->lock_version,
            'status'          => (string) $movement->status,
            'stock_condition' => (string) $movement->stock_condition,
            'lines'           => $lines,
        ];

        // Product identity for every obligated product is always visible so a blind operator knows
        // which products to look for, since only quantities, capacity, stock, allocations, and
        // expected serials are protected — not which products participate in this return.
        $projection['obligated_products'] = $obligationsByProduct->values()->map(function (TransferMovementReturnObligation $obligation) use ($canViewSystemStock) {
            $obligation->loadMissing(['activeReservations', 'product']);

            $productData = [
                'product_id'      => (int) $obligation->product_id,
                'product_name'    => (string) ($obligation->product?->product_name ?? ''),
                'product_code'    => (string) ($obligation->product?->product_code ?? ''),
                'stock_condition' => (string) $obligation->stock_condition,
            ];

            if ($canViewSystemStock) {
                $productData['available_capacity'] = $obligation->availableCapacity();
                $productData['outstanding_quantity'] = $obligation->outstandingQuantity();
            }

            return $productData;
        })->values()->all();

        return $projection;
    }

    /**
     * Build approval projection for a pending return-dispatch batch. Neutral for blind reviewers,
     * detailed for privileged approvers.
     */
    public function getApprovalProjection(Transfer $transfer, TransferMovement $movement, bool $canViewSystemStock): array
    {
        $comparison = $this->comparator->compare($transfer, $movement);

        if (!$canViewSystemStock) {
            return [
                'movement_id'     => (int) $movement->id,
                'return_batch_id' => (string) $movement->return_batch_id,
                'revision'        => (int) $movement->revision,
                'status'          => (string) $movement->status,
                'stock_condition' => (string) $movement->stock_condition,
                'matches'         => (bool) $comparison['matches'],
                'message'         => $comparison['matches']
                    ? 'Hasil perhitungan fisik batch retur sesuai dengan kewajiban transfer.'
                    : 'Terdapat ketidaksesuaian pada batch retur ini.',
            ];
        }

        return [
            'movement_id'     => (int) $movement->id,
            'return_batch_id' => (string) $movement->return_batch_id,
            'revision'        => (int) $movement->revision,
            'status'          => (string) $movement->status,
            'stock_condition' => (string) $movement->stock_condition,
            'matches'         => (bool) $comparison['matches'],
            'summary'         => $comparison['summary'],
            'details'         => $comparison['details'],
        ];
    }
}
