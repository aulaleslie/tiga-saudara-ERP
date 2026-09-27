<?php

namespace Modules\Pos\Services;

use DomainException;

/**
 * The single arithmetic authority for POS row overrides.
 *
 * Previously `PosLineTotalAllocator` derived subtotal/tax/discount and then
 * discarded them while `PosCartTotalsCalculator` independently reconstructed
 * different values from `line_total`. This class replaces that split: it
 * produces the canonical derived metadata once, in integer minor units, so
 * display, approval, draft, checkout, posting, receipt, and audit all agree
 * instead of re-deriving contradictory numbers later.
 *
 * All money is integer minor units (cents). Floats appear only where a caller
 * converts at a display boundary.
 */
class PosRowOverrideArithmetic
{
    /**
     * Apply a unit-price override.
     *
     * The requested unit price becomes the authoritative gross unit price.
     * Row gross is unit price x effective quantity; the existing row discount
     * is then applied exactly once.
     *
     * @param  int  $unitPriceMinor  Requested authoritative gross unit price.
     * @param  int  $qty             Effective (conversion-applied) quantity.
     * @return array<string, int|string>
     */
    public function applyUnitPrice(
        int $unitPriceMinor,
        int $qty,
        string $discountType = 'fixed',
        float $discountValue = 0.0,
        float $taxRate = 0.0,
        bool $isPkp = false
    ): array {
        if ($unitPriceMinor < 0) {
            throw new DomainException('Harga satuan tidak boleh negatif.');
        }

        $normalizedType = $this->normalizeDiscountType($discountType);
        $this->assertDiscountValueUsable($normalizedType, $discountValue);

        $effectiveQty = max(0, $qty);
        $grossMinor = $unitPriceMinor * $effectiveQty;
        $discountMinor = $this->forwardDiscountMinor($normalizedType, $discountValue, $grossMinor);
        $netMinor = max(0, $grossMinor - $discountMinor);

        return $this->assemble(
            unitPriceMinor: $unitPriceMinor,
            grossMinor: $grossMinor,
            discountMinor: $discountMinor,
            netMinor: $netMinor,
            qty: $effectiveQty,
            discountType: $normalizedType,
            discountValue: $discountValue,
            taxRate: $taxRate,
            isPkp: $isPkp,
            source: 'LINE_UNIT_PRICE_OVERRIDE'
        );
    }

    /**
     * Apply a row-total override.
     *
     * The requested total is authoritative *after* the row discount and
     * *before* bill-level adjustment. Gross and discount are reverse-derived so
     * the requested net is reproduced exactly:
     *
     *   fixed:      gross = net + discount
     *   percentage: gross = round(net / (1 - pct/100)), discount = gross - net
     *
     * Deriving the discount as `gross x pct` and subtracting would reintroduce
     * rounding drift; taking it as the residual makes the net exact by
     * construction.
     *
     * @param  int  $requestedNetMinor  Requested authoritative post-discount row total.
     * @return array<string, int|string>
     */
    public function applyRowTotal(
        int $requestedNetMinor,
        int $qty,
        string $discountType = 'fixed',
        float $discountValue = 0.0,
        float $taxRate = 0.0,
        bool $isPkp = false
    ): array {
        if ($requestedNetMinor < 0) {
            throw new DomainException('Total baris tidak boleh negatif.');
        }

        $normalizedType = $this->normalizeDiscountType($discountType);
        $this->assertDiscountValueUsable($normalizedType, $discountValue);

        $effectiveQty = max(0, $qty);
        [$grossMinor, $discountMinor] = $this->reverseDiscount(
            $normalizedType,
            $discountValue,
            $requestedNetMinor
        );

        // The requested net is authoritative and must survive exactly.
        $netMinor = $requestedNetMinor;

        // Effective unit price is derived for display only. It is rounded, so
        // it must never be multiplied back out to reproduce the row total.
        $unitPriceMinor = $effectiveQty > 0
            ? (int) round($grossMinor / $effectiveQty)
            : 0;

        return $this->assemble(
            unitPriceMinor: $unitPriceMinor,
            grossMinor: $grossMinor,
            discountMinor: $discountMinor,
            netMinor: $netMinor,
            qty: $effectiveQty,
            discountType: $normalizedType,
            discountValue: $discountValue,
            taxRate: $taxRate,
            isPkp: $isPkp,
            source: 'LINE_TOTAL_OVERRIDE'
        );
    }

