<?php

namespace Modules\Sale\Services;

use Illuminate\Support\Collection;

class SaleNormalizer
{
    /**
     * @param  iterable<int, mixed>  $detailInputs
     * @return array{
     *     header: array<string, mixed>,
     *     details: array<int, array<string, mixed>>,
     *     computed_discount_amount: float
     * }
     */
    public function normalize(array $header, iterable $detailInputs, bool $isPkp): array
    {
        $details = [];

        foreach ($detailInputs as $detailInput) {
            $details[] = $this->normalizeDetail($detailInput, $isPkp);
        }

        $totalSubTotal = array_sum(array_map(
            static fn (array $detail): float => (float) $detail['sub_total'],
            $details
        ));
        $taxAmount = array_sum(array_map(
            static fn (array $detail): float => (float) $detail['product_tax_amount'],
            $details
        ));

        $rawDiscountPercentage = $this->toFloat($header['discount_percentage'] ?? 0);
        $rawDiscountAmount = $this->toFloat($header['discount_amount'] ?? 0);
        $shippingAmount = max(0.0, $this->toFloat($header['shipping_amount'] ?? $header['shipping'] ?? 0));
        $paidAmount = max(0.0, $this->toFloat($header['paid_amount'] ?? 0));

        $isPercentageMode = $rawDiscountPercentage > 0;

        if ($isPercentageMode) {
            $discountPercentage = min(100.0, max(0.0, $rawDiscountPercentage));
            $computedDiscountAmount = $this->roundMoney($totalSubTotal * ($discountPercentage / 100));
            // In percentage mode, avoid allowing an unrelated fixed-discount input to create a second discount representation
            $discountAmount = 0.0;
        } else {
            $discountPercentage = 0.0;
            $rawFixedDiscount = max(0.0, $rawDiscountAmount);
            $computedDiscountAmount = min($rawFixedDiscount, $totalSubTotal);
            $discountAmount = $computedDiscountAmount;
        }

        // Prorate global discount across commercial rows in minor units
        $rowProratedDiscounts = $this->prorateGlobalDiscount($details, $computedDiscountAmount);
        $effectiveCommercialTotalMinor = 0;
        $totalAllocatedDiscountMinor = 0;

        foreach ($details as $idx => &$detail) {
            $share = $rowProratedDiscounts[$idx] ?? 0.0;
            $shareMinor = (int) round($share * 100);
            $subTotalMinor = (int) round((float) ($detail['sub_total'] ?? 0) * 100);
            $effectiveSubTotalMinor = max(0, $subTotalMinor - $shareMinor);

            $detail['global_discount_share'] = round($shareMinor / 100, 2);
            $detail['effective_sub_total'] = round($effectiveSubTotalMinor / 100, 2);

            $totalAllocatedDiscountMinor += $shareMinor;
            $effectiveCommercialTotalMinor += $effectiveSubTotalMinor;
        }
        unset($detail);

        $allocatedDiscountTotal = round($totalAllocatedDiscountMinor / 100, 2);
        $effectiveCommercialTotal = round($effectiveCommercialTotalMinor / 100, 2);

        // Authoritative total amount derived directly from allocated effective commercial total plus shipping
        $shippingMinor = (int) round($shippingAmount * 100);
        $totalAmountMinor = $effectiveCommercialTotalMinor + $shippingMinor;
        $totalAmount = round($totalAmountMinor / 100, 2);

        $dueAmount = $this->roundMoney(max($totalAmount - $paidAmount, 0));

        $normalizedHeader = [
            'tax_id' => $isPkp ? $this->normalizeNullableInt($header['tax_id'] ?? null) : null,
            'tax_percentage' => $isPkp ? $this->toFloat($header['tax_percentage'] ?? 0) : 0.0,
            'tax_amount' => $isPkp ? $this->roundMoney($taxAmount) : 0.0,
            'discount_percentage' => $discountPercentage,
            'discount_amount' => $discountAmount,
            'shipping_amount' => $this->roundMoney($shippingAmount),
            'paid_amount' => $this->roundMoney($paidAmount),
            'total_amount' => $totalAmount,
            'due_amount' => $dueAmount,
        ];

        return [
            'header' => $normalizedHeader,
            'details' => $details,
            'computed_discount_amount' => $computedDiscountAmount,
            'allocated_discount_total' => $allocatedDiscountTotal,
            'effective_commercial_total' => $effectiveCommercialTotal,
        ];
    }

