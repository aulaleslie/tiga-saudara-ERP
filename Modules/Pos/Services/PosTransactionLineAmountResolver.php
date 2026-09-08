<?php

namespace Modules\Pos\Services;

use Modules\Pos\Entities\PosTransactionLine;

class PosTransactionLineAmountResolver
{
    /**
     * Resolve the monetary amounts for a transaction line.
     *
     * Precedence:
     * 1. Canonical override metadata: line_gross_minor, line_discount_minor, line_net_minor
     * 2. Authoritative rounded net metadata: line_total_minor accompanied by line_gross_minor / line_discount_minor
     * 3. Authoritative rounded net alone: line_total_minor (line_gross = line_total_minor, line_discount = 0 or from discount_value)
     * 4. PACKED line metadata: packed_raw_total_minor / line_total
     * 5. Legacy line_total: minor units if PACKED or row override; Rupiah otherwise
     * 6. Standard calculation: qty * unit_price less line_discount
     *
     * @param  PosTransactionLine|array<string, mixed>  $line
     * @return array{
     *     gross: float,
     *     discount: float,
     *     net_before_bill: float,
     *     bill_discount: float,
     *     charged_total: float,
     *     rounding_adjustment: float
     * }
     */
    public static function resolve(PosTransactionLine|array $line): array
    {
        $lineMeta = is_array($line) ? ($line['line_meta'] ?? $line) : ($line->line_meta ?? []);
        $qty = (float) (is_array($line) ? ($line['qty'] ?? 0) : $line->qty);
        $unitPrice = (float) (is_array($line) ? ($line['unit_price'] ?? 0) : $line->unit_price);
        $discountType = (string) (is_array($line) ? ($line['line_discount_type'] ?? 'fixed') : ($line->line_discount_type ?? 'fixed'));
        $discountValue = (float) (is_array($line) ? ($line['line_discount_value'] ?? 0) : ($line->line_discount_value ?? 0));
        $priceSource = (string) ($lineMeta['price_source'] ?? (is_array($line) ? ($line['price_source'] ?? 'BASE') : 'BASE'));

        $canonicalGrossMinor = $lineMeta['line_gross_minor'] ?? null;
        $canonicalDiscountMinor = $lineMeta['line_discount_minor'] ?? null;
        $canonicalNetMinor = $lineMeta['line_net_minor'] ?? null;

        if ($canonicalNetMinor !== null && $canonicalGrossMinor !== null) {
            // Case 1: Canonical override metadata
            $lineGross = (float) $canonicalGrossMinor / 100;
            $lineDiscount = (float) ($canonicalDiscountMinor ?? 0) / 100;
            $lineNetBeforeBill = (float) $canonicalNetMinor / 100;
        } elseif (isset($lineMeta['line_total_minor']) && isset($lineMeta['line_gross_minor'])) {
            // Case 2: Authoritative rounded net with captured gross and discount
            $lineNetBeforeBill = (float) $lineMeta['line_total_minor'] / 100;
            $lineGross = (float) $lineMeta['line_gross_minor'] / 100;
            $lineDiscount = isset($lineMeta['line_discount_minor'])
                ? (float) $lineMeta['line_discount_minor'] / 100
                : self::calculateDiscountAmount($lineGross, $discountType, $discountValue);
        } elseif (isset($lineMeta['line_total_minor'])) {
            // Case 3: Authoritative net alone
            $lineNetBeforeBill = (float) $lineMeta['line_total_minor'] / 100;
            $rawGross = $qty * $unitPrice;
            $lineDiscount = self::calculateDiscountAmount($rawGross, $discountType, $discountValue);
            $lineGross = $rawGross > 0 ? $rawGross : $lineNetBeforeBill + $lineDiscount;
        } elseif ($priceSource === 'PACKED' && isset($lineMeta['line_total_minor'])) {
            $lineNetBeforeBill = (float) $lineMeta['line_total_minor'] / 100;
            $lineGross = $lineNetBeforeBill;
            $lineDiscount = 0.0;
        } elseif ($priceSource === 'PACKED' && isset($lineMeta['line_total'])) {
            $lineGross = (float) $lineMeta['line_total'] / 100;
            $lineDiscount = self::calculateDiscountAmount($lineGross, $discountType, $discountValue);
            $lineNetBeforeBill = max(0.0, $lineGross - $lineDiscount);
        } elseif (isset($lineMeta['line_total'])) {
            $isRowOverride = $priceSource === 'LINE_TOTAL_OVERRIDE' || $priceSource === 'LINE_UNIT_PRICE_OVERRIDE';
            if ($isRowOverride) {
                $lineNetBeforeBill = (float) $lineMeta['line_total'] / 100;
                $rawGross = $qty * $unitPrice;
                $lineDiscount = self::calculateDiscountAmount($rawGross, $discountType, $discountValue);
                $lineGross = $rawGross > 0 ? $rawGross : $lineNetBeforeBill + $lineDiscount;
            } else {
                $lineGross = (float) $lineMeta['line_total'];
                $lineDiscount = self::calculateDiscountAmount($lineGross, $discountType, $discountValue);
                $lineNetBeforeBill = max(0.0, $lineGross - $lineDiscount);
            }
        } else {
            // Standard fallback
            $lineGross = $qty * $unitPrice;
            $lineDiscount = self::calculateDiscountAmount($lineGross, $discountType, $discountValue);
            $lineNetBeforeBill = max(0.0, $lineGross - $lineDiscount);
        }

        $billDiscount = isset($lineMeta['bill_discount_amount'])
            ? (float) $lineMeta['bill_discount_amount']
            : 0.0;

        $chargedTotal = max(0.0, $lineNetBeforeBill - $billDiscount);
        $expectedNetBeforeRounding = max(0.0, $lineGross - $lineDiscount);
        $roundingAdjustment = round($lineNetBeforeBill - $expectedNetBeforeRounding, 2);

        return [
            'gross' => round($lineGross, 2),
            'discount' => round($lineDiscount, 2),
            'net_before_bill' => round($lineNetBeforeBill, 2),
            'bill_discount' => round($billDiscount, 2),
            'charged_total' => round($chargedTotal, 2),
            'rounding_adjustment' => $roundingAdjustment,
        ];
    }

    private static function calculateDiscountAmount(float $gross, string $type, float $value): float
    {
        if ($value <= 0) {
            return 0.0;
        }

        if ($type === 'percentage') {
            return min($gross, round(($gross * $value) / 100, 2));
        }

        return min($gross, $value);
    }
}
