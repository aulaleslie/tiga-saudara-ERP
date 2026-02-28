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
use Modules\Sale\Entities\SalesOrderSerialTracking;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Product\Entities\ProductSerialNumber;
use App\Services\SerialNumberHistoryService;

class InlinePosCheckoutPostingAdapter implements PosCheckoutPostingAdapter
{
    public function post(array $context): array
    {
        $settingId = (int) ($context['setting_id'] ?? 0);
        $cashierUserId = (int) ($context['cashier_user_id'] ?? 0);
        $customerId = (int) ($context['customer_id'] ?? 0);
        $checkoutId = (int) ($context['checkout_id'] ?? 0);
        $payment = is_array($context['payment'] ?? null) ? $context['payment'] : [];
        $cartSnapshot = is_array($context['cart_snapshot'] ?? null) ? $context['cart_snapshot'] : [];
        $allocations = is_array($context['allocations'] ?? null) ? $context['allocations'] : [];
        $lines = is_array($cartSnapshot['lines'] ?? null) ? $cartSnapshot['lines'] : [];
        $totals = is_array($cartSnapshot['totals'] ?? null) ? $cartSnapshot['totals'] : [];

        if ($settingId <= 0 || $cashierUserId <= 0 || $customerId <= 0) {
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
        $discountTotal = round((float) ($totals['discount_total'] ?? 0), 2);

        $totalPostedTaxTotal = 0.0;

        $sale = Sale::query()->create([
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'customer_name' => (string) ($customer->customer_name ?? ''),
            'tax_id' => null,
            'tax_percentage' => 0,
            'tax_amount' => 0,
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

            // Handle serial assignments
            $isSerialTracked = (bool) ($line['serial_number_required'] ?? false);
            $assignedSerials = (array) ($line['assigned_serials'] ?? []);
            $serialRecords = [];
            $serialIds = [];

            if ($isSerialTracked) {
                if (count($assignedSerials) !== $qty) {
                    throw new PosCheckoutValidationException('SERIAL_INVALID', "Serial tracked product $productId requires $qty serials, but " . count($assignedSerials) . " assigned.");
                }

                $serialRecords = ProductSerialNumber::query()
                    ->where('product_id', $productId)
                    ->whereIn('serial_number', $assignedSerials)
                    ->get()
                    ->keyBy('serial_number');

                foreach ($assignedSerials as $sn) {
                    if (! isset($serialRecords[$sn])) {
                        throw new PosCheckoutValidationException('SERIAL_INVALID', "Serial $sn not found for product $productId.");
                    }
                    $serialIds[] = (int) $serialRecords[$sn]->id;
                }

                // Override allocations based on actual serial locations
                $lineAllocations = [];
                $grouped = [];
                foreach ($assignedSerials as $sn) {
                    $record = $serialRecords[$sn];
                    $chunkLocId = (int) $record->location_id;
                    $chunkTaxId = $record->tax_id;
                    
                    $groupKey = $chunkLocId . '_' . ($chunkTaxId ?? 'null');
                    if (! isset($grouped[$groupKey])) {
                        $grouped[$groupKey] = [
                            'source_location_id' => $chunkLocId,
                            'source_setting_id' => $settingId,
                            'allocated_qty' => 0,
                            'serial_numbers' => [],
                            'tax_policy_snapshot' => [
                                'source_is_pkp' => true,
                                'tax_id' => $chunkTaxId,
                                'tax_name' => null,
                                'tax_rate' => 0.0, 
                            ]
                        ];
                        if ($chunkTaxId) {
                            $taxRecord = \Modules\Setting\Entities\Tax::find($chunkTaxId);
                            $grouped[$groupKey]['tax_policy_snapshot']['tax_name'] = (string) ($taxRecord?->name ?? '');
                            $grouped[$groupKey]['tax_policy_snapshot']['tax_rate'] = (float) ($taxRecord?->value ?? 0.0);
                        } else {
                            $grouped[$groupKey]['tax_policy_snapshot']['source_is_pkp'] = false;
                        }
                    }
                    $grouped[$groupKey]['allocated_qty']++;
                    $grouped[$groupKey]['serial_numbers'][] = $sn;
                }
                $lineAllocations = array_values($grouped);
            } else {
                $lineAllocations = $allocations[$index] ?? [];

                if ($lineAllocations === []) {
                    throw new PosCheckoutValidationException(
                        'STOCK_UNAVAILABLE',
                        'Stock allocation for checkout line is missing.'
                    );
                }

                $totalAllocated = array_sum(array_column($lineAllocations, 'allocated_qty'));
                if ((int) $totalAllocated !== $qty) {
                    throw new PosCheckoutValidationException('STOCK_UNAVAILABLE', 'Allocated quantity does not match line quantity.');
                }
            }

            $unitPrice = round((float) ($line['unit_price'] ?? 0), 2);
            $lineSubtotal = round((float) ($line['line_subtotal'] ?? ($unitPrice * $qty)), 2);

            $lineSubtotalMinor = $this->toMinor($lineSubtotal);
            $chunkGrossMinor = $this->allocateMinorByQuantity($lineAllocations, $lineSubtotalMinor, $qty);

            $linePostedTaxMinor = 0;
            foreach ($lineAllocations as $chunkIndex => $chunk) {
                $chunkQty = (int) ($chunk['allocated_qty'] ?? 0);
                $snapshot = $chunk['tax_policy_snapshot'] ?? [];
                $sourceIsPkp = (bool) ($snapshot['source_is_pkp'] ?? false);

                if ($sourceIsPkp) {
                    $taxRate = (float) ($snapshot['tax_rate'] ?? 0);
                    $linePostedTaxMinor += $this->extractIncludedTaxMinor(
                        (int) ($chunkGrossMinor[$chunkIndex] ?? 0),
                        $taxRate
                    );
                }
            }
            $linePostedTax = $this->fromMinor($linePostedTaxMinor);
            $totalPostedTaxTotal += $linePostedTax;

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
                'sub_total' => $lineSubtotal,
                'product_discount_amount' => $lineDiscount,
                'product_discount_type' => (string) ($line['line_discount_type'] ?? 'fixed'),
                'product_tax_amount' => $linePostedTax,
                'tax_id' => $taxId,
                'serial_number_ids' => $isSerialTracked ? $serialIds : null,
            ]);

            foreach ($lineAllocations as $chunk) {
                $chunkQty = (int) ($chunk['allocated_qty'] ?? 0);
                $chunkLocId = (int) ($chunk['source_location_id'] ?? 0);
                $snapshot = $chunk['tax_policy_snapshot'] ?? [];
                $sourceIsPkp = (bool) ($snapshot['source_is_pkp'] ?? false);
                $effectiveTaxId = $sourceIsPkp ? ($snapshot['tax_id'] ?? $taxId) : null;

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

                if ($effectiveTaxId !== null && (int) $stock->quantity_tax < $chunkQty) {
                    throw new PosCheckoutValidationException('STOCK_UNAVAILABLE', 'Insufficient taxed stock at source location.');
                }

                if ($effectiveTaxId === null && (int) $stock->quantity_non_tax < $chunkQty) {
                    throw new PosCheckoutValidationException('STOCK_UNAVAILABLE', 'Insufficient non-tax stock at source location.');
                }

                $assignedSerialsForChunk = $chunk['serial_numbers'] ?? null;

                $dispatchDetail = DispatchDetail::query()->create([
                    'dispatch_id' => $dispatch->id,
                    'sale_id' => $sale->id,
                    'tax_id' => $effectiveTaxId,
                    'product_id' => $productId,
                    'bundle_id' => null,
                    'dispatched_quantity' => $chunkQty,
                    'location_id' => $chunkLocId,
                    'serial_numbers' => $assignedSerialsForChunk ? json_encode($assignedSerialsForChunk) : null,
                ]);

                if ($assignedSerialsForChunk) {
                    foreach ($assignedSerialsForChunk as $sn) {
                        $snRecord = $serialRecords[$sn];
                        $snRecord->update([
                            'status' => 'SOLD',
                            'dispatch_detail_id' => $dispatchDetail->id,
                        ]);

                        SerialNumberHistoryService::record(
                            (int) $snRecord->id,
                            'SOLD',
                            $chunkLocId,
                            $dispatchDetail
                        );

                        SalesOrderSerialTracking::query()->create([
                            'sale_id' => (int) $sale->id,
                            'product_serial_number_id' => (int) $snRecord->id,
                            'quantity_allocated' => 1,
                            'dispatch_date' => now()->toDateTimeString(),
                        ]);
                    }
                }

                $previousProductQty = (int) $product->product_quantity;
                $previousLocationQty = (int) $stock->quantity;

                $stock->quantity = max(0, (int) $stock->quantity - $chunkQty);
                if ($effectiveTaxId !== null) {
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
                    'quantity_tax' => $effectiveTaxId !== null ? $chunkQty : 0,
                    'quantity_non_tax' => $effectiveTaxId !== null ? 0 : $chunkQty,
                    'broken_quantity_tax' => 0,
                    'broken_quantity_non_tax' => 0,
                ]);
            }
        }