    /**
     * Build the canonical derived metadata persisted on the line.
     *
     * @return array<string, int|string>
     */
    private function assemble(
        int $unitPriceMinor,
        int $grossMinor,
        int $discountMinor,
        int $netMinor,
        int $qty,
        string $discountType,
        float $discountValue,
        float $taxRate,
        bool $isPkp,
        string $source
    ): array {
        $taxMinor = $this->inclusiveTaxMinor($netMinor, $taxRate, $isPkp);

        return [
            'price_source' => $source,
            'qty' => $qty,
            'unit_price_minor' => $unitPriceMinor,
            'line_gross_minor' => $grossMinor,
            'line_discount_type' => $discountType,
            'line_discount_value' => $discountValue,
            'line_discount_minor' => $discountMinor,
            // Authoritative post-row-discount, pre-bill-discount amount.
            'line_net_minor' => $netMinor,
            'line_tax_minor' => $taxMinor,
            // POS prices are tax-inclusive, so the pre-tax base is net - tax.
            'line_taxable_base_minor' => max(0, $netMinor - $taxMinor),
        ];
    }

    /**
     * Reverse a row discount from an authoritative net amount.
     *
     * @return array{0:int, 1:int} [grossMinor, discountMinor]
     */
    private function reverseDiscount(string $type, float $value, int $netMinor): array
    {
        if ($netMinor <= 0) {
            return [$netMinor, 0];
        }

        if ($type === 'percentage') {
            $percentage = (float) $value;

            if ($percentage <= 0.0) {
                return [$netMinor, 0];
            }

            $grossMinor = (int) round($netMinor / (1 - ($percentage / 100)), 0, PHP_ROUND_HALF_UP);

            // Residual, not gross x pct: this is what makes the net exact.
            return [$grossMinor, $grossMinor - $netMinor];
        }

        $discountMinor = max(0, (int) round($value * 100, 0, PHP_ROUND_HALF_UP));

        return [$netMinor + $discountMinor, $discountMinor];
    }

    /**
     * Apply a row discount forward to a gross amount.
     */
    private function forwardDiscountMinor(string $type, float $value, int $grossMinor): int
    {
        if ($grossMinor <= 0) {
            return 0;
        }

        if ($type === 'percentage') {
            $percentage = max(0.0, (float) $value);

            return min($grossMinor, (int) round(($grossMinor * $percentage) / 100, 0, PHP_ROUND_HALF_UP));
        }

        return min($grossMinor, max(0, (int) round($value * 100, 0, PHP_ROUND_HALF_UP)));
    }

    /**
     * Extract inclusive tax from a gross amount, matching existing POS rules
     * (whole-rupiah rounding, zero for non-PKP).
     */
    private function inclusiveTaxMinor(int $netMinor, float $taxRate, bool $isPkp): int
    {
        if (! $isPkp || $netMinor <= 0) {
            return 0;
        }

        $rateBasisPoints = (int) round(max(0.0, $taxRate) * 100, 0, PHP_ROUND_HALF_UP);

        if ($rateBasisPoints <= 0) {
            return 0;
        }

        $grossAmount = $netMinor / 100;
        $taxAmount = (int) round(
            ($grossAmount * $rateBasisPoints) / (10000 + $rateBasisPoints),
            0,
            PHP_ROUND_HALF_UP
        );

        return $taxAmount * 100;
    }

    /**
     * Reject unusable percentage discounts rather than clamping them.
     *
     * At 100% no gross reproduces a positive net, and a silent clamp would
     * return a zero discount that contradicts the stored row.
     */
    private function assertDiscountValueUsable(string $type, float $value): void
    {
        if ($type !== 'percentage') {
            return;
        }

        if ($value < 0.0) {
            throw new DomainException('Persentase diskon baris tidak boleh negatif.');
        }

        if ($value >= 100.0) {
            throw new DomainException('Persentase diskon baris harus kurang dari 100%.');
        }
    }

    private function normalizeDiscountType(string $type): string
    {
        return strtolower($type) === 'percentage' ? 'percentage' : 'fixed';
    }
}
