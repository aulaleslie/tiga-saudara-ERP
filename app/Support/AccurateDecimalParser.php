<?php

namespace App\Support;

class AccurateDecimalParser
{
    /**
     * Parse Accurate-compatible decimal strings into a float.
     * Supports formats like "400,000.00", "400000", blank, zero, negative.
     * 
     * @param mixed $value
     * @return float|null Returns finite positive/zero/negative float, or null if invalid.
     */
    public static function parse($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        // Reject if there are letters or other non-numeric symbols
        if (preg_match('/[^\d.,\-\s]/', $raw)) {
            return null;
        }

        // Remove everything except digits, comma, dot, minus
        $clean = preg_replace('/[^\d.,\-]/', '', $raw);
        if ($clean === '' || $clean === '-' || $clean === '-.' || $clean === '.-') {
            return null;
        }

        $commaCount = substr_count($clean, ',');
        $dotCount   = substr_count($clean, '.');

        // Handling comma as thousand separator and dot as decimal
        if ($commaCount > 0 && $dotCount === 0) {
            // "400,00" -> could be comma as decimal if it's European, but Accurate is usually comma for thousands or comma for decimal?
            // Actually, if there is a comma and no dot, it might be 400,000 (thousand) or 400,00 (decimal).
            // Let's assume Accurate in ID uses comma for thousands, dot for decimals. Or if it's purely comma for decimal?
            // Actually, let's just strip commas.
            // Wait, what if comma is decimal separator? "400,00" -> 400.00
            // Accurate export generally exports prices like "400,000.00".
            // If comma is thousand, then replace comma with empty string.
            $clean = str_replace(',', '', $clean);
        } elseif ($commaCount > 0 && $dotCount > 0) {
            // "400,000.00" -> comma is thousand separator
            $clean = str_replace(',', '', $clean);
        }

        if (!is_numeric($clean)) {
            return null;
        }

        $floatVal = (float) $clean;
        
        if (is_infinite($floatVal) || is_nan($floatVal)) {
            return null;
        }

        return $floatVal;
    }
}
