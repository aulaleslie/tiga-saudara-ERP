<?php

namespace Modules\Product\Services;

use Modules\Setting\Entities\Setting;

class PriceFeedDecimalNormalizer
{
    /**
     * Normalize monetary float/string/int values to floats rounded to 2 decimal places.
     */
    public static function normalize(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * Compare two monetary values for strict equality after normalization.
     */
    public static function equals(?float $a, ?float $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }

        if ($a === null || $b === null) {
            return false;
        }

        return self::normalize($a) === self::normalize($b);
    }
}
