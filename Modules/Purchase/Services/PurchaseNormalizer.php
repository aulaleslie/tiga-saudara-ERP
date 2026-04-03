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
    private function normalizeDetail(mixed $detailInput, bool $isPkp): array
    {
        $options = $this->extractOptions($detailInput);
        $quantity = $this->normalizeQuantity($detailInput, $options);
        $unitPrice = $this->toFloat($options['unit_price'] ?? data_get($detailInput, 'unit_price') ?? data_get($detailInput, 'price'));
        $price = $this->toFloat(data_get($detailInput, 'price', $unitPrice));
        $discountAmount = $this->toFloat($options['product_discount'] ?? data_get($detailInput, 'discount') ?? data_get($detailInput, 'product_discount_amount'));

        $incomingSubTotal = $this->resolveIncomingSubTotal($detailInput, $options, $price, $quantity, $discountAmount);
        $incomingTaxAmount = $this->resolveIncomingTaxAmount($detailInput, $options, $incomingSubTotal);
        $subTotalBeforeTax = $this->resolveSubTotalBeforeTax($detailInput, $options, $incomingSubTotal, $incomingTaxAmount, $price, $quantity, $discountAmount);

        $normalizedTaxId = $isPkp
            ? $this->normalizeNullableInt($options['product_tax'] ?? data_get($detailInput, 'tax_id'))
            : null;
        $normalizedTaxAmount = $isPkp ? $this->roundMoney($incomingTaxAmount) : 0.0;
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
