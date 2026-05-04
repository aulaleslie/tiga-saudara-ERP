<?php

namespace Modules\Pos\Services;

use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Sale\Entities\SaleDetails;

class PosReturnSnapshotService
{
    /**
     * Build source snapshot for a POS transaction.
     *
     * @param int $posTransactionId
     * @return array
     */
    public function build(int $posTransactionId): array
    {
        $transaction = PosTransaction::with([
            'customer',
            'completedCheckout.checkoutSales.sale.saleDetails.product',
            'completedCheckout.checkoutSales.sale.saleDetails.bundleItems.product',
            'completedCheckout.payments.paymentMethod'
        ])->findOrFail($posTransactionId);

        $checkout = $transaction->completedCheckout;

        $snapshot = [
            'header' => [
                'transaction_id' => $transaction->id,
                'transaction_code' => $transaction->code,
                'checkout_id' => $checkout->id,
                'receipt_number' => $checkout->receipt_number,
                'customer_id' => $transaction->customer_id,
                'customer_name' => optional($transaction->customer)->customer_name,
                'date' => $transaction->created_at->toIso8601String(),
                'grand_total' => (float) $checkout->grand_total,
            ],
            'owners' => $checkout->checkoutSales->map(function ($cs) {
                return [
                    'checkout_sale_id' => $cs->id,
                    'split_key' => $cs->split_key,
                    'source_setting_id' => $cs->source_setting_id,
                    'source_location_id' => $cs->source_location_id,
                    'sale_id' => $cs->sale_id,
                    'grand_total' => (float) $cs->grand_total,
                ];
            })->toArray(),
            'payments' => $checkout->payments->map(function ($p) {
                return [
                    'method_name' => $p->paymentMethod->name ?? 'Unknown',
                    'amount' => (float) $p->amount,
                ];
            })->toArray(),
            'lines' => []
        ];

        $lines = [];
        foreach ($checkout->checkoutSales as $cs) {
            $sale = $cs->sale;
            if (!$sale) continue;

            foreach ($sale->saleDetails as $detail) {
                $lines[] = $this->buildLineSnapshot($cs, $detail);
            }
        }

        // Consolidate bundle lines: if multiple lines have the same product_id
        // and all have bundle_items, merge their bundle_items into one line
        $snapshot['lines'] = $this->consolidateBundleLines($lines);

        $snapshot['hash'] = $this->hash($snapshot);

        return $snapshot;
    }

    protected function buildLineSnapshot($checkoutSale, $detail): array
    {
        $dispatchDetailId = \Modules\Sale\Entities\DispatchDetail::where('sale_id', $detail->sale_id)
            ->where('product_id', $detail->product_id)
            ->value('id');

        $returnedQty = (float) PosReturnLine::whereHas('posReturn', function ($q) {
            $q->active();
        })->where('sale_detail_id', $detail->id)->sum('quantity');

        $isBundle = $detail->bundleItems->isNotEmpty();
        $originalQuantity = (float) $detail->quantity;

        // For bundles with qty=0 in the detail but with bundle items present,
        // infer that qty should be 1 (one bundle was sold with its components).
        if ($isBundle && $originalQuantity === 0.0) {
            $originalQuantity = 1.0;
        }

        // Bundle details store price=0; derive per-bundle unit price from the checkout_sale subtotal.
        $unitPrice = (float) $detail->unit_price;
        $lineTotal = (float) $detail->sub_total;
        if ($isBundle && $unitPrice === 0.0 && $originalQuantity > 0) {
            $checkoutSubtotal = (float) $checkoutSale->subtotal;
            $unitPrice = $checkoutSubtotal / $originalQuantity;
            $lineTotal = $checkoutSubtotal;
        }

        $line = [
            'checkout_sale_id' => $checkoutSale->id,
            'sale_id' => $detail->sale_id,
            'sale_detail_id' => $detail->id,
            'dispatch_detail_id' => $dispatchDetailId,
            'product_id' => $detail->product_id,
            'product_name' => $detail->product->product_name,
            'product_code' => $detail->product->product_code,
            'original_quantity' => $originalQuantity,
            'returned_quantity' => $returnedQty,
            'returnable_quantity' => max(0, $originalQuantity - $returnedQty),
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'tax_id' => $detail->tax_id,
            'serial_number_ids' => $detail->serial_number_ids ?? [],
            'is_bundle' => $isBundle,
            'bundle_items' => $detail->bundleItems->map(function ($bi) {
                return [
                    'product_id' => $bi->product_id,
                    'product_name' => $bi->product->product_name,
                    'product_code' => $bi->product->product_code,
                    'quantity_per_bundle' => (float) $bi->quantity,
                ];
            })->toArray(),
        ];

        return $line;
    }

