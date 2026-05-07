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
        $allSaleDetails = collect();
        $checkoutSalesBySaleId = $checkout->checkoutSales->keyBy('sale_id');
        
        foreach ($checkout->checkoutSales as $cs) {
            $sale = $cs->sale;
            if (!$sale) continue;
            
            foreach ($sale->saleDetails as $detail) {
                $allSaleDetails->put($detail->id, $detail);
            }
        }

        foreach ($allSaleDetails as $detailId => $detail) {
            $cs = $checkoutSalesBySaleId->get($detail->sale_id);
            if ($cs) {
                $lines[] = $this->buildLineSnapshot($cs, $detail);
            }
        }
        

        // Consolidate bundle lines: if multiple lines have the same product_id
        // and all have bundle_items, merge their bundle_items into one line
        $snapshot['lines'] = $this->consolidateLinesByProduct($lines);

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


        // Bundle details store price=0; derive per-bundle unit price from the checkout_sale subtotal.
        $unitPrice = (float) $detail->unit_price;
        $lineTotal = (float) $detail->sub_total;
        if ($isBundle && $unitPrice === 0.0 && $originalQuantity > 0) {
            $checkoutSubtotal = (float) $checkoutSale->subtotal;
            $unitPrice = $checkoutSubtotal / $originalQuantity;
            $lineTotal = $checkoutSubtotal;
        }

        $serialNumbers = [];
        $snIds = $detail->serial_number_ids ?? [];
        
        // If no SN IDs in detail, check dispatch detail
        if (empty($snIds) && $dispatchDetailId) {
            $dispatchDetail = \Modules\Sale\Entities\DispatchDetail::find($dispatchDetailId);
            if ($dispatchDetail && !empty($dispatchDetail->serial_numbers)) {
                $snIds = is_array($dispatchDetail->serial_numbers) 
                    ? $dispatchDetail->serial_numbers 
                    : explode(',', $dispatchDetail->serial_numbers);
            }
        }

        if (!empty($snIds)) {
            $serialNumbers = \Modules\Product\Entities\ProductSerialNumber::whereIn('id', $snIds)
                ->get(['id', 'serial_number'])
                ->map(fn($sn) => ['id' => $sn->id, 'serial_number' => $sn->serial_number])
                ->toArray();
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
            'is_tracked' => (bool) ($detail->product->serial_number_required ?? false),
            'serial_number_ids' => $detail->serial_number_ids ?? [],
            'serial_numbers' => $serialNumbers, // Added full serial info
            'is_bundle' => $isBundle,
            'bundle_items' => $detail->bundleItems->map(function ($bi) use ($originalQuantity) {
                return [
                    'product_id' => $bi->product_id,
                    'product_name' => $bi->product->product_name,
                    'product_code' => $bi->product->product_code,
                    'quantity' => (float) $bi->quantity,
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
     * Consolidate all lines with the same product_id into a single line.
     * This handles cases where POS splits products across multiple sales or bundle fragments.
     */
    protected function consolidateLinesByProduct(array $lines): array
    {
        $consolidated = [];
        $byProduct = [];

        foreach ($lines as $line) {
            $productId = $line['product_id'];

            if (!isset($byProduct[$productId])) {
                $byProduct[$productId] = $line;
            } else {
                $byProduct[$productId]['original_quantity'] += $line['original_quantity'];
                $byProduct[$productId]['returned_quantity'] += $line['returned_quantity'];
                $byProduct[$productId]['returnable_quantity'] += $line['returnable_quantity'];
                $byProduct[$productId]['line_total'] += $line['line_total'];
                
                // Merge serial numbers
                $byProduct[$productId]['serial_number_ids'] = array_values(array_unique(array_merge(
                    $byProduct[$productId]['serial_number_ids'], 
                    $line['serial_number_ids']
                )));
                
                $existingSnIds = collect($byProduct[$productId]['serial_numbers'])->pluck('id')->toArray();
                foreach ($line['serial_numbers'] as $sn) {
                    if (!in_array($sn['id'], $existingSnIds)) {
                        $byProduct[$productId]['serial_numbers'][] = $sn;
                        $existingSnIds[] = $sn['id'];
                    }
                }

                // Merge bundle items
                if ($line['is_bundle'] || $byProduct[$productId]['is_bundle']) {
                    $byProduct[$productId]['is_bundle'] = true;
                    $existingItems = collect($byProduct[$productId]['bundle_items'])->keyBy('product_id');
                    foreach ($line['bundle_items'] as $newItem) {
                        $pId = $newItem['product_id'];
                        if (!$existingItems->has($pId)) {
                            $existingItems->put($pId, $newItem);
                        } else {
                            // If it exists, we take the one with higher quantity 
                            // to handle cases where some split sales have 0 qty
                            $existingItem = $existingItems->get($pId);
                            if ((float) ($newItem['quantity'] ?? 0) > (float) ($existingItem['quantity'] ?? 0)) {
                                $existingItems->put($pId, $newItem);
                            }
                        }
                    }
                    $byProduct[$productId]['bundle_items'] = $existingItems->values()->toArray();
                }

                // Merge tracked status
                $byProduct[$productId]['is_tracked'] = $byProduct[$productId]['is_tracked'] || $line['is_tracked'];
            }
        }

        // Final normalization
        foreach ($byProduct as &$line) {
            if ($line['original_quantity'] > 0) {
                $line['unit_price'] = $line['line_total'] / $line['original_quantity'];
            }
            
            if ($line['is_bundle'] && $line['original_quantity'] > 0) {
                foreach ($line['bundle_items'] as &$bundleItem) {
                    $bundleItem['quantity_per_bundle'] = (float) ($bundleItem['quantity'] ?? 0) / $line['original_quantity'];
                }
                unset($bundleItem);
            }
            
            $consolidated[] = $line;
        }
        unset($line);

        return $consolidated;
    }
}
