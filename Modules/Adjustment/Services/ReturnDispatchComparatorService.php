<?php

namespace Modules\Adjustment\Services;

use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementReturnObligation;

class ReturnDispatchComparatorService
{
    /**
     * Compare a return-dispatch batch's counted lines against its transfer's product/condition
     * obligations. Unlike the forward-dispatch comparator, this:
     *  - Does not require every obligation to be present (partial batches are valid).
     *  - Does not compare exact serial identity (substitute serials are permitted); it only
     *    validates that the counted serial count matches the counted quantity and that every
     *    counted product is an actual obligated product/condition for this transfer.
     *
     * @return array{
     *     matches: bool,
     *     summary: array{
     *         total_counted_products: int,
     *         mismatched_products_count: int,
     *         unexpected_products_count: int,
     *     },
     *     details: array<int, array{
     *         product_id: int,
     *         product_name: string,
     *         product_code: string,
     *         is_serialized: bool,
     *         counted_quantity: string,
     *         counted_serial_count: int,
     *         status: string, // MATCH, UNEXPECTED, SERIAL_COUNT_MISMATCH, ZERO_QUANTITY
     *     }>
     * }
     */
    public function compare(Transfer $transfer, TransferMovement $movement): array
    {
        $movement->loadMissing(['lines.product', 'lines.serials']);

        $obligationsByProduct = TransferMovementReturnObligation::where('transfer_id', $transfer->id)
            ->where('stock_condition', $movement->stock_condition)
            ->get()
            ->keyBy('product_id');

        $matches = true;
        $details = [];
        $mismatchedCount = 0;
        $unexpectedCount = 0;

        foreach ($movement->lines as $line) {
            $productId = (int) $line->product_id;
            $product = $line->product;
            $isSerialized = (bool) ($product?->serial_number_required ?? false);
            $countedQty = bcadd((string) $line->quantity, '0', 4);
            $serialCount = $line->serials->count();

            $status = 'MATCH';

            if (!$obligationsByProduct->has($productId)) {
                $status = 'UNEXPECTED';
                $unexpectedCount++;
                $matches = false;
            } elseif (bccomp($countedQty, '0', 4) <= 0) {
                if ($line->count_confirmed) {
                    $status = 'ZERO_QUANTITY';
                } else {
                    // Unconfirmed zero lines are simply not part of the batch; skip from output.
                    continue;
                }
            } elseif ($isSerialized) {
                // Serialized quantities must be exact non-negative integers (one serial per unit);
                // compare via decimal string equality rather than float/round, which could
                // silently accept a fractional quantity as if it matched an integer serial count.
                if (bccomp($countedQty, (string) $serialCount, 4) !== 0) {
                    $status = 'SERIAL_COUNT_MISMATCH';
                    $mismatchedCount++;
                    $matches = false;
                }
            }

            $details[$productId] = [
                'product_id'            => $productId,
                'product_name'          => (string) ($product?->product_name ?? ''),
                'product_code'          => (string) ($product?->product_code ?? ''),
                'is_serialized'         => $isSerialized,
                'counted_quantity'      => $countedQty,
                'counted_serial_count'  => $serialCount,
                'status'                => $status,
            ];
        }

        if (empty($details)) {
            $matches = false;
        }

        return [
            'matches' => $matches,
            'summary' => [
                'total_counted_products'    => count($details),
                'mismatched_products_count' => $mismatchedCount,
                'unexpected_products_count' => $unexpectedCount,
            ],
            'details' => $details,
        ];
    }
}
