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
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Konteks posting checkout tidak valid.');
        }

        $customer = Customer::query()
            ->whereKey($customerId)
            ->first();

        if (! $customer) {
            throw new PosCheckoutValidationException('CUSTOMER_UNRESOLVED', 'Pelanggan tidak dapat ditentukan untuk checkout.');
        }

        // Handle both single-payment and multi-payment contexts
        $isMultiPayment = (bool) ($payment['is_multi_payment'] ?? false);

        if ($isMultiPayment) {
            // For multi-payment: use first payment method for sale_payment row
            $payments = is_array($payment['payments'] ?? null) ? $payment['payments'] : [];
            if (empty($payments)) {
                throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Array pembayaran kosong.');
            }
            $firstPayment = $payments[0];
            $paymentMethodId = (int) ($firstPayment['payment_method_id'] ?? 0);
            $paymentReference = $firstPayment['reference'] ?? null;
        } else {
            // For single-payment: use existing logic
            $paymentReference = isset($payment['reference']) ? trim((string) $payment['reference']) : null;
            $paymentMethodId = (int) ($payment['payment_method_id'] ?? 0);
        }

        $paymentReference = isset($paymentReference) ? trim((string) $paymentReference) : null;
        $paymentReference = $paymentReference !== '' ? $paymentReference : null;

        if ($paymentMethodId <= 0) {
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Metode pembayaran diperlukan.');
        }

        $paymentMethod = PaymentMethod::query()->find($paymentMethodId);
        if (! $paymentMethod) {
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Metode pembayaran tidak ditemukan.');
        }

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
            'payment_method' => strtoupper($paymentMethod->name ?? 'CUSTOM'),
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
            $bundleId = isset($line['bundle_id']) ? (int) $line['bundle_id'] : null;
            $bundleItems = is_array($line['bundle_items'] ?? null) ? $line['bundle_items'] : [];

            if ($productId <= 0 || $qty <= 0) {
                throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Baris checkout tidak valid.');
            }

            // Handle serial assignments (Parent only for now)
            $isSerialTracked = (bool) ($line['serial_number_required'] ?? false);
            $assignedSerials = (array) ($line['assigned_serials'] ?? []);
            $serialRecords = [];
            $serialIds = [];

            if ($isSerialTracked && (bool) ($line['stock_managed'] ?? true)) {
                if (count($assignedSerials) !== $qty) {
                    throw new PosCheckoutValidationException('SERIAL_INVALID', "Produk terlacak seri $productId memerlukan $qty seri, tetapi " . count($assignedSerials) . " yang diberikan.");
                }

                $serialRecords = ProductSerialNumber::query()
                    ->where('product_id', $productId)
                    ->whereIn('serial_number', $assignedSerials)
                    ->get()
                    ->keyBy('serial_number');

                foreach ($assignedSerials as $sn) {
                    if (! isset($serialRecords[$sn])) {
                        throw new PosCheckoutValidationException('SERIAL_INVALID', "Seri $sn tidak ditemukan untuk produk $productId.");
                    }
                    $serialIds[] = (int) $serialRecords[$sn]->id;
                }
            }

            // 1. Resolve Parent Allocations
            $parentAllocations = [];
            if ((bool) ($line['stock_managed'] ?? true)) {
                // Try "{index}_P" first (from new bundle-aware resolver lines), fallback to "$index" for backward compatibility
                $parentAllocations = $allocations["{$index}_P"] ?? ($allocations[$index] ?? []);

                if ($parentAllocations === []) {
                    throw new PosCheckoutValidationException(
                        'STOCK_UNAVAILABLE',
                        "Stok alokasi untuk produk #{$productId} tidak ditemukan."
                    );
                }

                if (! $isSerialTracked) {
                    $totalAllocated = array_sum(array_column($parentAllocations, 'allocated_qty'));
                    if ((int) $totalAllocated !== $qty) {
                        throw new PosCheckoutValidationException('STOCK_UNAVAILABLE', "Kuantitas alokasi produk #{$productId} tidak sesuai.");
                    }
                }
            }

            $unitPrice = round((float) ($line['unit_price'] ?? 0), 2);
            $lineSubtotal = round((float) ($line['line_subtotal'] ?? ($unitPrice * $qty)), 2);

            $lineSubtotalMinor = $this->toMinor($lineSubtotal);
            $linePostedTaxMinor = 0;

            if ($parentAllocations !== []) {
                $chunkGrossMinor = $this->allocateMinorByQuantity($parentAllocations, $lineSubtotalMinor, $qty);
                foreach ($parentAllocations as $chunkIndex => $chunk) {
                    $snapshot = $chunk['tax_policy_snapshot'] ?? [];
                    if ((bool) ($snapshot['source_is_pkp'] ?? false)) {
                        $taxRate = (float) ($snapshot['tax_rate'] ?? 0);
                        $linePostedTaxMinor += $this->extractIncludedTaxMinor(
                            (int) ($chunkGrossMinor[$chunkIndex] ?? 0),
                            $taxRate
                        );
                    }
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

            // 2. Process Parent Stock Deduction
            if ($parentAllocations !== []) {
                $this->recordStockMovement(
                    $productId,
                    $qty,
                    $parentAllocations,
                    $cashierUserId,
                    $settingId,
                    $checkoutId,
                    $sale,
                    $dispatch,
                    $taxId,
                    $bundleId,
                    $serialRecords
                );
            }

            // 3. Process Bundle Child Stock Deduction
            foreach ($bundleItems as $itemIndex => $item) {
                if ((bool) ($item['stock_managed'] ?? false)) {
                    $childProductId = (int) ($item['product_id'] ?? 0);
                    $childQty = $qty * (int) ($item['quantity'] ?? 1);
                    $childAllocations = $allocations["{$index}_C_{$itemIndex}"] ?? [];

                    if ($childAllocations === []) {
                        throw new PosCheckoutValidationException(
                            'STOCK_UNAVAILABLE',
                            "Stok alokasi untuk produk anak #{$childProductId} dalam paket tidak ditemukan."
                        );
                    }

                    $this->recordStockMovement(
                        $childProductId,
                        $childQty,
                        $childAllocations,
                        $cashierUserId,
                        $settingId,
                        $checkoutId,
                        $sale,
                        $dispatch,
                        null, // Tax is typically handled on the parent line in POS
                        $bundleId,
                        [] // Serial items in bundles not supported yet
                    );
                }
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

        // Create SalePayment(s)
        $lastSalePaymentId = null;

        if ($isMultiPayment) {
            // Create one SalePayment per payment method
            $payments = is_array($payment['payments'] ?? null) ? $payment['payments'] : [];

            foreach ($payments as $paymentEntry) {
                $entryAmount = (float) ($paymentEntry['amount_minor_units'] ?? 0) / 100;

                // Task 3.4: Skip creating SalePayment for any payment entry with amount ≤ 0
                if ($entryAmount <= 0) {
                    continue;
                }

                $entryPaymentMethodId = (int) ($paymentEntry['payment_method_id'] ?? 0);
                $entryPaymentMethod = PaymentMethod::query()->find($entryPaymentMethodId);

                $salePayment = SalePayment::query()->create([
                    'sale_id' => $sale->id,
                    'amount' => $entryAmount,
                    'date' => now()->toDateString(),
                    'reference' => $sale->reference,
                    'payment_method' => strtoupper($entryPaymentMethod?->name ?? 'CUSTOM'),
                    'note' => $paymentEntry['reference'] ?? null,
                    'payment_method_id' => $entryPaymentMethodId,
                ]);

                $lastSalePaymentId = (int) $salePayment->id;
            }
        } else {
            // Single-payment: keep existing logic (one SalePayment per sale)
            $salePayment = SalePayment::query()->create([
                'sale_id' => $sale->id,
                'amount' => $totalPostedGrandTotal,
                'date' => now()->toDateString(),
                'reference' => $sale->reference,
                'payment_method' => strtoupper($paymentMethod->name ?? 'CUSTOM'),
                'note' => $paymentReference,
                'payment_method_id' => $paymentMethodId,
            ]);

            $lastSalePaymentId = (int) $salePayment->id;
        }

        return [
            'sale_id' => (int) $sale->id,
            'dispatch_ids' => [(int) $dispatch->id],
            'sale_payment_id' => $lastSalePaymentId,
            'receipt_number' => (string) $sale->reference,
            'actual_tax_total' => (float) $totalPostedTaxTotal,
            'actual_grand_total' => (float) $totalPostedGrandTotal,
        ];
    }

    /**
     * Record stock movement, transactions, and dispatch details for a product allocation chunk.
     */
    private function recordStockMovement(
        int $productId,
        int $qty,
        array $lineAllocations,
        int $cashierUserId,
        int $settingId,
        int $checkoutId,
        Sale $sale,
        Dispatch $dispatch,
        ?int $lineTaxId,
        ?int $bundleId = null,
        array $serialRecords = []
    ): void {
        foreach ($lineAllocations as $chunk) {
            $chunkQty = (int) ($chunk['allocated_qty'] ?? 0);
            $chunkLocId = (int) ($chunk['source_location_id'] ?? 0);
            $snapshot = $chunk['tax_policy_snapshot'] ?? [];
            $taxBucketUsed = (bool) ($chunk['tax_bucket_used'] ?? false);
            $snapshotTaxId = isset($snapshot['tax_id']) ? (int) $snapshot['tax_id'] : null;
            $snapshotTaxId = $snapshotTaxId !== null && $snapshotTaxId > 0 ? $snapshotTaxId : null;

            $stock = ProductStock::query()
                ->where('product_id', $productId)
                ->where('location_id', $chunkLocId)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                throw new PosCheckoutValidationException('STOCK_UNAVAILABLE', "Stok produk #{$productId} tidak tersedia di lokasi sumber.");
            }

            if ((int) $stock->quantity < $chunkQty) {
                throw new PosCheckoutValidationException('STOCK_UNAVAILABLE', "Stok produk #{$productId} tidak cukup di lokasi sumber.");
            }

            if ($taxBucketUsed && (int) $stock->quantity_tax < $chunkQty) {
                throw new PosCheckoutValidationException('STOCK_UNAVAILABLE', "Stok pajak produk #{$productId} tidak cukup di lokasi sumber.");
            }

            if (! $taxBucketUsed && (int) $stock->quantity_non_tax < $chunkQty) {
                throw new PosCheckoutValidationException('STOCK_UNAVAILABLE', "Stok non-pajak produk #{$productId} tidak cukup di lokasi sumber.");
            }

            $assignedSerialsForChunk = $chunk['serial_numbers'] ?? null;

            $dispatchDetail = DispatchDetail::query()->create([
                'dispatch_id' => $dispatch->id,
                'sale_id' => $sale->id,
                'tax_id' => $snapshotTaxId ?? $lineTaxId,
                'product_id' => $productId,
                'bundle_id' => $bundleId,
                'dispatched_quantity' => $chunkQty,
                'location_id' => $chunkLocId,
                'serial_numbers' => $assignedSerialsForChunk ? json_encode($assignedSerialsForChunk) : null,
            ]);

            if ($assignedSerialsForChunk) {
                foreach ($assignedSerialsForChunk as $sn) {
                    if (! isset($serialRecords[$sn])) {
                        continue;
                    }
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

            $sourceSettingId = (int) ($chunk['source_setting_id'] ?? $settingId);

            $previousSettingQty = (int) ProductStock::query()
                ->where('product_id', $productId)
                ->whereHas('location', fn($q) => $q->where('setting_id', $sourceSettingId))
                ->sum('quantity');

            $previousLocationQty = (int) $stock->quantity;

            $stock->quantity = max(0, (int) $stock->quantity - $chunkQty);
            if ($taxBucketUsed) {
                $stock->quantity_tax = max(0, (int) $stock->quantity_tax - $chunkQty);
            } else {
                $stock->quantity_non_tax = max(0, (int) $stock->quantity_non_tax - $chunkQty);
            }
            $stock->save();

            $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
            if ($product) {
                $product->product_quantity = max(0, (int) $product->product_quantity - $chunkQty);
                $product->save();
            }

            $afterSettingQty = $previousSettingQty - $chunkQty;
            $afterLocationQty = (int) $stock->quantity;

            Transaction::query()->create([
                'product_id' => $productId,
                'setting_id' => $sourceSettingId,
                'quantity' => -$chunkQty,
                'current_quantity' => $afterSettingQty,
                'broken_quantity' => 0,
                'location_id' => $chunkLocId,
                'user_id' => $cashierUserId,
                'reason' => 'POS checkout #' . $checkoutId,
                'type' => 'DISPATCH',
                'previous_quantity' => $previousSettingQty,
                'after_quantity' => $afterSettingQty,
                'previous_quantity_at_location' => $previousLocationQty,
                'after_quantity_at_location' => $afterLocationQty,
                'quantity_tax' => $taxBucketUsed ? $chunkQty : 0,
                'quantity_non_tax' => $taxBucketUsed ? 0 : $chunkQty,
                'broken_quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
            ]);
        }
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
