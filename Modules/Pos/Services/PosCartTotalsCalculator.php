<?php

namespace Modules\Pos\Services;

class PosCartTotalsCalculator
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array{type?: string, value?: float|int|string|null}  $billDiscount
     * @return array{
     *     lines: array<int, array<string, mixed>>,
     *     totals: array{
     *         subtotal: float,
     *         line_discount_total: float,
     *         bill_discount_total: float,
     *         discount_total: float,
     *         tax_total: float,
     *         grand_total: float
     *     }
     * }
     */
    public function calculate(array $lines, array $billDiscount, bool $isPkp): array
    {
        $normalizedLines = array_map(function (array $line): array {
            $lineId = (int) ($line['line_id'] ?? 0);
            $qty = max(0, (int) ($line['qty'] ?? 0));
            $unitPriceCents = $this->toMinor((float) ($line['unit_price'] ?? 0));
            $lineGrossCents = $qty * $unitPriceCents;

            $discountType = $this->normalizeDiscountType((string) ($line['line_discount_type'] ?? 'fixed'));
            $discountValue = (float) ($line['line_discount_value'] ?? 0);
            $lineDiscountCents = $this->lineDiscountCents($discountType, $discountValue, $lineGrossCents);
            $lineNetBeforeBillCents = max(0, $lineGrossCents - $lineDiscountCents);

            return array_merge($line, [
                'line_id' => $lineId,
                'qty' => $qty,
                'unit_price' => $this->fromMinor($unitPriceCents),
                'line_discount_type' => $discountType,
                'line_discount_value' => $discountValue,
                '_line_gross_minor' => $lineGrossCents,
                '_line_discount_minor' => $lineDiscountCents,
                '_line_net_before_bill_minor' => $lineNetBeforeBillCents,
            ]);
        }, $lines);

        usort($normalizedLines, function (array $left, array $right): int {
            return (int) $left['line_id'] <=> (int) $right['line_id'];
        });

        $billDiscountType = $this->normalizeDiscountType((string) ($billDiscount['type'] ?? 'fixed'));
        $billDiscountValue = (float) ($billDiscount['value'] ?? 0);
        $billDiscountBaseCents = array_sum(array_map(
            fn (array $line): int => (int) $line['_line_net_before_bill_minor'],
            $normalizedLines
        ));

        $billDiscountCents = $this->billDiscountCents(
            $billDiscountType,
            $billDiscountValue,
            $billDiscountBaseCents
        );

        $allocatedBillDiscounts = $this->prorateBillDiscount($normalizedLines, $billDiscountCents, $billDiscountBaseCents);

        $lineDiscountTotalCents = 0;
        $billDiscountTotalCents = 0;
        $subtotalCents = 0;
        $taxTotalCents = 0;
        $resultLines = [];

        foreach ($normalizedLines as $line) {
            $lineId = (int) $line['line_id'];
            $lineGrossCents = (int) $line['_line_gross_minor'];
            $lineDiscountCents = (int) $line['_line_discount_minor'];
            $lineNetBeforeBillCents = (int) $line['_line_net_before_bill_minor'];
            $billShareCents = (int) ($allocatedBillDiscounts[$lineId] ?? 0);
            $lineSubtotalCents = max(0, $lineNetBeforeBillCents - $billShareCents);
            $lineTaxCents = $this->lineTaxCents(
                $lineSubtotalCents,
                $line['tax_id'] ?? null,
                (float) ($line['tax_rate'] ?? 0),
                $isPkp
            );
            $lineTotalCents = $lineSubtotalCents;

            $lineDiscountTotalCents += $lineDiscountCents;
            $billDiscountTotalCents += $billShareCents;
            $subtotalCents += $lineSubtotalCents;
            $taxTotalCents += $lineTaxCents;

            $resultLines[] = array_merge($line, [
                'line_gross' => $this->fromMinor($lineGrossCents),
                'line_discount_amount' => $this->fromMinor($lineDiscountCents),
                'line_net_before_bill' => $this->fromMinor($lineNetBeforeBillCents),
                'bill_discount_amount' => $this->fromMinor($billShareCents),
                'line_subtotal' => $this->fromMinor($lineSubtotalCents),
                'line_tax_total' => $this->fromMinor($lineTaxCents),
                'line_total' => $this->fromMinor($lineTotalCents),
            ]);
        }

        $discountTotalCents = $lineDiscountTotalCents + $billDiscountTotalCents;
        $grandTotalCents = $subtotalCents;

        $resultLines = array_map(function (array $line): array {
            unset(
                $line['_line_gross_minor'],
                $line['_line_discount_minor'],
                $line['_line_net_before_bill_minor']
            );

            return $line;
        }, $resultLines);

        return [
            'lines' => $resultLines,
            'totals' => [
                'subtotal' => $this->fromMinor($subtotalCents),
                'line_discount_total' => $this->fromMinor($lineDiscountTotalCents),
                'bill_discount_total' => $this->fromMinor($billDiscountTotalCents),
                'discount_total' => $this->fromMinor($discountTotalCents),
                'tax_total' => $this->fromMinor($taxTotalCents),
                'grand_total' => $this->fromMinor($grandTotalCents),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, int>
     */
    private function prorateBillDiscount(array $lines, int $billDiscountCents, int $baseCents): array
    {
        $allocations = [];

        if ($billDiscountCents <= 0 || $baseCents <= 0 || $lines === []) {
            foreach ($lines as $line) {
                $allocations[(int) $line['line_id']] = 0;
            }

            return $allocations;
        }

        $fractionalRows = [];
        $allocated = 0;

        foreach ($lines as $line) {
            $lineId = (int) $line['line_id'];
            $lineBase = max(0, (int) $line['_line_net_before_bill_minor']);
            $numerator = $lineBase * $billDiscountCents;
            $floorShare = intdiv($numerator, $baseCents);
            $remainder = $numerator % $baseCents;

            $allocations[$lineId] = $floorShare;
            $allocated += $floorShare;

            $fractionalRows[] = [
                'line_id' => $lineId,
                'remainder' => $remainder,
            ];
        }

        $remaining = max(0, $billDiscountCents - $allocated);

        usort($fractionalRows, function (array $left, array $right): int {
            if ((int) $left['remainder'] === (int) $right['remainder']) {
                return (int) $left['line_id'] <=> (int) $right['line_id'];
            }

            return (int) $right['remainder'] <=> (int) $left['remainder'];
        });

        for ($index = 0; $index < $remaining; $index++) {
            $row = $fractionalRows[$index % count($fractionalRows)];
            $allocations[(int) $row['line_id']]++;
        }

        return $allocations;
    }

    private function normalizeDiscountType(string $type): string
    {
        return strtolower($type) === 'percentage' ? 'percentage' : 'fixed';
    }

    private function lineDiscountCents(string $type, float $value, int $lineGrossCents): int
    {
        if ($lineGrossCents <= 0) {
            return 0;
        }

        if ($type === 'percentage') {
            $percentage = max(0.0, min(100.0, $value));

            return min(
                $lineGrossCents,
                (int) round(($lineGrossCents * $percentage) / 100, 0, PHP_ROUND_HALF_UP)
            );
        }

        $fixedCents = max(0, $this->toMinor($value));

        return min($lineGrossCents, $fixedCents);
    }

    private function billDiscountCents(string $type, float $value, int $baseCents): int
    {
        if ($baseCents <= 0) {
            return 0;
        }

        if ($type === 'percentage') {
            $percentage = max(0.0, min(100.0, $value));

            return min(
                $baseCents,
                (int) round(($baseCents * $percentage) / 100, 0, PHP_ROUND_HALF_UP)
            );
        }

        return min($baseCents, max(0, $this->toMinor($value)));
    }

    private function lineTaxCents(int $lineSubtotalCents, mixed $taxId, float $taxRate, bool $isPkp): int
    {
        if (! $isPkp || $lineSubtotalCents <= 0) {
            return 0;
        }

        $taxIdValue = (int) ($taxId ?? 0);
        $rateBasisPoints = (int) round(max(0.0, $taxRate) * 100, 0, PHP_ROUND_HALF_UP);

        if ($taxIdValue <= 0 || $rateBasisPoints <= 0) {
            return 0;
        }

        // POS prices are treated as gross; extract tax with whole-rupiah rounding.
        $grossAmount = $lineSubtotalCents / 100;
        $taxAmount = (int) round(
            ($grossAmount * $rateBasisPoints) / (10000 + $rateBasisPoints),
            0,
            PHP_ROUND_HALF_UP
        );

        return $taxAmount * 100;
    }

    private function toMinor(float $value): int
    {
        return (int) round($value * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function fromMinor(int $value): float
    {
        return round($value / 100, 2);
    }
}
