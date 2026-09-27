<?php

namespace App\Services\Reports;

class CrossBusinessQuantityPresenter
{
    public const MODE_DECIMAL = 'decimal';
    public const MODE_CONVERSION = 'conversion';

    /**
     * Format a quantity based on the requested mode and unit metadata.
     *
     * In decimal mode:
     * - Rounds to 2 decimal places.
     * - Uses comma (,) as decimal separator.
     * - If the value after rounding is whole (e.g. 10.00), returns integer string ("10") without ",00".
     * - If fractional (e.g. 10.5 or 10.126), returns 2 decimal places with comma ("10,50", "10,13").
     *
     * In conversion mode:
     * - If a valid largest conversion exists (factor > 0):
     *     whole converted units = floor(quantity / factor)
     *     base remainder = quantity - (whole converted units * factor)
     *     formatted as: "{converted_units} {conversion_unit_name} {remainder} {base_unit_name}"
     *     (Each quantity part formatted using decimal rule, but if whole converted units > 0 and remainder == 0:
     *      ProductDataTable convention: "{convertedQuantity} {unit} {remainder} {baseUnit}" e.g. "1 Karton 0 Pcs" or if base remainder exists "1 Karton 6 Pcs", "1 Karton 6,50 Pcs")
     * - If no conversion exists:
     *     If base unit exists: "{formatted_qty} {base_unit_name}"
     *     If no base unit exists: "{formatted_qty}"
     *
     * @param float|int|string|null $quantity
     * @param string $mode 'decimal' | 'conversion'
     * @param string|null $baseUnitName
     * @param string|null $conversionUnitName
     * @param float|int|string|null $conversionFactor
     * @return string
     */
    public static function format(
        $quantity,
        string $mode = self::MODE_DECIMAL,
        ?string $baseUnitName = null,
        ?string $conversionUnitName = null,
        $conversionFactor = null
    ): string {
        $qty = (float) ($quantity ?? 0.0);

        if ($mode === self::MODE_CONVERSION) {
            return self::formatConversion($qty, $baseUnitName, $conversionUnitName, $conversionFactor);
        }

        return self::formatDecimal($qty);
    }

    /**
     * Format bare numeric quantity with comma decimal separator and whole-number trimming.
     */
    public static function formatDecimal(float $val): string
    {
        // Round to 2 decimal places to avoid floating point anomalies like 10.000000001 or 9.999999999
        $rounded = round($val, 2);

        // Check if mathematically whole
        if (abs($rounded - round($rounded)) < 0.000001) {
            return (string) (int) round($rounded);
        }

        // Fractional: format with 2 decimals, comma separator
        return number_format($rounded, 2, ',', '');
    }

    /**
     * Format according to largest-conversion-plus-base-remainder convention.
     */
    private static function formatConversion(
        float $qty,
        ?string $baseUnitName,
        ?string $conversionUnitName,
        $conversionFactor
    ): string {
        $factor = (float) ($conversionFactor ?? 0.0);

        // Only use conversion if factor > 0 and conversion unit name is present
        if ($factor > 0 && !empty($conversionUnitName)) {
            // Decompose the magnitude, then reapply the sign to each non-zero part, so a
            // negative balance never reads as the mathematically wrong "-1 Box 11 Pcs" for
            // -1 base unit, and never shows a meaningless "-0 Box" denomination.
            $isNegative = $qty < 0;
            $magnitude = abs($qty);

            $convertedUnits = (int) floor($magnitude / $factor);
            $remainder = $magnitude - ($convertedUnits * $factor);
            $baseUnit = $baseUnitName ?? '';

            if ($isNegative && $convertedUnits === 0) {
                // No whole converted unit: show only the signed base-unit remainder.
                $formattedRemainder = self::formatDecimal(-$remainder);

                return trim("{$formattedRemainder} {$baseUnit}");
            }

            $formattedConverted = self::formatDecimal($isNegative ? -$convertedUnits : $convertedUnits);
            $formattedRemainder = self::formatDecimal(
                $isNegative && $remainder > 0.000001 ? -$remainder : $remainder
            );

            // Matching ProductDataTable convention:
            // "{$convertedQuantity} {$biggestConversion->unit->short_name} {$remainder} {$baseUnit->short_name}"
            return trim("{$formattedConverted} {$conversionUnitName} {$formattedRemainder} {$baseUnit}");
        }

        $formatted = self::formatDecimal($qty);

        if (!empty($baseUnitName)) {
            return "{$formatted} {$baseUnitName}";
        }

        return $formatted;
    }
}
