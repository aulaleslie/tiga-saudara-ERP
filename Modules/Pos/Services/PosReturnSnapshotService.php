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
                $lines = array_merge($lines, $this->buildLineSnapshots($transaction, $cs, $detail));
            }
        }
        
        $snapshot['lines'] = array_values(array_filter($lines, fn($l) => !($l['is_zero_qty_component'] ?? false)));
        $snapshot['hash'] = $this->hash($snapshot);

        return $snapshot;
    }

    protected function buildLineSnapshots(PosTransaction $transaction, $checkoutSale, $detail): array
    {
        $dispatchDetailId = \Modules\Sale\Entities\DispatchDetail::where('sale_id', $detail->sale_id)
            ->where('product_id', $detail->product_id)
            ->value('id');

        $isBundle = $detail->bundleItems->isNotEmpty();
        $originalQuantity = (float) $detail->quantity;

        // Bundle details store price=0; derive per-bundle unit price from the checkout_sale subtotal.
        $unitPrice = (float) $detail->unit_price;
        $lineTotal = (float) $detail->sub_total;
        if ($isBundle && $unitPrice == 0.0 && $originalQuantity > 0) {
            $checkoutSubtotal = (float) $checkoutSale->subtotal;
            $unitPrice = $checkoutSubtotal / $originalQuantity;
            $lineTotal = $checkoutSubtotal;
        }

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

        $serialNumbers = [];
        if (!empty($snIds)) {
            $serialNumbers = \Modules\Product\Entities\ProductSerialNumber::whereIn('id', $snIds)
                ->get(['id', 'serial_number'])
                ->map(fn($sn) => ['id' => $sn->id, 'serial_number' => $sn->serial_number])
                ->toArray();
        }

        $isTracked = (bool) ($detail->product->serial_number_required ?? false);
        $results = [];

        $bundleItems = $detail->bundleItems->map(function ($bi) {
            return [
                'product_id' => $bi->product_id,
                'product_name' => $bi->product->product_name,
                'product_code' => $bi->product->product_code,
                'quantity' => (float) $bi->quantity,
            ];
        })->toArray();

        // Check if zero-quantity split component (Task 2.8)
        $isZeroQtyComponent = false;
        if ($originalQuantity == 0.0 && $unitPrice == 0.0) {
            $isZeroQtyComponent = true;
        }

        if ($isTracked && count($serialNumbers) > 0) {
            // Build one row per serial number
            foreach ($serialNumbers as $sn) {
                // Find pos_transaction_line_id
                $ptlId = \Modules\Pos\Entities\PosTransactionLineSerial::where('serial_number', $sn['serial_number'])
                    ->whereHas('line', fn($q) => $q->where('pos_transaction_id', $transaction->id))
                    ->value('pos_transaction_line_id');

                $ptl = $ptlId ? \Modules\Pos\Entities\PosTransactionLine::find($ptlId) : null;
                
                $serialIsBundle = $isBundle;
                $serialBundleItems = $bundleItems;
                $serialUnitPrice = $unitPrice;

                // Task 2.7: POS transaction line metadata is the source of truth for bundle context.
                // Accept bundle_id (real POS checkout), is_bundle (test/manual), or non-empty bundle_items.
                $ptlIsBundle = $ptl && (
                    !empty($ptl->line_meta['bundle_id']) ||
                    !empty($ptl->line_meta['is_bundle']) ||
                    !empty($ptl->line_meta['bundle_items'])
                );

                if ($ptlIsBundle) {
                    $serialIsBundle = true;
                    $serialBundleItems = [];
                    $bundleMetaItems = $ptl->line_meta['bundle_items'] ?? [];
                    
                    foreach ($bundleMetaItems as $item) {
                        $componentProductId = $item['product_id'] ?? null;
                        
                        // Task 2.10: Align to source allocation context
                        $allocation = $this->findComponentAllocation($checkoutSale, $componentProductId);
                        
                        $serialBundleItems[] = [
                            'product_id' => $componentProductId,
                            'product_name' => $item['name'] ?? $item['product_name'] ?? 'Unknown Component',
                            'product_code' => null,
                            'quantity' => (float)($item['quantity'] ?? $item['qty'] ?? 0),
                            'sale_detail_id' => $allocation ? $allocation->id : null,
                            'unit_price' => $allocation ? (float)$allocation->unit_price : 0,
                        ];
                    }
                    
                    // Task 3.9: Use full original POS unit price for bundled serials
                    if ($ptl->unit_price > 0) {
                        $serialUnitPrice = (float) $ptl->unit_price;
                    }
                }

                // Returned qty is either 0 or 1 for a serial
                $returnedQty = (float) PosReturnLine::whereHas('posReturn', function ($q) {
                    $q->consumesReturnQuantity();
                })->where('sale_detail_id', $detail->id)->where('returned_serial_id', $sn['id'])->sum('quantity');

                $results[] = [
                    'checkout_sale_id' => $checkoutSale->id,
                    'sale_id' => $detail->sale_id,
                    'sale_detail_id' => $detail->id,
                    'dispatch_detail_id' => $dispatchDetailId,
                    'pos_transaction_line_id' => $ptlId,
                    'product_id' => $detail->product_id,
                    'product_name' => $detail->product->product_name,
                    'product_code' => $detail->product->product_code,
                    'original_quantity' => 1.0,
                    'returned_quantity' => $returnedQty,
                    'returnable_quantity' => max(0, 1.0 - $returnedQty),
                    'unit_price' => $serialUnitPrice,
                    'line_total' => $serialUnitPrice, // For 1 qty, line total is unit price
                    'tax_id' => $detail->tax_id,
                    'is_tracked' => true,
                    'serial_number_ids' => [$sn['id']],
                    'serial_numbers' => [$sn],
                    // Task 2.7: bundle context from PTL metadata as source of truth
                    'is_bundle' => $serialIsBundle,
                    'bundle_id' => $ptlIsBundle ? ($ptl->line_meta['bundle_id'] ?? null) : null,
                    'bundle_name' => $ptlIsBundle ? ($ptl->line_meta['bundle_name'] ?? null) : null,
                    'bundle_items' => $serialBundleItems,
                    'is_zero_qty_component' => false,
                ];
            }
        } else {
            // Non-serial tracked, one row for the whole sale detail
            $returnedQty = (float) PosReturnLine::whereHas('posReturn', function ($q) {
                $q->consumesReturnQuantity();
            })->where('sale_detail_id', $detail->id)->sum('quantity');

            $results[] = [
                'checkout_sale_id' => $checkoutSale->id,
                'sale_id' => $detail->sale_id,
                'sale_detail_id' => $detail->id,
                'dispatch_detail_id' => $dispatchDetailId,
                'pos_transaction_line_id' => null, // Requirement only mandates it for serialized lines
                'product_id' => $detail->product_id,
                'product_name' => $detail->product->product_name,
                'product_code' => $detail->product->product_code,
                'original_quantity' => $originalQuantity,
                'returned_quantity' => $returnedQty,
                'returnable_quantity' => max(0, $originalQuantity - $returnedQty),
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'tax_id' => $detail->tax_id,
                'is_tracked' => false,
                'serial_number_ids' => [],
                'serial_numbers' => [],
                'is_bundle' => $isBundle,
                'bundle_items' => $bundleItems,
                'is_zero_qty_component' => $isZeroQtyComponent,
            ];
        }

        return $results;
    }

    protected function findComponentAllocation($checkoutSale, $productId): ?SaleDetails
    {
        if (!$checkoutSale || !$checkoutSale->sale) return null;
        
        return $checkoutSale->sale->saleDetails->first(function ($sd) use ($productId) {
            return $sd->product_id == $productId && $sd->unit_price == 0;
        });
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
        
        if (isset($data['lines']) && is_array($data['lines'])) {
            usort($data['lines'], function ($a, $b) {
                // Task 2.9: Use POS line ID + Serial ID for keying/sorting where possible
                $aKey = ($a['pos_transaction_line_id'] ?? $a['sale_detail_id'] ?? 0) . '-' . 
                        (!empty($a['serial_number_ids']) ? $a['serial_number_ids'][0] : 0);
                $bKey = ($b['pos_transaction_line_id'] ?? $b['sale_detail_id'] ?? 0) . '-' . 
                        (!empty($b['serial_number_ids']) ? $b['serial_number_ids'][0] : 0);
                return strcmp($aKey, $bKey);
            });
        }
        
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
}
