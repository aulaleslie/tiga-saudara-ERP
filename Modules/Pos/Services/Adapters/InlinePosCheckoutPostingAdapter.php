<?php

namespace Modules\Pos\Services\Adapters;

use Modules\People\Entities\Customer;
use Modules\Pos\Services\Contracts\PosCheckoutPostingAdapter;
use Modules\Pos\Services\Exceptions\PosCheckoutValidationException;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\PaymentMethod;

class InlinePosCheckoutPostingAdapter implements PosCheckoutPostingAdapter
{
    public function post(array $context): array
    {
        $settingId = (int) ($context['setting_id'] ?? 0);
        $cashierUserId = (int) ($context['cashier_user_id'] ?? 0);
        $customerId = (int) ($context['customer_id'] ?? 0);
        $sourceLocationId = (int) ($context['source_location_id'] ?? 0);
        $checkoutId = (int) ($context['checkout_id'] ?? 0);
        $payment = is_array($context['payment'] ?? null) ? $context['payment'] : [];
        $cartSnapshot = is_array($context['cart_snapshot'] ?? null) ? $context['cart_snapshot'] : [];
        $allocations = is_array($context['allocations'] ?? null) ? $context['allocations'] : [];
        $lines = is_array($cartSnapshot['lines'] ?? null) ? $cartSnapshot['lines'] : [];
        $totals = is_array($cartSnapshot['totals'] ?? null) ? $cartSnapshot['totals'] : [];

        if ($settingId <= 0 || $cashierUserId <= 0 || $customerId <= 0 || $sourceLocationId <= 0) {
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Checkout posting context is not valid.');
        }

        $customer = Customer::query()
            ->where('setting_id', $settingId)
            ->whereKey($customerId)
            ->first();

        if (! $customer) {
            throw new PosCheckoutValidationException('CUSTOMER_UNRESOLVED', 'Customer could not be resolved for checkout.');
        }

        $methodCode = strtolower((string) ($payment['method_code'] ?? ''));
        $paymentReference = isset($payment['reference']) ? trim((string) $payment['reference']) : null;
        $paymentReference = $paymentReference !== '' ? $paymentReference : null;
        $paymentMethodId = $this->resolvePaymentMethodId($methodCode);

        $grandTotal = round((float) ($totals['grand_total'] ?? 0), 2);
        $taxTotal = round((float) ($totals['tax_total'] ?? 0), 2);
        $discountTotal = round((float) ($totals['discount_total'] ?? 0), 2);

        $sale = Sale::query()->create([
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'customer_name' => (string) ($customer->customer_name ?? ''),
            'tax_id' => null,
            'tax_percentage' => 0,
            'tax_amount' => $taxTotal,
            'discount_percentage' => 0,
            'discount_amount' => $discountTotal,
            'shipping_amount' => 0,
            'total_amount' => $grandTotal,
            'paid_amount' => $grandTotal,
            'due_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Paid',
            'payment_term_id' => PaymentTerm::defaultCodTermId(),
            'note' => 'POS checkout #' . $checkoutId,
            'setting_id' => $settingId,
            'is_tax_included' => false,
            'payment_method' => strtoupper($methodCode),
            'tax_ref_no' => null,
        ]);

        $dispatch = Dispatch::query()->create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
            'approved_by' => $cashierUserId,
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);

