<?php

namespace Modules\Purchase\Support;

class PurchaseCostHelper
{
    /**
     * Calculate the unit cost of a purchase line.
     *
     * @param float $subTotal
     * @param float $taxAmount
     * @param float $discountAmount
     * @param float $quantity
     * @return float
     */
    public static function calculateUnitCost(float $subTotal, float $taxAmount, float $discountAmount, float $quantity): float
    {
        if ($quantity <= 0) {
            return 0.0;
        }

        $dpp = $subTotal - $taxAmount;
        return $dpp / $quantity;
    }

    /**
     * Allocate a PurchaseDetail-level monetary total (e.g. sub_total or
     * product_tax_amount) across its individual receiving details,
     * proportionally by each receipt's share of the ORIGINAL PurchaseDetail
     * quantity, rounded to 2 decimals.
     *
     * Guarantees exact monetary conservation: the sum of returned allocated
     * values equals round($total, 2) exactly, by assigning any leftover
     * rounding remainder to the LAST receipt in $orderedReceiptQuantities
     * (the caller must pass receipts in a deterministic order — e.g. by
     * received_note_detail id ascending — so preview and execution always
     * agree on which line absorbs the remainder).
     *
     * This is the single source of truth for this allocation so preview
     * and execution can never drift apart under rounding.
     *
     * @param float $total The PurchaseDetail-level amount to allocate (e.g. sub_total).
     * @param float $originalQuantity The PurchaseDetail's original (pre-normalization) quantity.
     * @param array<int, array{key: mixed, quantity: float}> $orderedReceiptQuantities
     *        Each entry describes one receiving detail's quantity_received,
     *        keyed by an arbitrary caller-supplied identifier (e.g.
     *        received_note_detail id), in the deterministic order the
     *        remainder-bearing line should be picked from (last wins).
     * @return array<mixed, float> Map of the same keys to their allocated amount, rounded to 2 decimals.
     */
    public static function allocateProportionally(float $total, float $originalQuantity, array $orderedReceiptQuantities): array
    {
        $allocations = [];

        if ($originalQuantity <= 0 || empty($orderedReceiptQuantities)) {
            return $allocations;
        }

        $roundedTotal = round($total, 2);
        $runningSum = 0.0;
        $lastKey = null;

        foreach ($orderedReceiptQuantities as $entry) {
            $key = $entry['key'];
            $quantity = (float) $entry['quantity'];
            $ratio = $quantity / $originalQuantity;
            $allocated = round($total * $ratio, 2);

            $allocations[$key] = $allocated;
            $runningSum += $allocated;
            $lastKey = $key;
        }

        // Deterministically assign the rounding remainder to the last
        // receipt in the supplied order, so the allocated amounts sum to
        // exactly the rounded total.
        if ($lastKey !== null) {
            $remainder = round($roundedTotal - $runningSum, 2);
            if (abs($remainder) > 0.0000001) {
                $allocations[$lastKey] = round($allocations[$lastKey] + $remainder, 2);
            }
        }

        return $allocations;
    }
}
