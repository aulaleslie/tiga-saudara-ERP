<?php

namespace Modules\Sale\Support;

class BackfillCostCalculator
{
    /**
     * Calculate the net Distributed Purchase Price (DPP) for a line item.
     * Subtracts tax and discount from the sub-total.
     */
    public static function calculatePurchaseDpp(float $subTotal, float $taxAmount, float $discountAmount): float
    {
        return $subTotal - $taxAmount - $discountAmount;
    }

    /**
     * Prorate the total line cost based on receipt quantity versus ordered quantity.
     */
    public static function calculateProratedReceiptCost(float $orderedQty, float $receiptQty, float $lineDpp): float
    {
        if ($orderedQty <= 0) {
            return 0.0;
        }

        return $lineDpp * ($receiptQty / $orderedQty);
    }

    /**
     * Apply a purchase (additive) event to the running average state.
     * Reseeds the average if current quantity is <= 0.
     * 
     * @return array{runningQty: float, runningValue: float, currentAverage: float}
     */
    public static function applyPurchase(float $eventQty, float $eventCost, float $runningQty, float $runningValue, float $currentAverage): array
    {
        if ($runningQty <= 0) {
            // Reseed average from this purchase and clear poisoned value
            $currentAverage = $eventQty > 0 ? $eventCost / $eventQty : 0;
            $runningQty += $eventQty;
            $runningValue = $runningQty > 0 ? $runningQty * $currentAverage : 0;
        } else {
            $runningQty += $eventQty;
            $runningValue += $eventCost;
            if ($runningQty > 0) {
                $currentAverage = $runningValue / $runningQty;
            }
        }

        return [
            'runningQty' => $runningQty,
            'runningValue' => $runningValue,
            'currentAverage' => $currentAverage,
        ];
    }

    /**
     * Apply a consumption (subtractive) event like a sale or purchase return to the running average state.
     * Consumes value at the current average cost.
     * 
     * @return array{runningQty: float, runningValue: float, currentAverage: float}
     */
    public static function applyConsumption(float $eventQty, float $runningQty, float $runningValue, float $currentAverage): array
    {
        $runningValue -= $currentAverage * $eventQty;
        $runningQty -= $eventQty;
        
        if ($runningQty > 0) {
            $currentAverage = $runningValue / $runningQty;
        } else if ($runningQty <= 0) {
            $runningValue = 0;
            // Retain $currentAverage for subsequent negative-stock sales
        }

        return [
            'runningQty' => $runningQty,
            'runningValue' => $runningValue,
            'currentAverage' => $currentAverage,
        ];
    }
}
