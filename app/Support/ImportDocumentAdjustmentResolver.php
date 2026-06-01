<?php

namespace App\Support;

class ImportDocumentAdjustmentResolver
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function resolve(array $rows, string $field, string $label): float
    {
        $values = [];

        foreach ($rows as $row) {
            $value = $this->parseMoney($row[$field] ?? null);

            if ($value === null) {
                continue;
            }

            $key = number_format($value, 2, '.', '');
            $values[$key] = $value;
        }

        if (count($values) > 1) {
            throw new \RuntimeException("Repeated {$label} values do not match within the invoice group.");
        }

        return round(array_values($values)[0] ?? 0.0, 2);
    }

    private function parseMoney(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
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

        return round((float) $normalized, 2);
    }
}