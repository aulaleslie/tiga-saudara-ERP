<?php

namespace Tests\Feature\SalesReturn;

use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement as Settlement;

/** Standard sales with explicit financials (lines, discount, shipping, payments) for group 6 tests. Requires BuildsStandardFlow. */
trait BuildsFinancialFlow
{
    /**
     * A relationally valid standard sale with explicit financials.
     *
     * @param  array<int, array{qty: int, sub: float}>  $lines
     * @return array{sale: Sale, lines: array<int, array>}
     */
    protected function sale(array $lines, float $discount = 0, float $shipping = 0, float $paid = 0, bool $serial = false, ?float $headerPaid = null, float $discountPercentage = 0): array
    {
        $linesTotal = array_sum(array_column($lines, 'sub'));
        // Percentage mode is stored the way SaleNormalizer stores it: discount_percentage > 0, discount_amount = 0.
        $effectiveDiscount = $discountPercentage > 0 ? round($linesTotal * $discountPercentage / 100, 2) : $discount;
        $total = $linesTotal - $effectiveDiscount + $shipping;
        $sale = Sale::create([
            'setting_id' => 1, 'date' => now(), 'reference' => 'SL-' . uniqid(), 'customer_id' => 1, 'customer_name' => 'Test Customer',
            'payment_method' => 'Cash', 'discount_amount' => $discountPercentage > 0 ? 0 : $discount, 'discount_percentage' => $discountPercentage, 'shipping_amount' => $shipping, 'total_amount' => $total,
            'paid_amount' => $headerPaid ?? $paid, 'due_amount' => $total - ($headerPaid ?? $paid),
            'status' => Sale::STATUS_DISPATCHED, 'payment_status' => $paid >= $total ? 'Paid' : 'Partial',
        ]);
        if ($paid > 0) {
            SalePayment::create(['sale_id' => $sale->id, 'amount' => $paid, 'date' => now()->toDateString(), 'reference' => 'PAY-' . $sale->id, 'payment_method' => 'Cash']);
        }
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'dispatch_date' => now(), 'status' => Dispatch::STATUS_APPROVED]);

        $out = [];
        foreach ($lines as $line) {
            $product = $this->makeProduct($serial);
            $detail = SaleDetails::create([
                'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->product_name, 'product_code' => $product->product_code,
                'quantity' => $line['qty'], 'price' => 1, 'unit_price' => 1, 'sub_total' => $line['sub'],
                'product_discount_amount' => 0, 'product_tax_amount' => 0,
            ]);
            $dd = DispatchDetail::create(['dispatch_id' => $dispatch->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'dispatched_quantity' => $line['qty'], 'location_id' => 1]);
            $serials = [];
            if ($serial) {
                for ($i = 1; $i <= $line['qty']; $i++) {
                    $serials[] = ProductSerialNumber::create(['product_id' => $product->id, 'dispatch_detail_id' => $dd->id, 'serial_number' => 'FS-' . $product->id . '-' . $i, 'location_id' => 1, 'status' => ProductSerialNumber::STATUS_SOLD]);
                }
            }
            $out[] = ['sale' => $sale, 'product' => $product, 'saleDetail' => $detail, 'dispatchDetail' => $dd, 'serials' => $serials];
        }

        return ['sale' => $sale, 'lines' => $out];
    }

    protected function returnOf(array $flow, int $quantity, array $serialIds = []): SaleReturn
    {
        // A realistic return: list price per unit as the claim service records it (sale line sub_total / quantity),
        // header total = quantity x list price. Headers are never rewritten by tests.
        $unit = (float) $flow['saleDetail']->sub_total / max(1, (int) $flow['saleDetail']->quantity);

        return $this->makeReturnFor($flow, $quantity, [
            'status' => 'Awaiting Settlement', 'approval_status' => 'approved', 'received_at' => now(),
        ], $serialIds, $unit);
    }

    protected function cash(SaleReturn $return, ?int $serialId = null): Settlement
    {
        return $this->settlementFor($return, Settlement::STATUS_SUBMITTED, 'CASH_REFUND', $serialId);
    }
}
