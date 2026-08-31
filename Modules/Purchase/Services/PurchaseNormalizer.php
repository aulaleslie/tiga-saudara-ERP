<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Collection;

class PurchaseNormalizer
{
    /**
     * @param  iterable<int, mixed>  $detailInputs
     * @return array{
     *     header: array<string, mixed>,
     *     details: array<int, array<string, mixed>>,
     *     computed_discount_amount: float
     * }
     */
    /** @var array<int, float> Tax rates by id, memoized per normalize() call. */
    private array $taxRateCache = [];

    private ?float $defaultTaxRate = null;

    public function normalize(array $header, iterable $detailInputs, bool $isPkp, ?int $settingId = null): array
    {
        $details = [];

        // Resolved once per call rather than once per row: a large document would
        // otherwise issue one Setting query per detail line.
        $settingIncrement = $settingId !== null
            ? (float) (\Modules\Setting\Entities\Setting::query()->whereKey($settingId)->value('row_total_rounding_increment') ?? 100.00)
            : 100.00;

        // Whether the submitted prices already contain tax. Without this the
        // automatic branch would re-apply the tax rate to a tax-inclusive price.
        $isTaxIncluded = (bool) ($header['is_tax_included'] ?? false);

        $this->taxRateCache = [];
        $this->defaultTaxRate = null;

        foreach ($detailInputs as $detailInput) {
            $details[] = $this->normalizeDetail(
                $detailInput,
                $isPkp,
                $settingId,
                $settingIncrement,
                $isTaxIncluded
            );
        }

        $totalSubTotal = array_sum(array_map(
            static fn (array $detail): float => (float) $detail['sub_total'],
            $details
        ));
        $taxAmount = array_sum(array_map(
            static fn (array $detail): float => (float) $detail['product_tax_amount'],
            $details
        ));

        $discountPercentage = $this->toFloat($header['discount_percentage'] ?? 0);
        $discountAmount = $this->toFloat($header['discount_amount'] ?? 0);
        $shippingAmount = $this->toFloat($header['shipping_amount'] ?? $header['shipping'] ?? 0);
        $paidAmount = $this->toFloat($header['paid_amount'] ?? 0);

        $computedDiscountAmount = $discountPercentage > 0
            ? $this->roundMoney($totalSubTotal * ($discountPercentage / 100))
            : $this->roundMoney($discountAmount);

        $totalAmount = $this->roundMoney($totalSubTotal - $computedDiscountAmount + $shippingAmount);
        $dueAmount = $this->roundMoney(max($totalAmount - $paidAmount, 0));

        $normalizedHeader = [
            'tax_id' => $isPkp ? $this->normalizeNullableInt($header['tax_id'] ?? null) : null,
            'tax_percentage' => $isPkp ? $this->toFloat($header['tax_percentage'] ?? 0) : 0.0,
            'tax_amount' => $isPkp ? $this->roundMoney($taxAmount) : 0.0,
            'discount_percentage' => $discountPercentage,
            'discount_amount' => $discountAmount,
            'shipping_amount' => $this->roundMoney($shippingAmount),
            'total_amount' => $totalAmount,
            'due_amount' => $dueAmount,
        ];

        return [
            'header' => $normalizedHeader,
            'details' => $details,
            'computed_discount_amount' => $computedDiscountAmount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeDetail(
        mixed $detailInput,
        bool $isPkp,
        ?int $settingId = null,
        float $settingIncrement = 100.00,
        bool $isTaxIncluded = false
    ): array {
        $options = $this->extractOptions($detailInput);
        $quantity = $this->normalizeQuantity($detailInput, $options);
        $unitPrice = array_key_exists('unit_price', $options)
            ? $this->toFloat($options['unit_price'])
            : $this->toFloat(data_get($detailInput, 'unit_price', data_get($detailInput, 'price')));
        $price = $this->toFloat(data_get($detailInput, 'price', $unitPrice));
        $discountAmount = $this->toFloat($options['product_discount'] ?? data_get($detailInput, 'discount') ?? data_get($detailInput, 'product_discount_amount'));
        $pricingSource = (string) ($options['pricing_source'] ?? data_get($detailInput, 'pricing_source') ?? 'manual');

        if ($settingId && $pricingSource === 'automatic') {
            $rawTaxOption = $options['product_tax'] ?? data_get($detailInput, 'options.product_tax') ?? data_get($detailInput, 'tax_id') ?? data_get($detailInput, 'product_tax');
            $normalizedTaxIdTemp = $isPkp
                ? $this->normalizeNullableInt($rawTaxOption)
                : null;
            $taxRate = $this->resolveTaxRate($normalizedTaxIdTemp, $isPkp);

            // Only the server-side cart sets this, and only for a row it just
            // recalculated. Absent/false means the supplied total is a stored
            // value being carried through a load/save and must be preserved.
            $recalcRequired = (bool) (
                $options[\App\Support\RowTotalRoundingCalculator::RECALC_FLAG]
                    ?? data_get($detailInput, \App\Support\RowTotalRoundingCalculator::RECALC_FLAG)
                    ?? false
            );

            $suppliedSubTotal = array_key_exists('sub_total', $options)
                ? $this->toFloat($options['sub_total'])
                : (data_get($detailInput, 'sub_total') !== null ? $this->toFloat(data_get($detailInput, 'sub_total')) : null);

            if (! $recalcRequired && $suppliedSubTotal !== null) {
                $roundedSubTotal = $this->roundMoney($suppliedSubTotal);
            } else {
                $rawNetUnitPrice = max($unitPrice - $discountAmount, 0);
                // A tax-inclusive unit price already carries the tax, so the row
                // total is the price itself; only a tax-exclusive price is grossed up.
                $rawSubTotal = $isTaxIncluded
                    ? $rawNetUnitPrice * $quantity
                    : $rawNetUnitPrice * $quantity * (1 + $taxRate);
                $roundedSubTotal = \App\Support\RowTotalRoundingCalculator::round($rawSubTotal, $settingIncrement);
            }

            if ($taxRate > 0 && $roundedSubTotal > 0) {
                $incomingTaxAmount = round($roundedSubTotal - ($roundedSubTotal / (1 + $taxRate)), 2);
                $subTotalBeforeTax = round($roundedSubTotal - $incomingTaxAmount, 2);
            } else {
                $incomingTaxAmount = 0.0;
                $subTotalBeforeTax = $roundedSubTotal;
            }
            $incomingSubTotal = $roundedSubTotal;
        } else {
            $incomingSubTotal = $this->resolveIncomingSubTotal($detailInput, $options, $price, $quantity, $discountAmount);
            $incomingTaxAmount = $this->resolveIncomingTaxAmount($detailInput, $options, $incomingSubTotal);
            $subTotalBeforeTax = $this->resolveSubTotalBeforeTax($detailInput, $options, $incomingSubTotal, $incomingTaxAmount, $price, $quantity, $discountAmount);
        }

        $normalizedTaxId = $isPkp
            ? $this->normalizeNullableInt($options['product_tax'] ?? data_get($detailInput, 'tax_id'))
            : null;
        $normalizedTaxAmount = $isPkp ? $this->roundMoney($incomingTaxAmount) : 0.0;
        // A non-PKP business stores no tax, so the authoritative row total there is
        // the tax-stripped DPP rather than the tax-inclusive amount.
        $normalizedSubTotal = $isPkp
            ? $this->roundMoney($incomingSubTotal)
            : $this->roundMoney($subTotalBeforeTax);

        return [
            'product_id' => (int) (data_get($detailInput, 'id') ?? data_get($detailInput, 'product_id') ?? 0),
            'product_name' => (string) (data_get($detailInput, 'name') ?? data_get($detailInput, 'product_name') ?? ''),
            'product_code' => (string) ($options['code'] ?? data_get($detailInput, 'product_code') ?? ''),
            'quantity' => $quantity,
            'unit_price' => $this->roundMoney($unitPrice),
            'price' => $this->roundMoney($price),
            'product_discount_type' => (string) ($options['product_discount_type'] ?? data_get($detailInput, 'discount_type') ?? data_get($detailInput, 'product_discount_type') ?? 'fixed'),
            'product_discount_amount' => $this->roundMoney($discountAmount),
            'sub_total' => $normalizedSubTotal,
            'product_tax_amount' => $normalizedTaxAmount,
            'tax_id' => $normalizedTaxId,
            'sub_total_before_tax' => $this->roundMoney($subTotalBeforeTax),
            'pricing_source' => $pricingSource,
        ];
    }

    /**
     * Resolve a tax rate as a fraction, memoized for the current normalize() call
     * so a many-line document does not issue one Tax query per row.
     */
    private function resolveTaxRate(?int $taxId, bool $isPkp): float
    {
        if ($taxId) {
            if (! array_key_exists($taxId, $this->taxRateCache)) {
                $value = \Modules\Setting\Entities\Tax::query()->whereKey($taxId)->value('value');
                $this->taxRateCache[$taxId] = $value !== null ? (float) $value / 100 : 0.0;
            }

            return $this->taxRateCache[$taxId];
        }

        if (! $isPkp) {
            return 0.0;
        }

        if ($this->defaultTaxRate === null) {
            $defaultTaxVal = \Modules\Setting\Entities\Tax::query()->where('is_default', true)->value('value');
            $this->defaultTaxRate = $defaultTaxVal !== null ? (float) $defaultTaxVal / 100 : 0.0;
        }

        return $this->defaultTaxRate;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractOptions(mixed $detailInput): array
    {
        $options = data_get($detailInput, 'options', []);

        if ($options instanceof Collection) {
            return $options->all();
        }

        if (is_object($options) && method_exists($options, 'toArray')) {
            return $options->toArray();
        }

        return is_array($options) ? $options : [];
    }

    private function normalizeQuantity(mixed $detailInput, array $options): int
    {
        $quantity = data_get($detailInput, 'qty');

        if ($quantity === null) {
            $quantity = data_get($detailInput, 'quantity', $options['quantity'] ?? 0);
        }

        return max(0, (int) $quantity);
    }

    private function resolveIncomingSubTotal(mixed $detailInput, array $options, float $price, int $quantity, float $discountAmount): float
    {
        $pricingSource = (string) ($options['pricing_source'] ?? data_get($detailInput, 'pricing_source') ?? 'manual');

        if ($pricingSource !== 'automatic' && array_key_exists('sub_total', $options)) {
            return $this->toFloat($options['sub_total']);
        }

        if ($pricingSource !== 'automatic' && data_get($detailInput, 'sub_total') !== null) {
            return $this->toFloat(data_get($detailInput, 'sub_total'));
        }

        $effectivePrice = array_key_exists('unit_price', $options) ? $this->toFloat($options['unit_price']) : $price;
        return $this->roundMoney(max($effectivePrice - $discountAmount, 0) * $quantity);
    }

    private function resolveIncomingTaxAmount(mixed $detailInput, array $options, float $incomingSubTotal): float
    {
        $pricingSource = (string) ($options['pricing_source'] ?? data_get($detailInput, 'pricing_source') ?? 'manual');

        if ($pricingSource !== 'automatic' && array_key_exists('product_tax_amount', $options)) {
            return $this->toFloat($options['product_tax_amount']);
        }

        if ($pricingSource !== 'automatic' && data_get($detailInput, 'product_tax_amount') !== null) {
            return $this->toFloat(data_get($detailInput, 'product_tax_amount'));
        }

        if ($pricingSource !== 'automatic' && array_key_exists('sub_total_before_tax', $options)) {
            return $this->roundMoney($incomingSubTotal - $this->toFloat($options['sub_total_before_tax']));
        }

        return 0.0;
    }

    private function resolveSubTotalBeforeTax(
        mixed $detailInput,
        array $options,
        float $incomingSubTotal,
        float $incomingTaxAmount,
        float $price,
        int $quantity,
        float $discountAmount
    ): float {
        $pricingSource = (string) ($options['pricing_source'] ?? data_get($detailInput, 'pricing_source') ?? 'manual');

        if ($pricingSource !== 'automatic' && array_key_exists('sub_total_before_tax', $options)) {
            return $this->toFloat($options['sub_total_before_tax']);
        }

        if ($pricingSource !== 'automatic' && data_get($detailInput, 'sub_total_before_tax') !== null) {
            return $this->toFloat(data_get($detailInput, 'sub_total_before_tax'));
        }

        if ($incomingTaxAmount > 0) {
            return $this->roundMoney($incomingSubTotal - $incomingTaxAmount);
        }

        return $this->roundMoney(max($price - $discountAmount, 0) * $quantity);
    }

    private function toFloat(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return (float) $value;
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function roundMoney(float $value): float
    {
        return round($value, 2);
    }
}
