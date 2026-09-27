<?php

namespace App\Support;

class RowTotalRoundingCalculator
{
    /**
     * Transient cart-option flag marking a row whose total was produced by an
     * eligible automatic pricing interaction during THIS request, and which the
     * backend must therefore reconstruct, tax, and round itself.
     *
     * Absent or false means the supplied total is an authoritative stored value
     * being carried through a load/save, which must be preserved verbatim.
     *
     * It is derived inside server-side cart operations only. It is never read
     * from request input, so a client cannot use it to force or suppress
     * repricing, nor to smuggle in a hand-picked row total.
     */
    public const RECALC_FLAG = 'pricing_recalculation_required';

    /**
     * Round a float or integer amount to the nearest increment (half-up) using minor units (cents / 2 decimal places).
     * If increment is 0, negative, or unconfigured, returns the original amount rounded to 2 decimal places.
     *
     * @param float|int $amount
     * @param float|int $increment
     * @return float
     */
    public static function round($amount, $increment): float
    {
        $amountFloat = (float) $amount;
        $incrementFloat = (float) $increment;

        if ($incrementFloat <= 0) {
            return round($amountFloat, 2);
        }

        // Convert to minor units (integer cents) to prevent binary floating point errors
        $amountMinor = (int) round($amountFloat * 100);
        $incrementMinor = (int) round($incrementFloat * 100);

        if ($incrementMinor <= 0) {
            return round($amountFloat, 2);
        }

        $remainder = $amountMinor % $incrementMinor;
        if ($remainder < 0) {
            $remainder += $incrementMinor;
        }

        $quotient = (int) floor($amountMinor / $incrementMinor);

        // Half-up check: if remainder * 2 >= incrementMinor, round up to next multiple
        if ($remainder * 2 >= $incrementMinor) {
            $roundedMinor = ($quotient + 1) * $incrementMinor;
        } else {
            $roundedMinor = $quotient * $incrementMinor;
        }

        return $roundedMinor / 100.0;
    }
}