    /**
     * Generate canonical hash for a snapshot.
     *
     * @param array $snapshot
     * @return string
     */
    public function hash(array $snapshot): string
    {
        // Remove hash from snapshot before hashing if it exists
        $data = $snapshot;
        unset($data['hash']);
        
        // Sort keys recursively for canonicality
        $this->ksortRecursive($data);
        
        return md5(json_encode($data));
    }

    protected function ksortRecursive(&$array): void
    {
        if (is_array($array)) {
            ksort($array);
            foreach ($array as &$value) {
                $this->ksortRecursive($value);
            }
        }
    }

    /**
     * Consolidate bundle lines with the same product_id into a single line.
     * This handles cases where POS splits a single bundled product across multiple sales.
     */
    protected function consolidateBundleLines(array $lines): array
    {
        $consolidated = [];
        $bundlesByProduct = [];
        $nonBundleLines = [];

        foreach ($lines as $line) {
            if (!$line['is_bundle']) {
                $nonBundleLines[] = $line;
            } else {
                $productId = $line['product_id'];

                if (!isset($bundlesByProduct[$productId])) {
                    $bundlesByProduct[$productId] = $line;
                } else {
                    $existing = $bundlesByProduct[$productId];

                    $bundlesByProduct[$productId]['original_quantity'] += $line['original_quantity'];
                    $bundlesByProduct[$productId]['returned_quantity'] += $line['returned_quantity'];
                    $bundlesByProduct[$productId]['returnable_quantity'] += $line['returnable_quantity'];
                    $bundlesByProduct[$productId]['line_total'] += $line['line_total'];

                    // Merge bundle_items, avoiding duplicates by product_id
                    $existingItemIds = collect($existing['bundle_items'])->pluck('product_id')->toArray();
                    foreach ($line['bundle_items'] as $newItem) {
                        if (!in_array($newItem['product_id'], $existingItemIds)) {
                            $bundlesByProduct[$productId]['bundle_items'][] = $newItem;
                            $existingItemIds[] = $newItem['product_id'];
                        }
                    }
                }
            }
        }

        // When a non-bundle line shares the same product_id as a bundle line, merge its
        // line_total into the bundle row (the POS receipt displays them as one combined line).
        // Otherwise keep the non-bundle line as its own row.
        foreach ($nonBundleLines as $line) {
            if (isset($bundlesByProduct[$line['product_id']])) {
                $bundlesByProduct[$line['product_id']]['line_total'] += $line['line_total'];
            } else {
                $consolidated[] = $line;
            }
        }

        // Add consolidated bundles to output, normalizing quantity_per_bundle and unit_price.
        // bundle_item.quantity stores total qty sold across all bundle instances,
        // so divide by total bundle count to get the per-bundle quantity.
        foreach ($bundlesByProduct as &$line) {
            $totalBundles = $line['original_quantity'];
            if ($totalBundles > 0) {
                foreach ($line['bundle_items'] as &$bundleItem) {
                    $bundleItem['quantity_per_bundle'] = $bundleItem['quantity_per_bundle'] / $totalBundles;
                }
                unset($bundleItem);
                // Recompute per-bundle unit price from the fully-merged line_total
                $line['unit_price'] = $line['line_total'] / $totalBundles;
            }
            $consolidated[] = $line;
        }
        unset($line);

        return $consolidated;
    }
}
