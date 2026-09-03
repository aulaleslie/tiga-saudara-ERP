<?php

namespace App\Support;

class QuantityFormatter
{
    /**
     * Format a quantity, displaying decimal places only when the value is truly fractional.
     * Trims trailing zeros and keeps up to 3 decimal places when genuinely fractional.
     *
     * @param float|int|string $value
     * @param int $maxDecimals Maximum decimal places to preserve (default 3)
     * @return string Formatted quantity string
     */
    public static function formatCanonicalQuantity($value, int $maxDecimals = 3): string
    {
        $floatValue = (float) $value;

        // If the value is a whole number, return it without decimals
        if ((float) ((int) $floatValue) === $floatValue) {
            return (string) (int) $floatValue;
        }

        // Format with maximum decimals, then trim trailing zeros
        $formatted = number_format($floatValue, $maxDecimals, '.', '');
        $trimmed = rtrim($formatted, '0');
        $trimmed = rtrim($trimmed, '.');

        return $trimmed;
    }
}