        foreach ($lines as $index => $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $qty = (int) ($line['qty'] ?? 0);
            $taxId = isset($line['tax_id']) ? (int) $line['tax_id'] : 0;
            $taxId = $taxId > 0 ? $taxId : null;

            if ($productId <= 0 || $qty <= 0) {
                throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Checkout line is invalid.');
            }

            $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
            if (! $product) {
                throw new PosCheckoutValidationException('STOCK_UNAVAILABLE', 'Product is not available for posting.');
            }

            $lineAllocations = $allocations[$index] ?? [
                [
                    'source_location_id' => $sourceLocationId,
                    'source_setting_id' => $settingId,
                    'allocated_qty' => $qty,
                ]
            ];

            // Validate total allocated matches line qty
            $totalAllocated = array_sum(array_column($lineAllocations, 'allocated_qty'));
            if ((int) $totalAllocated !== $qty) {
                throw new PosCheckoutValidationException('STOCK_UNAVAILABLE', 'Allocated quantity does not match line quantity.');
            }

            $unitPrice = round((float) ($line['unit_price'] ?? 0), 2);
            $lineSubtotal = round((float) ($line['line_subtotal'] ?? ($unitPrice * $qty)), 2);
            $lineTax = round((float) ($line['line_tax_total'] ?? 0), 2);
            $lineTotal = round((float) ($line['line_total'] ?? ($lineSubtotal + $lineTax)), 2);
            $lineDiscount = round(
                (float) ($line['line_discount_amount'] ?? 0) + (float) ($line['bill_discount_amount'] ?? 0),
                2
            );

            SaleDetails::query()->create([
                'sale_id' => $sale->id,
                'product_id' => $productId,
                'product_name' => (string) ($line['product_name'] ?? ''),
                'product_code' => (string) ($line['product_code'] ?? ''),
                'quantity' => $qty,
                'price' => $unitPrice,
                'unit_price' => $unitPrice,
                'sub_total' => $lineTotal,
                'product_discount_amount' => $lineDiscount,
                'product_discount_type' => (string) ($line['line_discount_type'] ?? 'fixed'),
                'product_tax_amount' => $lineTax,
                'tax_id' => $taxId,
                'serial_number_ids' => null,
            ]);

            foreach ($lineAllocations as $chunk) {
                $chunkQty = (int) $chunk['allocated_qty'];
                $chunkLocId = (int) $chunk['source_location_id'];

                $stock = ProductStock::query()
                    ->where('product_id', $productId)
                    ->where('location_id', $chunkLocId)
                    ->lockForUpdate()
                    ->first();

                if (! $stock) {
                    throw new PosCheckoutValidationException('STOCK_UNAVAILABLE', 'Product stock is unavailable at source location.');
                }

                if ((int) $stock->quantity < $chunkQty) {
                    throw new PosCheckoutValidationException('STOCK_UNAVAILABLE', 'Insufficient stock at source location.');
                }

                if ($taxId !== null && (int) $stock->quantity_tax < $chunkQty) {
                    throw new PosCheckoutValidationException('STOCK_UNAVAILABLE', 'Insufficient taxed stock at source location.');
                }

                if ($taxId === null && (int) $stock->quantity_non_tax < $chunkQty) {
                    throw new PosCheckoutValidationException('STOCK_UNAVAILABLE', 'Insufficient non-tax stock at source location.');
                }

                DispatchDetail::query()->create([
                    'dispatch_id' => $dispatch->id,
                    'sale_id' => $sale->id,
                    'tax_id' => $taxId,
                    'product_id' => $productId,
                    'bundle_id' => null,
                    'dispatched_quantity' => $chunkQty,
                    'location_id' => $chunkLocId,
                    'serial_numbers' => null,
                ]);

                $previousProductQty = (int) $product->product_quantity;
                $previousLocationQty = (int) $stock->quantity;

                $stock->quantity = max(0, (int) $stock->quantity - $chunkQty);
                if ($taxId !== null) {
                    $stock->quantity_tax = max(0, (int) $stock->quantity_tax - $chunkQty);
                } else {
                    $stock->quantity_non_tax = max(0, (int) $stock->quantity_non_tax - $chunkQty);
                }
                $stock->save();

                $product->product_quantity = max(0, (int) $product->product_quantity - $chunkQty);
                $product->save();

                $afterProductQty = (int) $product->product_quantity;
                $afterLocationQty = (int) $stock->quantity;

                Transaction::query()->create([
                    'product_id' => $productId,
                    'setting_id' => $settingId,
                    'quantity' => -$chunkQty,
                    'current_quantity' => $afterProductQty,
                    'broken_quantity' => 0,
                    'location_id' => $chunkLocId,
                    'user_id' => $cashierUserId,
                    'reason' => 'POS checkout #' . $checkoutId,
                    'type' => 'DISPATCH',
                    'previous_quantity' => $previousProductQty,
                    'after_quantity' => $afterProductQty,
                    'previous_quantity_at_location' => $previousLocationQty,
                    'after_quantity_at_location' => $afterLocationQty,
                    'quantity_tax' => $taxId !== null ? $chunkQty : 0,
                    'quantity_non_tax' => $taxId !== null ? 0 : $chunkQty,
                    'broken_quantity_tax' => 0,
                    'broken_quantity_non_tax' => 0,
                ]);
            }
        }

        $salePayment = SalePayment::query()->create([
            'sale_id' => $sale->id,
            'amount' => $grandTotal,
            'date' => now()->toDateString(),
            'reference' => $sale->reference,
            'payment_method' => strtoupper($methodCode),
            'note' => $paymentReference,
            'payment_method_id' => $paymentMethodId,
        ]);

        return [
            'sale_id' => (int) $sale->id,
            'dispatch_ids' => [(int) $dispatch->id],
            'sale_payment_id' => (int) $salePayment->id,
            'receipt_number' => (string) $sale->reference,
        ];
    }

    private function resolvePaymentMethodId(string $methodCode): int
    {
        if ($methodCode === 'cash') {
            $cashMethodId = PaymentMethod::query()
                ->where('is_cash', true)
                ->orderBy('id')
                ->value('id');

            if ($cashMethodId) {
                return (int) $cashMethodId;
            }

            $fallbackCashId = PaymentMethod::query()
                ->whereRaw('LOWER(name) LIKE ?', ['%cash%'])
                ->orderBy('id')
                ->value('id');

            if ($fallbackCashId) {
                return (int) $fallbackCashId;
            }
        }

        if ($methodCode === 'transfer') {
            $transferMethodId = PaymentMethod::query()
                ->whereRaw('LOWER(name) LIKE ?', ['%transfer%'])
                ->orderBy('id')
                ->value('id');

            if ($transferMethodId) {
                return (int) $transferMethodId;
            }
        }

        if ($methodCode === 'qris') {
            $qrisMethodId = PaymentMethod::query()
                ->whereRaw('LOWER(name) LIKE ?', ['%qris%'])
                ->orderBy('id')
                ->value('id');

            if ($qrisMethodId) {
                return (int) $qrisMethodId;
            }
        }

        throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Payment method is not configured for POS.');
    }
}
