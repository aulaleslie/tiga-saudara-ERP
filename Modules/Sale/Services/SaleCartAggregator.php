<?php

namespace Modules\Sale\Services;

use Illuminate\Support\Collection;

class SaleCartAggregator
{
    /**
     * Aggregate cart rows by product and tax combination.
     *
     * @param  \Illuminate\Support\Collection|array  $cartItems
     * @return array<int, array<string, mixed>>
     */
    public static function aggregate($cartItems): array
    {
        if ($cartItems instanceof Collection) {
            $cartItems = $cartItems->all();
        }

        $aggregated = [];

        foreach ($cartItems as $item) {
            $options = is_array($item->options) ? $item->options : $item->options->toArray();

            $productId = $options['product_id'] ?? $item->id;
            $taxId = $options['product_tax'] ?? null;
            $key = $productId . ':' . ($taxId ?? 'null');

            if (! isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'product_id' => $productId,
                    'product_name' => $item->name,
                    'product_code' => $options['code'] ?? null,
                    'product_discount_type' => $options['product_discount_type'] ?? null,
                    'tax_id' => $taxId,
                    'quantity' => 0,
                    'unit_price_total' => 0.0,
                    'price_total' => 0.0,
                    'product_discount_amount' => 0.0,
                    'sub_total' => 0.0,
                    'sub_total_before_tax' => 0.0,
                    'product_tax_amount' => 0.0,
                    'bundle_items' => [],
                ];
            }

            $qty = (float) $item->qty;
            $subTotal = (float) ($options['sub_total'] ?? ($item->price * $qty));
            $subTotalBeforeTax = (float) ($options['sub_total_before_tax'] ?? $subTotal);
            $unitPrice = (float) ($options['unit_price'] ?? $item->price);
            $price = (float) $item->price;
            $discountAmount = (float) ($options['product_discount'] ?? 0);

            $aggregated[$key]['quantity'] += $qty;
            $aggregated[$key]['unit_price_total'] += $unitPrice * $qty;
            $aggregated[$key]['price_total'] += $price * $qty;
            $aggregated[$key]['product_discount_amount'] += $discountAmount;
            $aggregated[$key]['sub_total'] += $subTotal;
            $aggregated[$key]['sub_total_before_tax'] += $subTotalBeforeTax;
            $aggregated[$key]['product_tax_amount'] += $subTotal - $subTotalBeforeTax;

            $bundleItems = $options['bundle_items'] ?? [];
            if (is_iterable($bundleItems)) {
                foreach ($bundleItems as $bundleItem) {
                    $bundleItem = is_array($bundleItem) ? $bundleItem : (array) $bundleItem;
                    $bundleKey = ($bundleItem['bundle_item_id'] ?? 'null') . ':' . ($bundleItem['product_id'] ?? 'null');

                    if (! isset($aggregated[$key]['bundle_items'][$bundleKey])) {
                        $aggregated[$key]['bundle_items'][$bundleKey] = [
                            'bundle_id' => $bundleItem['bundle_id'] ?? null,
                            'bundle_item_id' => $bundleItem['bundle_item_id'] ?? null,
                            'product_id' => $bundleItem['product_id'] ?? null,
                            'name' => $bundleItem['name'] ?? null,
                            'tax_id' => $bundleItem['tax_id'] ?? null,
                            'quantity' => 0.0,
                            'sub_total' => 0.0,
                            'price_total' => 0.0,
                        ];
                    }

                    $bundleQty = (float) ($bundleItem['quantity'] ?? 0);
                    $bundleSubTotal = array_key_exists('sub_total', $bundleItem)
                        ? (float) $bundleItem['sub_total']
                        : null;
                    $bundlePrice = (float) ($bundleItem['price'] ?? 0);

                    $aggregated[$key]['bundle_items'][$bundleKey]['quantity'] += $bundleQty;
                    $aggregated[$key]['bundle_items'][$bundleKey]['sub_total'] += $bundleSubTotal ?? ($bundlePrice * $bundleQty);
                    $aggregated[$key]['bundle_items'][$bundleKey]['price_total'] += $bundlePrice * $bundleQty;
                }
            }
        }

        foreach ($aggregated as &$entry) {
            $entry['unit_price'] = $entry['quantity'] > 0 ? $entry['unit_price_total'] / $entry['quantity'] : 0;
            $entry['price'] = $entry['quantity'] > 0 ? $entry['price_total'] / $entry['quantity'] : 0;

            $entry['bundle_items'] = array_map(function ($bundle) {
                $quantity = $bundle['quantity'];
                $price = $quantity > 0 ? $bundle['price_total'] / $quantity : 0;

                return [
                    'bundle_id' => $bundle['bundle_id'],
                    'bundle_item_id' => $bundle['bundle_item_id'],
                    'product_id' => $bundle['product_id'],
                    'name' => $bundle['name'],
                    'tax_id' => $bundle['tax_id'] ?? null,
                    'price' => $price,
                    'quantity' => $quantity,
                    'sub_total' => $bundle['sub_total'],
                ];
            }, $entry['bundle_items']);

            unset($entry['unit_price_total'], $entry['price_total']);
        }

        return array_values($aggregated);
    }
}