    /**
     * Prorates the global discount amount across commercial rows using integer minor units.
     * Assigns rounding remainders deterministically based on largest remainder.
     * Bundle components are ignored and receive 0.
     *
     * @param  array<int, array<string, mixed>>  $details
     * @return array<int, float>  Index -> discount share
     */
    public function prorateGlobalDiscount(array $details, float $totalDiscountAmount): array
    {
        $discountMinor = (int) round($totalDiscountAmount * 100);
        if ($discountMinor <= 0 || empty($details)) {
            return array_fill(0, count($details), 0.0);
        }

        $weights = [];
        $totalWeightMinor = 0;

        foreach ($details as $idx => $detail) {
            $subTotalMinor = (int) round((float) ($detail['sub_total'] ?? 0) * 100);
            $weights[$idx] = max(0, $subTotalMinor);
            $totalWeightMinor += $weights[$idx];
        }

        if ($totalWeightMinor <= 0) {
            return array_fill(0, count($details), 0.0);
        }

        // Cap discount at total weight
        $discountMinor = min($discountMinor, $totalWeightMinor);

        $sharesMinor = [];
        $remainders = [];
        $allocatedMinor = 0;

        foreach ($details as $idx => $detail) {
            $w = $weights[$idx];
            $product = $discountMinor * $w;
            $share = intdiv($product, $totalWeightMinor);
            $rem = $product % $totalWeightMinor;

            $sharesMinor[$idx] = $share;
            $allocatedMinor += $share;
            $remainders[] = [
                'index' => $idx,
                'remainder' => $rem,
                'weight' => $w,
            ];
        }

        $unallocatedMinor = $discountMinor - $allocatedMinor;
        if ($unallocatedMinor > 0) {
            // Sort by largest remainder desc, then largest weight desc, then index asc
            usort($remainders, function ($a, $b) {
                if ($b['remainder'] !== $a['remainder']) {
                    return $b['remainder'] <=> $a['remainder'];
                }
                if ($b['weight'] !== $a['weight']) {
                    return $b['weight'] <=> $a['weight'];
                }
                return $a['index'] <=> $b['index'];
            });

            for ($i = 0; $i < $unallocatedMinor; $i++) {
                $idx = $remainders[$i % count($remainders)]['index'];
                $sharesMinor[$idx]++;
            }
        }

        $result = [];
        foreach ($details as $idx => $detail) {
            $result[$idx] = round(($sharesMinor[$idx] ?? 0) / 100, 2);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeDetail(mixed $detailInput, bool $isPkp): array
    {
        $options = $this->extractOptions($detailInput);
        $quantity = $this->normalizeQuantity($detailInput, $options);
        $unitPrice = $this->toFloat($options['unit_price'] ?? data_get($detailInput, 'unit_price') ?? data_get($detailInput, 'price'));
        $price = $this->toFloat(data_get($detailInput, 'price', $unitPrice));
        $discountAmount = $this->toFloat($options['product_discount'] ?? data_get($detailInput, 'discount') ?? data_get($detailInput, 'product_discount_amount'));

        $incomingSubTotal = $this->resolveIncomingSubTotal($detailInput, $options, $price, $quantity, $discountAmount);
        $incomingTaxAmount = $this->resolveIncomingTaxAmount($detailInput, $options, $incomingSubTotal);
        $subTotalBeforeTax = $this->resolveSubTotalBeforeTax(
            $detailInput,
            $options,
            $incomingSubTotal,
            $incomingTaxAmount,
            $price,
            $quantity,
            $discountAmount
        );

        $normalizedTaxId = $isPkp
            ? $this->normalizeNullableInt($options['product_tax'] ?? $options['tax_id'] ?? data_get($detailInput, 'tax_id'))
            : null;
        $normalizedTaxAmount = $isPkp ? $this->roundMoney($incomingTaxAmount) : 0.0;
        $normalizedSubTotal = $isPkp
            ? $this->roundMoney($incomingSubTotal)
            : $this->roundMoney($subTotalBeforeTax);

        return [
            'product_id' => (int) ($options['product_id'] ?? data_get($detailInput, 'product_id') ?? data_get($detailInput, 'id') ?? 0),
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
            'bundle_items' => $this->normalizeBundleItems($options['bundle_items'] ?? data_get($detailInput, 'bundle_items', [])),
            'pricing_source' => (string) ($options['pricing_source'] ?? data_get($detailInput, 'pricing_source') ?? 'automatic'),
        ];
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
        if (array_key_exists('sub_total', $options)) {
            return $this->toFloat($options['sub_total']);
        }

        if (data_get($detailInput, 'sub_total') !== null) {
            return $this->toFloat(data_get($detailInput, 'sub_total'));
        }

        return $this->roundMoney(max($price - $discountAmount, 0) * $quantity);
    }

    private function resolveIncomingTaxAmount(mixed $detailInput, array $options, float $incomingSubTotal): float
    {
        if (array_key_exists('product_tax_amount', $options)) {
            return $this->toFloat($options['product_tax_amount']);
        }

        if (data_get($detailInput, 'product_tax_amount') !== null) {
            return $this->toFloat(data_get($detailInput, 'product_tax_amount'));
        }

        if (array_key_exists('sub_total_before_tax', $options)) {
            return $this->roundMoney($incomingSubTotal - $this->toFloat($options['sub_total_before_tax']));
        }

        if (data_get($detailInput, 'sub_total_before_tax') !== null) {
            return $this->roundMoney($incomingSubTotal - $this->toFloat(data_get($detailInput, 'sub_total_before_tax')));
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
        if (array_key_exists('sub_total_before_tax', $options)) {
            return $this->toFloat($options['sub_total_before_tax']);
        }

        if (data_get($detailInput, 'sub_total_before_tax') !== null) {
            return $this->toFloat(data_get($detailInput, 'sub_total_before_tax'));
        }

        if ($incomingTaxAmount > 0) {
            return $this->roundMoney($incomingSubTotal - $incomingTaxAmount);
        }

        return $this->roundMoney(max($price - $discountAmount, 0) * $quantity);
    }

    /**
     * @param  iterable<int, mixed>|mixed  $bundleItems
     * @return array<int, array<string, mixed>>
     */
    private function normalizeBundleItems(mixed $bundleItems): array
    {
        if ($bundleItems instanceof Collection) {
            $bundleItems = $bundleItems->all();
        } elseif ($bundleItems === null) {
            return [];
        } elseif (! is_array($bundleItems)) {
            $bundleItems = (array) $bundleItems;
        }

        $normalized = [];

        foreach ($bundleItems as $bundleItem) {
            $bundleItem = is_array($bundleItem) ? $bundleItem : (array) $bundleItem;

            $bundleId = $this->normalizeNullableInt($bundleItem['bundle_id'] ?? null);

            $normalized[] = [
                'bundle_id' => $bundleId,
                'bundle_item_id' => $this->normalizeNullableInt($bundleItem['bundle_item_id'] ?? null),
                'product_id' => $this->normalizeNullableInt($bundleItem['product_id'] ?? null),
                'name' => (string) ($bundleItem['name'] ?? ''),
                // Task 3.3/3.4: Selected bundle components are non-billable.
                'price' => $bundleId ? 0.0 : $this->roundMoney($this->toFloat($bundleItem['price'] ?? 0)),
                'quantity' => $this->toFloat($bundleItem['quantity'] ?? 0),
                'quantity_per_bundle' => $this->toFloat($bundleItem['quantity_per_bundle'] ?? 0),
                'sub_total' => $bundleId ? 0.0 : $this->roundMoney($this->toFloat($bundleItem['sub_total'] ?? 0)),
                'tax_id' => $this->normalizeNullableInt($bundleItem['tax_id'] ?? null),
            ];
        }

        return $normalized;
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
