<?php

namespace App\Support;

class HppNumericParser
{
    /**
     * Parse a quantity from a CSV string. Supports negative values.
     * Often used for `Mutasi` where sales are negative quantities.
     */
    public function parseQuantity(mixed $value): float
    {
        return (float) $this->parseNumeric($value);
    }

    /**
     * Parse an HPP / average price from a CSV string.
     */
    public function parseHpp(mixed $value): ?float
    {
        return $this->parseNumeric($value);
    }

    protected function parseNumeric(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        // Remove currency symbols if any
        $normalized = str_replace(['Rp', 'IDR', '$', ' '], '', $normalized);

        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                // e.g. 1.000,50 -> comma is decimal
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                // e.g. 1,000.50 -> dot is decimal
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($lastComma !== false) {
            // Only comma present.
            // If it ends with exactly 3 digits, e.g., 1,000, we assume it's a thousand separator if it looks like one.
            if (preg_match('/^[-+]?[0-9]{1,3}(,[0-9]{3})+$/', $normalized)) {
                $normalized = str_replace(',', '', $normalized);
            } else {
                $normalized = str_replace(',', '.', $normalized);
            }
        } elseif ($lastDot !== false) {
            // Only dot present.
            if (preg_match('/^[-+]?[0-9]{1,3}(\.[0-9]{3})+$/', $normalized)) {
                $normalized = str_replace('.', '', $normalized);
            }
        }

        return is_numeric($normalized) ? (float) $normalized : null;
    }

}
