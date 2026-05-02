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
            'completedCheckout.payments'
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
                    'method_name' => $p->method_name,
                    'amount' => (float) $p->amount,
                ];
            })->toArray(),
            'lines' => []
        ];

        foreach ($checkout->checkoutSales as $cs) {
            $sale = $cs->sale;
            if (!$sale) continue;

            foreach ($sale->saleDetails as $detail) {
                $snapshot['lines'][] = $this->buildLineSnapshot($cs, $detail);
            }
        }

        $snapshot['hash'] = $this->hash($snapshot);

        return $snapshot;
    }

    protected function buildLineSnapshot($checkoutSale, $detail): array
    {
        $returnedQty = (float) PosReturnLine::whereHas('posReturn', function ($q) {
            $q->active();
        })->where('sale_detail_id', $detail->id)->sum('quantity');

        $line = [
            'checkout_sale_id' => $checkoutSale->id,
            'sale_id' => $detail->sale_id,
            'sale_detail_id' => $detail->id,
            'product_id' => $detail->product_id,
            'product_name' => $detail->product->product_name,
            'product_code' => $detail->product->product_code,
            'original_quantity' => (float) $detail->quantity,
            'returned_quantity' => $returnedQty,
            'returnable_quantity' => max(0, (float) $detail->quantity - $returnedQty),
            'unit_price' => (float) $detail->unit_price,
            'line_total' => (float) $detail->sub_total,
            'tax_id' => $detail->tax_id,
            'serial_number_ids' => $detail->serial_number_ids ?? [],
            'is_bundle' => $detail->bundleItems->isNotEmpty(),
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
}
