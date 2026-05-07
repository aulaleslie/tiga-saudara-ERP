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
        
        $snapshot['lines'] = $lines;
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
        if ($isBundle && $unitPrice === 0.0 && $originalQuantity > 0) {
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

        if ($isTracked && count($serialNumbers) > 0) {
            // Build one row per serial number
            foreach ($serialNumbers as $sn) {
                // Find pos_transaction_line_id
                $ptlId = \Modules\Pos\Entities\PosTransactionLineSerial::where('serial_number', $sn['serial_number'])
                    ->whereHas('line', fn($q) => $q->where('pos_transaction_id', $transaction->id))
                    ->value('pos_transaction_line_id');

                // Returned qty is either 0 or 1 for a serial
                $returnedQty = (float) PosReturnLine::whereHas('posReturn', function ($q) {
                    $q->active();
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
                    'unit_price' => $unitPrice,
                    'line_total' => $unitPrice, // For 1 qty, line total is unit price
                    'tax_id' => $detail->tax_id,
                    'is_tracked' => true,
                    'serial_number_ids' => [$sn['id']],
                    'serial_numbers' => [$sn],
                    'is_bundle' => $isBundle,
                    'bundle_items' => $bundleItems,
                ];
            }
        } else {
            // Non-serial tracked, one row for the whole sale detail
            $returnedQty = (float) PosReturnLine::whereHas('posReturn', function ($q) {
                $q->active();
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
            ];
        }

        return $results;
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
                $aKey = ($a['checkout_sale_id'] ?? 0) . '-' . 
                        ($a['sale_detail_id'] ?? 0) . '-' . 
                        (!empty($a['serial_number_ids']) ? $a['serial_number_ids'][0] : 0);
                $bKey = ($b['checkout_sale_id'] ?? 0) . '-' . 
                        ($b['sale_detail_id'] ?? 0) . '-' . 
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
