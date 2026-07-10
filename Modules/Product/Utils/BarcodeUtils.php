<?php

namespace Modules\Product\Utils;

class BarcodeUtils
{
    /**
     * Canonicalizes a barcode for unique identity checks.
     * Removes surrounding whitespace and normalizes case to ensure
     * consistent unique constraints across MySQL (usually case-insensitive)
     * and SQLite (usually case-sensitive).
     *
     * @param string|null $barcode
     * @return string|null
     */
    public static function canonicalize(?string $barcode): ?string
    {
        if ($barcode === null) {
            return null;
        }

        $trimmed = trim($barcode);
        
        if ($trimmed === '') {
            return null;
        }

        // Trim whitespace and convert to lowercase for deterministic identity
        return mb_strtolower($trimmed, 'UTF-8');
    }
}
