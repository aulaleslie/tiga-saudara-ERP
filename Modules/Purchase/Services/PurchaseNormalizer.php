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

    public function normalize(
        array $header,
        iterable $detailInputs,
        bool $isPkp,
        ?int $settingId = null,
        mixed $existingPurchaseOrDetails = null
    ): array {
        $details = [];

        $trustedDetailsMap = [];
        if ($existingPurchaseOrDetails instanceof \Modules\Purchase\Entities\Purchase) {
            $existingPurchaseOrDetails->loadMissing('purchaseDetails');
            foreach ($existingPurchaseOrDetails->purchaseDetails as $d) {
                $trustedDetailsMap[(int) $d->id] = $d;
            }
        } elseif ($existingPurchaseOrDetails instanceof Collection) {
            foreach ($existingPurchaseOrDetails as $d) {
                $id = is_object($d) ? ($d->id ?? null) : ($d['id'] ?? null);
                if ($id) {
                    $trustedDetailsMap[(int) $id] = $d;
                }
            }
        } elseif (is_array($existingPurchaseOrDetails)) {
            foreach ($existingPurchaseOrDetails as $d) {
                $id = is_object($d) ? ($d->id ?? null) : ($d['id'] ?? null);
                if ($id) {
                    $trustedDetailsMap[(int) $id] = $d;
                }
            }
        }

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
                $isTaxIncluded,
                $trustedDetailsMap
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
        bool $isTaxIncluded = false,
        array $trustedDetailsMap = []
    ): array {
        $options = $this->extractOptions($detailInput);
        $quantity = $this->normalizeQuantity($detailInput, $options);
        $unitPrice = array_key_exists('unit_price', $options)
            ? $this->toFloat($options['unit_price'])
            : $this->toFloat(data_get($detailInput, 'unit_price', data_get($detailInput, 'price')));
        $price = $this->toFloat(data_get($detailInput, 'price', $unitPrice));
        $discountAmount = $this->toFloat($options['product_discount'] ?? data_get($detailInput, 'discount') ?? data_get($detailInput, 'product_discount_amount'));
        $pricingSource = (string) ($options['pricing_source'] ?? data_get($detailInput, 'pricing_source') ?? 'manual');

        $productId = (int) (data_get($detailInput, 'id') ?? data_get($detailInput, 'product_id') ?? 0);
        $convId = data_get($detailInput, 'product_unit_conversion_id') ?? data_get($detailInput, 'options.product_unit_conversion_id');
        $unitId = data_get($detailInput, 'purchase_unit_id') ?? data_get($detailInput, 'options.purchase_unit_id');

        $uomSnapshot = [];
        if ($productId > 0) {
            $product = \Modules\Product\Entities\Product::find($productId);
            if ($product) {
                $detailId = data_get($detailInput, 'options.purchase_detail_id')
                    ?? data_get($detailInput, 'options.' . \Modules\Purchase\Services\PurchaseMonetaryEditService::DETAIL_ID_OPTION)
                    ?? data_get($detailInput, 'purchase_detail_id');

                $snapshotData = [];
                if ($detailId && isset($trustedDetailsMap[(int) $detailId])) {
                    $existingDetail = $trustedDetailsMap[(int) $detailId];
                    $existingProductId = is_object($existingDetail) ? (int) $existingDetail->product_id : (int) ($existingDetail['product_id'] ?? 0);

                    if ($existingProductId === $productId) {
                        $storedConvId = is_object($existingDetail) ? $existingDetail->product_unit_conversion_id : ($existingDetail['product_unit_conversion_id'] ?? null);
                        $storedUnitId = is_object($existingDetail) ? $existingDetail->purchase_unit_id : ($existingDetail['purchase_unit_id'] ?? null);

                        $storedConvId = $storedConvId ? (int) $storedConvId : null;
                        $storedUnitId = $storedUnitId ? (int) $storedUnitId : null;

                        $parsedConvId = $convId ? (int) $convId : null;
                        $parsedUnitId = $unitId ? (int) $unitId : null;

                        $convUnchanged = ($parsedConvId === null || $parsedConvId === $storedConvId);
                        $unitUnchanged = ($parsedUnitId === null || $parsedUnitId === $storedUnitId);

                        if ($convUnchanged && $unitUnchanged) {
                            $snapshotData = [
                                'is_unchanged_historical' => true,
                                'purchase_unit_id' => is_object($existingDetail) ? $existingDetail->purchase_unit_id : ($existingDetail['purchase_unit_id'] ?? null),
                                'product_unit_conversion_id' => is_object($existingDetail) ? $existingDetail->product_unit_conversion_id : ($existingDetail['product_unit_conversion_id'] ?? null),
                                'conversion_factor' => is_object($existingDetail) ? $existingDetail->effective_conversion_factor : ($existingDetail['conversion_factor'] ?? 1.0),
                                'unit_name' => is_object($existingDetail) ? $existingDetail->effective_unit_name : ($existingDetail['unit_name'] ?? 'UNIT'),
                                'base_unit_name' => is_object($existingDetail) ? $existingDetail->effective_base_unit_name : ($existingDetail['base_unit_name'] ?? 'UNIT'),
                                'unit_price' => is_object($existingDetail) ? $existingDetail->unit_price : ($existingDetail['unit_price'] ?? null),
                                'entered_unit_price' => is_object($existingDetail) ? $existingDetail->effective_entered_unit_price : ($existingDetail['entered_unit_price'] ?? null),
                            ];
                        }
                    }
                }

                $rawQty = data_get($detailInput, 'entered_quantity')
                    ?? data_get($detailInput, 'qty')
                    ?? data_get($detailInput, 'quantity')
                    ?? $quantity;

                $rawPrice = data_get($detailInput, 'entered_unit_price')
                    ?? (array_key_exists('unit_price', $options)
                        ? $options['unit_price']
                        : data_get($detailInput, 'unit_price', data_get($detailInput, 'price')));

                $conversionService = app(\Modules\Purchase\Services\PurchaseUomConversionService::class);
                $convResult = $conversionService->convert(
                    $product,
                    $rawQty,
                    $rawPrice !== null ? (float) $rawPrice : null,
                    $convId ? (int) $convId : null,
                    $unitId ? (int) $unitId : null,
                    $snapshotData
                );

                $quantity = $convResult->canonicalQuantity;
                // The canonical (base-unit) price is always server-derived: the entered
                // price divided by the server-loaded, validated conversion factor. Cart
                // options are request-controlled, so no client-supplied canonical price
                // or row total is allowed to displace this value.
                // Canonical (base-unit) cost is established solely from the entered price
                // and the server-loaded, validated conversion factor -- or, for an
                // unchanged existing line, from the stored PurchaseDetail via
                // $snapshotData. Cart options are request-controlled, so no client-supplied
                // canonical price or row total may steer six-decimal inventory cost: a
                // near-equivalent hint that reconstructs the same displayed two-decimal
                // entered price can still shift per-unit cost, which becomes material
                // across a large canonical quantity.
                if ($convResult->normalizedUnitPrice !== null) {
                    $unitPrice = $convResult->normalizedUnitPrice;
                    $price = $convResult->normalizedUnitPrice;
                }

                $uomSnapshot = $convResult->toArray();
                $uomSnapshot['unit_price'] = number_format($unitPrice, 6, '.', '');

                $discountType = strtolower((string) ($options['product_discount_type'] ?? data_get($detailInput, 'discount_type') ?? data_get($detailInput, 'product_discount_type') ?? 'fixed'));
                $rawDiscountInput = $this->toFloat(
                    data_get($detailInput, 'entered_product_discount_amount')
                    ?? $options['product_discount']
                    ?? data_get($detailInput, 'discount')
                    ?? data_get($detailInput, 'product_discount_amount')
                );

                if ($discountType === 'percentage') {
                    $discountAmount = $unitPrice * ($rawDiscountInput / 100);
                } elseif ($discountType === 'fixed' && $convResult->conversionFactor > 1.0) {
                    $discountAmount = $rawDiscountInput / $convResult->conversionFactor;
                } else {
                    $discountAmount = $rawDiscountInput;
                }

                $uomSnapshot['entered_product_discount_amount'] = number_format($rawDiscountInput, 2, '.', '');
            }
        }

        if (empty($uomSnapshot)) {
            $rawDiscountInput = $this->toFloat(
                data_get($detailInput, 'entered_product_discount_amount')
                ?? $options['product_discount']
                ?? data_get($detailInput, 'discount')
                ?? data_get($detailInput, 'product_discount_amount')
            );
            $uomSnapshot = [
                'purchase_unit_id' => $unitId ? (int) $unitId : null,
                'product_unit_conversion_id' => $convId ? (int) $convId : null,
                'quantity' => number_format($quantity, 3, '.', ''),
                'entered_quantity' => number_format($quantity, 3, '.', ''),
                'unit_price' => number_format($unitPrice, 6, '.', ''),
                'entered_unit_price' => number_format($unitPrice, 2, '.', ''),
                'entered_product_discount_amount' => number_format($rawDiscountInput, 2, '.', ''),
                'conversion_factor' => '1.000000',
                'unit_name' => 'UNIT',
                'base_unit_name' => 'UNIT',
            ];
        }

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
                // Purchase totals are exact: no configured rounding increment is
                // applied, only ordinary two-decimal currency precision. Sales and
                // POS keep their own increment behavior.
                $roundedSubTotal = $this->roundMoney($rawSubTotal);
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

        return array_merge([
            'product_id' => (int) (data_get($detailInput, 'id') ?? data_get($detailInput, 'product_id') ?? 0),
            'product_name' => (string) (data_get($detailInput, 'name') ?? data_get($detailInput, 'product_name') ?? ''),
            'product_code' => (string) ($options['code'] ?? data_get($detailInput, 'product_code') ?? ''),
            'quantity' => $quantity,
            // Both columns are decimal(15,6) and hold the same canonical base-unit
            // price. Rounding to 2 here would leave `price` disagreeing with the
            // 6-decimal `unit_price` the snapshot merges in below.
            'unit_price' => $this->roundCanonicalPrice($unitPrice),
            'price' => $this->roundCanonicalPrice($price),
            'product_discount_type' => (string) ($options['product_discount_type'] ?? data_get($detailInput, 'discount_type') ?? data_get($detailInput, 'product_discount_type') ?? 'fixed'),
            'product_discount_amount' => $this->roundMoney($discountAmount),
            'sub_total' => $normalizedSubTotal,
            'product_tax_amount' => $normalizedTaxAmount,
            'tax_id' => $normalizedTaxId,
            'sub_total_before_tax' => $this->roundMoney($subTotalBeforeTax),
            'pricing_source' => $pricingSource,
        ], $uomSnapshot);
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

    private function normalizeQuantity(mixed $detailInput, array $options): float
    {
        $quantity = data_get($detailInput, 'qty');

        if ($quantity === null) {
            $quantity = data_get($detailInput, 'quantity', $options['quantity'] ?? 0);
        }

        return max(0.0, round((float) $quantity, 3));
    }

    private function resolveIncomingSubTotal(mixed $detailInput, array $options, float $price, float $quantity, float $discountAmount): float
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
        float $quantity,
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

    /**
     * Canonical base-unit prices keep 6 decimals: dividing an entered price by a
     * conversion factor frequently repeats (100,000 / 3), and truncating to 2 here
     * would lose the precision the decimal(15,6) price columns exist to hold.
     */
    private function roundCanonicalPrice(float $value): float
    {
        return round($value, 6);
    }
}
