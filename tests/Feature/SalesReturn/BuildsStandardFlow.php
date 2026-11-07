<?php

namespace Tests\Feature\SalesReturn;

use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement;

/**
 * A complete, relationally valid standard flow (setting, customer, location, product, sale with
 * priced line, APPROVED dispatch, serials, return, detail). Foreign keys stay ENABLED.
 */
trait BuildsStandardFlow
{
    use SeedsReferenceData;

    protected function seedBase(): void
    {
        $this->seedSetting(1);
        $this->seedSetting(2);
        $this->seedCustomer(1, 1);
        $this->seedLocation(1, 1);
        session(['setting_id' => 1]);
    }

    protected function makeProduct(bool $serial = false, string $code = 'P-GUARD'): Product
    {
        return Product::create([
            'setting_id' => 1,
            'product_name' => 'Guard Product',
            'product_code' => $code . '-' . uniqid(),
            'product_unit' => 'pc',
            'product_price' => 1000,
            'product_cost' => 500,
            'product_stock_alert' => 1,
            'product_quantity' => 0,
            'serial_number_required' => $serial,
        ]);
    }

    /** @return array{sale: Sale, product: Product, saleDetail: SaleDetails, dispatchDetail: DispatchDetail, serials: array<int, ProductSerialNumber>} */
    protected function makeDispatchedSale(int $quantity = 5, bool $serial = false, int $settingId = 1): array
    {
        $product = $this->makeProduct($serial);
        $sale = Sale::create([
            'setting_id' => $settingId, 'date' => now(), 'reference' => 'SL-' . uniqid(),
            'customer_id' => 1, 'customer_name' => 'Test Customer', 'payment_method' => 'Cash',
            'total_amount' => 1000 * $quantity, 'paid_amount' => 1000 * $quantity, 'due_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED, 'payment_status' => 'Paid',
        ]);
        // Financial evidence must agree with the header (paid_amount is explained by an active payment).
        if (1000 * $quantity > 0) {
            \Modules\Sale\Entities\SalePayment::create([
                'sale_id' => $sale->id, 'amount' => 1000 * $quantity, 'date' => now()->toDateString(),
                'reference' => 'PAY-' . $sale->id, 'payment_method' => 'Cash',
            ]);
        }
        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->product_name,
            'product_code' => $product->product_code, 'quantity' => $quantity, 'price' => 1000, 'unit_price' => 1000,
            'sub_total' => 1000 * $quantity, 'product_discount_amount' => 0, 'product_tax_amount' => 0,
        ]);
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'dispatch_date' => now(), 'status' => Dispatch::STATUS_APPROVED]);
        $dispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id, 'sale_id' => $sale->id, 'product_id' => $product->id,
            'dispatched_quantity' => $quantity, 'location_id' => 1,
        ]);

        $serials = [];
        if ($serial) {
            for ($i = 1; $i <= $quantity; $i++) {
                $serials[] = ProductSerialNumber::create([
                    'product_id' => $product->id, 'dispatch_detail_id' => $dispatchDetail->id,
                    'serial_number' => 'SN-' . $product->id . '-' . $i, 'location_id' => 1,
                    'status' => ProductSerialNumber::STATUS_SOLD,
                ]);
            }
        }

        return compact('sale', 'product', 'saleDetail', 'dispatchDetail', 'serials');
    }

    /** @param array<string, mixed> $overrides */
    protected function makeReturnFor(array $flow, int $quantity, array $overrides = [], array $serialIds = [], int|float $unitPrice = 1000): SaleReturn
    {
        $return = SaleReturn::create(array_merge([
            'date' => now(), 'sale_id' => $flow['sale']->id, 'sale_reference' => $flow['sale']->reference,
            'customer_id' => 1, 'customer_name' => 'Test Customer', 'setting_id' => $flow['sale']->setting_id,
            'location_id' => 1, 'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0,
            'discount_amount' => 0, 'shipping_amount' => 0, 'total_amount' => $unitPrice * $quantity,
            'paid_amount' => 0, 'due_amount' => $unitPrice * $quantity, 'status' => 'Pending Approval',
            'approval_status' => 'pending', 'payment_status' => 'Unpaid', 'payment_method' => 'Pending',
        ], $overrides));

        SaleReturnDetail::create([
            'sale_return_id' => $return->id, 'sale_detail_id' => $flow['saleDetail']->id,
            'dispatch_detail_id' => $flow['dispatchDetail']->id, 'location_id' => 1,
            'product_id' => $flow['product']->id, 'product_name' => $flow['product']->product_name,
            'product_code' => $flow['product']->product_code, 'quantity' => $quantity,
            'unit_price' => $unitPrice, 'price' => $unitPrice, 'sub_total' => $unitPrice * $quantity,
            'product_discount_amount' => 0, 'product_tax_amount' => 0, 'serial_number_ids' => $serialIds,
        ]);

        return $return->refresh();
    }

    protected function awaitingSettlement(array $flow, int $quantity = 1, array $serialIds = []): SaleReturn
    {
        return $this->makeReturnFor($flow, $quantity, [
            'status' => 'Awaiting Settlement', 'approval_status' => 'approved', 'received_at' => now(),
        ], $serialIds);
    }

    protected function settlementFor(SaleReturn $return, string $status, string $method = 'REPAIR', ?int $serialId = null): SaleReturnItemSettlement
    {
        $row = SaleReturnItemSettlement::create([
            'sale_return_id' => $return->id,
            'sale_return_detail_id' => $return->saleReturnDetails()->value('id'),
            'product_serial_number_id' => $serialId,
            'method' => $method, 'nominal' => 1000, 'status' => $status,
        ]);

        // A submitted cash return carries the financial review the form would have recorded.
        if ($method === 'CASH_REFUND' && $status === SaleReturnItemSettlement::STATUS_SUBMITTED) {
            $fresh = $row->fresh();
            $derived = app(\App\Support\SalesReturn\StandardFinancialService::class)->resolve($fresh);
            $row->forceFill([
                'nominal' => $derived['value'], 'reviewed_value' => $derived['value'], 'reviewed_fingerprint' => $derived['fingerprint'],
            ])->save();
        }

        return $row;
    }
}
