<?php

namespace App\Support;

class ImportDocumentAdjustmentResolver
{
    /**
     * Resolve a repeated document-level amount (e.g. Diskon, Biaya Pengiriman) at full source
     * precision. The value is intentionally NOT rounded to two decimals: a source discount like
     * 747298.755 must flow unrounded into reconciliation/allocation so the final document total
     * rounds to the same value as the rounded source Total. Persisted columns may still round.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function resolve(array $rows, string $field, string $label): float
    {
        $values = [];

        foreach ($rows as $row) {
            $value = $this->parseMoney($row[$field] ?? null, false);

            if ($value === null) {
                continue;
            }

            // Dedup at two decimals so trivially-rounded repeats of the same source value match,
            // but keep the full-precision value for reconciliation.
            $key = number_format($value, 2, '.', '');
            $values[$key] = $value;
        }

        if (count($values) > 1) {
            throw new \RuntimeException("Repeated {$label} values do not match within the invoice group.");
        }

        return array_values($values)[0] ?? 0.0;
    }

    private function parseMoney(mixed $value, bool $round = true): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $round ? round((float) $value, 2) : (float) $value;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        // Some exports emit very small values in scientific notation (e.g. "1.0e-06"), which are
        // effectively zero. Parse these directly before stripping characters, otherwise the "e"
        // would be removed and the value misread (e.g. "1.0-06" -> non-numeric -> null).
        if (preg_match('/^[+-]?\d*\.?\d+[eE][+-]?\d+$/', $normalized) === 1) {
            return $round ? round((float) $normalized, 2) : (float) $normalized;
        }

        $normalized = preg_replace('/[^0-9,.-]/', '', $normalized) ?? '';
        if ($normalized === '' || $normalized === '-' || $normalized === ',' || $normalized === '.') {
            return null;
        }

        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');
        $commaCount = substr_count($normalized, ',');
        $dotCount = substr_count($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($lastComma !== false) {
            $normalized = $commaCount === 1
                ? str_replace(',', '.', $normalized)
                : str_replace(',', '', $normalized);
        } elseif ($lastDot !== false && $dotCount !== 1) {
            $normalized = str_replace('.', '', $normalized);
        }

        if (! is_numeric($normalized)) {
            return null;
        }

        return $round ? round((float) $normalized, 2) : (float) $normalized;
    }
}