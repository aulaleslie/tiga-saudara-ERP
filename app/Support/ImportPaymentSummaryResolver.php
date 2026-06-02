<?php

namespace App\Support;

use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\PaymentMethod;

class ImportPaymentSummaryResolver
{
    private const TOLERANCE = 0.01;

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{source_total:?float,outstanding_balance:float,paid_amount:float,deduction_amount:float,needs_payment:bool}
     */
    public function resolve(array $rows, float $calculatedDocumentTotal): array
    {
        $calculatedDocumentTotal = round($calculatedDocumentTotal, 2);

        $todayOutstandingValues = $this->collectDistinctMoneyValues($rows, function (array $row): ?float {
            return $this->parseMoney($row['sisa_tagihan_hari_ini'] ?? null);
        });

        if (count($todayOutstandingValues) > 1) {
            throw new \RuntimeException('Repeated payment fields do not match for Sisa Tagihan Hari Ini.');
        }

        $sisaTagihanValues = $this->collectDistinctMoneyValues($rows, function (array $row): ?float {
            return $this->parseMoney($row['sisa_tagihan'] ?? null);
        });

        if (count($sisaTagihanValues) > 1) {
            throw new \RuntimeException('Repeated payment fields do not match for Sisa Tagihan.');
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

        $deductionValues = $this->collectDistinctMoneyValues($rows, function (array $row): ?float {
            return $this->parseMoney($row['jumlah_pemotongan'] ?? null);
        });

        if (count($deductionValues) > 1) {
            throw new \RuntimeException('Repeated payment fields do not match for Jumlah Pemotongan.');
        }

        $todayOutstanding = $todayOutstandingValues[0] ?? null;
        $sisaTagihan = $sisaTagihanValues[0] ?? null;
        $explicitPaidAmount = $paymentValues[0] ?? null;
        $sourceTotal = $sourceTotalValues[0] ?? null;
        // Jumlah Pemotongan is a non-cash settlement reduction/credit recorded separately from
        // the cash Pembayaran. It settles part of the invoice without cash changing hands.
        $deductionAmount = round(max($deductionValues[0] ?? 0.0, 0.0), 2);

        // Prefer Sisa Tagihan Hari Ini, falling back to Sisa Tagihan. When an
        // explicit Pembayaran is present, choose whichever candidate makes
        // paid + outstanding reconcile with the document total (preferring
        // today's outstanding on a tie). This handles old unpaid invoices that
        // were later settled, where Sisa Tagihan Hari Ini is 0 but Sisa Tagihan
        // still carries the original balance.
        $outstandingBalance = $todayOutstanding ?? $sisaTagihan ?? 0.0;

        // The deduction settles part of the invoice alongside cash, so reconciliation is
        // cash Pembayaran + Jumlah Pemotongan + outstanding == document total.
        if ($explicitPaidAmount !== null && $todayOutstanding !== null && $sisaTagihan !== null) {
            $todayReconciles = abs(($explicitPaidAmount + $deductionAmount + $todayOutstanding) - $calculatedDocumentTotal) <= self::TOLERANCE;
            $sisaReconciles = abs(($explicitPaidAmount + $deductionAmount + $sisaTagihan) - $calculatedDocumentTotal) <= self::TOLERANCE;

            if (! $todayReconciles && $sisaReconciles) {
                $outstandingBalance = $sisaTagihan;
            }
        }

        // Cash Pembayaran: explicit when present, otherwise the residual after deduction/outstanding.
        $paidAmount = $explicitPaidAmount ?? round($calculatedDocumentTotal - $deductionAmount - $outstandingBalance, 2);
        $paidAmount = round(max($paidAmount, 0), 2);

        if ($sourceTotal !== null && abs($sourceTotal - $calculatedDocumentTotal) > self::TOLERANCE) {
            throw new \RuntimeException('Payment total mismatch: source Total does not reconcile with calculated document total.');
        }

        if (abs(($paidAmount + $deductionAmount + $outstandingBalance) - $calculatedDocumentTotal) > self::TOLERANCE) {
            throw new \RuntimeException('Payment total mismatch: paid amount plus deduction and outstanding balance does not reconcile with calculated document total.');
        }

        return [
            'source_total' => $sourceTotal,
            'outstanding_balance' => $outstandingBalance,
            'paid_amount' => $paidAmount,
            'deduction_amount' => $deductionAmount,
            'needs_payment' => $paidAmount > self::TOLERANCE,
        ];
    }

    /**
     * Payment method name for the non-cash settlement credit (Jumlah Pemotongan).
     */
    public const DEDUCTION_METHOD_NAME = 'POTONGAN';

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
     * Resolve the non-cash payment method used to record a Jumlah Pemotongan settlement credit.
     *
     * Purchase and sales reports derive "paid" from active payment rows, so the deduction must be
     * persisted as its own active payment row to avoid being reported as outstanding. A dedicated
     * non-cash method keeps the credit distinguishable from cash in payment-method breakdowns.
     */
    public function resolveDeductionPaymentMethod(): PaymentMethod
    {
        $existing = PaymentMethod::query()
            ->whereRaw('LOWER(name) = ?', [strtolower(self::DEDUCTION_METHOD_NAME)])
            ->first();

        if ($existing) {
            return $existing;
        }

        // payment_methods.coa_id is required; reuse an existing account rather than inventing one.
        // Prefer the cash method's account, falling back to any chart of account.
        $coaId = $this->resolveCashPaymentMethod()?->coa_id
            ?? ChartOfAccount::query()->value('id');

        if ($coaId === null) {
            throw new \RuntimeException('A chart of account is required to record a Jumlah Pemotongan credit.');
        }

        return PaymentMethod::create([
            'name' => self::DEDUCTION_METHOD_NAME,
            'coa_id' => $coaId,
            'is_cash' => false,
        ]);
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
        } elseif ($lastDot !== false) {
            if ($dotCount !== 1) {
                $normalized = str_replace('.', '', $normalized);
            }
        }

        if (! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }
}