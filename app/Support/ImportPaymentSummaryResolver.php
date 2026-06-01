<?php

namespace App\Support;

use Modules\Setting\Entities\PaymentMethod;

class ImportPaymentSummaryResolver
{
    private const TOLERANCE = 0.01;

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{source_total:?float,outstanding_balance:float,paid_amount:float,needs_payment:bool}
     */
    public function resolve(array $rows, float $calculatedDocumentTotal): array
    {
        $calculatedDocumentTotal = round($calculatedDocumentTotal, 2);

        $preferredOutstandingValues = $this->collectDistinctMoneyValues($rows, function (array $row): ?float {
            $todayOutstanding = $this->parseMoney($row['sisa_tagihan_hari_ini'] ?? null);

            if ($todayOutstanding !== null) {
                return $todayOutstanding;
            }

            return $this->parseMoney($row['sisa_tagihan'] ?? null);
        });

        if (count($preferredOutstandingValues) > 1) {
            throw new \RuntimeException('Repeated payment fields do not match for preferred outstanding balance.');
        }

        $paymentValues = $this->collectDistinctMoneyValues($rows, function (array $row): ?float {
            return $this->parseMoney($row['pembayaran'] ?? null);
        });

        if (count($paymentValues) > 1) {
            throw new \RuntimeException('Repeated payment fields do not match for Pembayaran.');
        }

        $sourceTotalValues = $this->collectDistinctMoneyValues($rows, function (array $row): ?float {
            return $this->parseMoney($row['source_total'] ?? null);
        });

        if (count($sourceTotalValues) > 1) {
            throw new \RuntimeException('Repeated payment fields do not match for Total.');
        }

        $outstandingBalance = $preferredOutstandingValues[0] ?? 0.0;
        $explicitPaidAmount = $paymentValues[0] ?? null;
        $sourceTotal = $sourceTotalValues[0] ?? null;
        $paidAmount = $explicitPaidAmount ?? round($calculatedDocumentTotal - $outstandingBalance, 2);
        $paidAmount = round(max($paidAmount, 0), 2);

        if ($sourceTotal !== null && abs($sourceTotal - $calculatedDocumentTotal) > self::TOLERANCE) {
            throw new \RuntimeException('Payment total mismatch: source Total does not reconcile with calculated document total.');
        }

        if (abs(($paidAmount + $outstandingBalance) - $calculatedDocumentTotal) > self::TOLERANCE) {
            throw new \RuntimeException('Payment total mismatch: paid amount plus outstanding balance does not reconcile with calculated document total.');
        }

        return [
            'source_total' => $sourceTotal,
            'outstanding_balance' => $outstandingBalance,
            'paid_amount' => $paidAmount,
            'needs_payment' => $paidAmount > self::TOLERANCE,
        ];
    }

    public function resolveCashPaymentMethod(): ?PaymentMethod
    {
        $cashMethod = PaymentMethod::query()
            ->where('is_cash', true)
            ->first();

        if ($cashMethod) {
            return $cashMethod;
        }

        return PaymentMethod::query()
            ->whereRaw('LOWER(name) = ?', ['cash'])
            ->first();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): ?float  $resolver
     * @return array<int, float>
     */
    private function collectDistinctMoneyValues(array $rows, callable $resolver): array
    {
        $values = [];

        foreach ($rows as $row) {
            $value = $resolver($row);

            if ($value === null) {
                continue;
            }

            $key = number_format($value, 2, '.', '');
            $values[$key] = $value;
        }

        return array_values($values);
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

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($lastComma !== false) {
            $decimals = strlen($normalized) - $lastComma - 1;
            $normalized = $decimals > 0 && $decimals <= 2
                ? str_replace(',', '.', $normalized)
                : str_replace(',', '', $normalized);
        } elseif ($lastDot !== false) {
            $decimals = strlen($normalized) - $lastDot - 1;

            if (! ($decimals > 0 && $decimals <= 2)) {
                $normalized = str_replace('.', '', $normalized);
            }
        }

        if (! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }
}