        // Keep payable total aligned with gross cart totals; tax is extracted for reporting only.
        $totalPostedTaxTotal = round($totalPostedTaxTotal, 2);
        $totalPostedGrandTotal = $grandTotal;
        $sale->update([
            'tax_amount' => $totalPostedTaxTotal,
            'total_amount' => $totalPostedGrandTotal,
            'paid_amount' => $totalPostedGrandTotal,
            'is_tax_included' => $totalPostedTaxTotal > 0,
        ]);

        $salePayment = SalePayment::query()->create([
            'sale_id' => $sale->id,
            'amount' => $totalPostedGrandTotal,
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
            'actual_tax_total' => (float) $totalPostedTaxTotal,
            'actual_grand_total' => (float) $totalPostedGrandTotal,
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

    /**
     * @param array<int, array<string, mixed>> $allocations
     * @return array<int, int>
     */
    private function allocateMinorByQuantity(array $allocations, int $totalMinor, int $totalQty): array
    {
        $shares = [];
        if ($totalMinor <= 0 || $totalQty <= 0 || $allocations === []) {
            foreach ($allocations as $index => $_allocation) {
                $shares[$index] = 0;
            }

            return $shares;
        }

        $fractionalRows = [];
        $allocated = 0;

        foreach ($allocations as $index => $allocation) {
            $chunkQty = max(0, (int) ($allocation['allocated_qty'] ?? 0));
            $numerator = $totalMinor * $chunkQty;
            $floorShare = intdiv($numerator, $totalQty);
            $remainder = $numerator % $totalQty;

            $shares[$index] = $floorShare;
            $allocated += $floorShare;
            $fractionalRows[] = [
                'index' => $index,
                'remainder' => $remainder,
            ];
        }

        $remaining = max(0, $totalMinor - $allocated);
        usort($fractionalRows, function (array $left, array $right): int {
            if ((int) $left['remainder'] === (int) $right['remainder']) {
                return (int) $left['index'] <=> (int) $right['index'];
            }

            return (int) $right['remainder'] <=> (int) $left['remainder'];
        });

        $rowCount = count($fractionalRows);
        for ($index = 0; $index < $remaining && $rowCount > 0; $index++) {
            $row = $fractionalRows[$index % $rowCount];
            $shares[(int) $row['index']]++;
        }

        return $shares;
    }

    private function extractIncludedTaxMinor(int $grossMinor, float $taxRate): int
    {
        if ($grossMinor <= 0) {
            return 0;
        }

        $rateBasisPoints = (int) round(max(0.0, $taxRate) * 100, 0, PHP_ROUND_HALF_UP);
        if ($rateBasisPoints <= 0) {
            return 0;
        }

        $grossAmount = $grossMinor / 100;
        $taxAmount = (int) round(
            ($grossAmount * $rateBasisPoints) / (10000 + $rateBasisPoints),
            0,
            PHP_ROUND_HALF_UP
        );

        return $taxAmount * 100;
    }

    private function toMinor(float $value): int
    {
        return (int) round($value * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function fromMinor(int $value): float
    {
        return round($value / 100, 2);
    }
}
