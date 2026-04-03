<?php

namespace Modules\Product\Support;

class ProductConversionPriceNormalizer
{
    public const PREFIX = 'RP ';
    public const THOUSANDS = '.';
    public const DECIMAL = ',';
    public const PRECISION = 2;

    public static function normalizeConversions(array $conversions): array
    {
        return array_map(function ($conversion) {
            if (! is_array($conversion)) {
                return $conversion;
            }

            if (array_key_exists('price', $conversion)) {
                $conversion['price'] = self::normalizePrice($conversion['price']);
            }

            return $conversion;
        }, $conversions);
    }

    public static function normalizePrice(mixed $value): mixed
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value) || is_float($value)) {
            return self::formatCanonical((float) $value);
        }

        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        $withoutPrefix = preg_replace('/^RP\s*/i', '', $text) ?? $text;
        $collapsed = preg_replace('/\s+/', '', $withoutPrefix) ?? $withoutPrefix;

        if ($collapsed === '') {
            return '';
        }

        $sanitized = preg_replace('/[^0-9,.\-]/', '', $collapsed) ?? '';
        if ($sanitized === '') {
            return $text;
        }

        $lastComma = strrpos($sanitized, ',');
        $lastDot = strrpos($sanitized, '.');
        $decimalSeparator = null;

        if ($lastComma !== false && $lastDot !== false) {
            $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
        } elseif ($lastComma !== false) {
            $decimalSeparator = ',';
        } elseif ($lastDot !== false) {
            $dotCount = substr_count($sanitized, '.');
            $fractionalDigits = strlen(preg_replace('/\D/', '', substr($sanitized, $lastDot + 1)) ?? '');

            if ($dotCount === 1 && $fractionalDigits > 0 && $fractionalDigits <= 2) {
                $decimalSeparator = '.';
            }
        }

        $normalized = $sanitized;
        if ($decimalSeparator === ',') {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif ($decimalSeparator === '.') {
            $normalized = str_replace(',', '', $normalized);
        } else {
            $normalized = str_replace([',', '.'], '', $normalized);
        }

        if (! is_numeric($normalized)) {
            return $text;
        }

        return self::formatCanonical((float) $normalized);
    }

    public static function isCanonicalNumeric(mixed $value): bool
    {
        return is_string($value) && $value !== '' && is_numeric($value);
    }

    public static function formatDisplay(mixed $value): string
    {
        $canonical = self::normalizePrice($value);

        if (! self::isCanonicalNumeric($canonical)) {
            return (string) $canonical;
        }

        return self::PREFIX.number_format((float) $canonical, self::PRECISION, self::DECIMAL, self::THOUSANDS);
    }

    public static function formatCanonical(float $value): string
    {
        $rounded = round($value, 2);

        if ((float) ((int) $rounded) === $rounded) {
            return (string) (int) $rounded;
        }

        return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
    }
}
