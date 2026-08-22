<?php

namespace Modules\Pos\Services\Adapters;

use Carbon\Carbon;
use Modules\People\Entities\Customer;
use Modules\Pos\Services\Contracts\PosCheckoutPostingAdapter;
use Modules\Pos\Services\Exceptions\PosCheckoutValidationException;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SalesOrderSerialTracking;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Product\Entities\ProductSerialNumber;
use App\Services\SerialNumberHistoryService;
use App\Support\SalesLocationResolver;
use Modules\Sale\Support\PendingDispatchSerialGuard;

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

        $isDebt = (bool) ($context['is_debt'] ?? false);
        $paymentTermId = isset($context['payment_term_id']) ? (int) $context['payment_term_id'] : null;
        $downPaymentAmount = round((float) ($payment['amount_paid'] ?? 0), 2);

        // Handle both single-payment and multi-payment contexts
        $isMultiPayment = (bool) ($payment['is_multi_payment'] ?? false);

        $paymentMethodId = null;
        $paymentReference = null;
        $paymentMethod = null;

        if ($isMultiPayment) {
            // For multi-payment: use first payment method for sale_payment row
            $payments = is_array($payment['payments'] ?? null) ? $payment['payments'] : [];
            if (empty($payments) && (!$isDebt || $downPaymentAmount > 0)) {
                throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Array pembayaran kosong.');
            }
            if (!empty($payments)) {
                $firstPayment = $payments[0];
                $paymentMethodId = (int) ($firstPayment['payment_method_id'] ?? 0);
                $paymentReference = $firstPayment['reference'] ?? null;
            }
        } else {
            // For single-payment: use existing logic
            $paymentReference = isset($payment['reference']) ? trim((string) $payment['reference']) : null;
            $paymentMethodId = (int) ($payment['payment_method_id'] ?? 0);
        }

        $paymentReference = isset($paymentReference) ? trim((string) $paymentReference) : null;
        $paymentReference = $paymentReference !== '' ? $paymentReference : null;

        $paymentMethod = null;

        if ($downPaymentAmount > 0 || !$isDebt) {
            if ($paymentMethodId <= 0) {
                throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Metode pembayaran diperlukan.');
            }

            $paymentMethod = PaymentMethod::query()->find($paymentMethodId);
            if (! $paymentMethod) {
                throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Metode pembayaran tidak ditemukan.');
            }
        }

        $grandTotal = round((float) ($totals['grand_total'] ?? 0), 2);
        $discountTotal = round((float) ($totals['discount_total'] ?? 0), 2);

        $totalPostedTaxTotal = 0.0;

        $paidAmount = $grandTotal;
        $dueAmount = 0;
        $paymentStatus = 'Paid';
        $salePaymentTermId = PaymentTerm::defaultCodTermId();
        $dueDate = $context['due_date'] ?? now()->toDateString();
        $checkoutDate = $context['checkout_date'] ?? now()->toDateString();
        $salePaymentMethodStr = strtoupper($paymentMethod->name ?? 'CUSTOM');

        if ($isDebt) {
            $paidAmount = $downPaymentAmount;
            $dueAmount = round($grandTotal - $paidAmount, 2);
            $paymentStatus = $paidAmount > 0 ? 'Partial' : 'Unpaid';
            $salePaymentTermId = $paymentTermId;

            if (empty($context['due_date'])) {
                $term = PaymentTerm::query()->find($paymentTermId);
                if ($term) {
                    $dueDate = now()->addDays((int) $term->longevity)->toDateString();
                }
            }
            if ($paidAmount == 0) {
                $salePaymentMethodStr = 'DEBT';
            }
        }

        $cartNote = $cartSnapshot['note'] ?? null;
        $posTransactionCode = $context['pos_transaction_code'] ?? null;

        $saleNote = $posTransactionCode ? 'POS ' . $posTransactionCode : 'POS checkout #' . $checkoutId;
        if ($cartNote !== null && $cartNote !== '') {
            $saleNote .= "\n" . $cartNote;
        }

        // When this Sale carries only non-stock content it has no allocation-derived owner,
        // so ownership comes from the configured first POS sales-location source. This keeps
        // non-stock ownership correct with split posting disabled, where this adapter posts
        // the whole cart directly. With split posting enabled the caller has already resolved
        // the owner per group, and that resolved setting is preserved unchanged.
        $saleSettingId = $settingId;
        if ($this->cartIsEntirelyNonStock($lines)) {
            $saleSettingId = $this->requireNonStockSource($settingId)['setting_id'];
        }

        $sequenceAllocator = app(\App\Services\Sequence\DocumentSequenceAllocator::class);
        $sequenceNamespace = $sequenceAllocator->buildNamespace(
            \App\Services\Sequence\DocumentType::SALE,
            $saleSettingId,
            Carbon::parse($checkoutDate)
        );
        $namespaceCollector = $context['sequence_namespace_collector'] ?? null;
        if (is_callable($namespaceCollector)) {
            $namespaceCollector([$sequenceNamespace]);
        }

        $sale = Sale::query()->create([
            'date' => $checkoutDate,
            'due_date' => $dueDate,
            'customer_id' => $customer->id,
            'customer_name' => (string) ($customer->customer_name ?? ''),
            'tax_id' => null,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => $discountTotal,
            'shipping_amount' => 0,
            'total_amount' => $grandTotal,
            'paid_amount' => $paidAmount,
            'due_amount' => $dueAmount,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => $paymentStatus,
            'payment_term_id' => $salePaymentTermId,
            'note' => $saleNote,
            'setting_id' => $saleSettingId,
            'is_tax_included' => false,
            'payment_method' => $salePaymentMethodStr,
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

        $hppWarnings = [];

        foreach ($lines as $index => $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $qty = (int) ($line['qty'] ?? 0);
            $taxId = isset($line['tax_id']) ? (int) $line['tax_id'] : 0;
            $taxId = $taxId > 0 ? $taxId : null;
            $bundleId = isset($line['bundle_id']) ? (int) $line['bundle_id'] : null;
            $bundleItems = is_array($line['bundle_items'] ?? null) ? $line['bundle_items'] : [];

            if ($productId <= 0 || $qty < 0) {
                throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Baris checkout tidak valid.');
            }

            // Persisted product classification is the sole authority for choosing between
            // inventory posting and audit-only posting. The cart snapshot is untrusted input.
            $parentNotFulfilledByGroup = (bool) ($line['parent_not_fulfilled_by_group'] ?? false);
            $isStockManaged = $this->resolveAuthoritativeStockManaged(
                productId: $productId,
                snapshotStockManaged: $line['stock_managed'] ?? null,
                label: (string) (($line['product_name'] ?? null) ?: (($line['product_code'] ?? null) ?: "#$productId")),
                allowSnapshotDowngrade: $parentNotFulfilledByGroup
            );

            // Handle serial assignments (Parent only for now)
            $isSerialTracked = (bool) ($line['serial_number_required'] ?? false);
            $assignedSerials = (array) ($line['assigned_serials'] ?? []);
            $serialRecords = [];
            $serialIds = [];

            if ($isSerialTracked && $isStockManaged && ! $parentNotFulfilledByGroup) {
                if (count($assignedSerials) !== $qty) {
                    throw new PosCheckoutValidationException('SERIAL_INVALID', "Produk terlacak seri $productId memerlukan $qty seri, tetapi " . count($assignedSerials) . " yang diberikan.");
                }

                if (count($assignedSerials) !== count(array_unique($assignedSerials))) {
                    throw new PosCheckoutValidationException('SERIAL_INVALID', "Produk terlacak seri $productId memiliki nomor seri duplikat dalam satu baris.");
                }

                $serialRecords = ProductSerialNumber::query()
                    ->where('product_id', $productId)
                    ->whereIn('serial_number', $assignedSerials)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('serial_number');

                foreach ($assignedSerials as $sn) {
                    if (! isset($serialRecords[$sn])) {
                        throw new PosCheckoutValidationException('SERIAL_INVALID', "Seri $sn tidak ditemukan untuk produk $productId.");
                    }
                    $this->assertSerialCurrentlyPostable($serialRecords[$sn], $settingId);
                    $serialIds[] = (int) $serialRecords[$sn]->id;
                }
            }

            // 1. Resolve Parent Allocations
            $parentAllocations = [];
            if ($isStockManaged && ! $parentNotFulfilledByGroup) {
                // Try "{index}_P" first (from new bundle-aware resolver lines), fallback to "$index" for backward compatibility
                $parentAllocations = $allocations["{$index}_P"] ?? ($allocations[$index] ?? []);

                if ($parentAllocations === []) {
                    $productLabel = (string) (($line['product_name'] ?? null) ?: (($line['product_code'] ?? null) ?: "#$productId"));
                    throw new PosCheckoutValidationException(
                        'STOCK_UNAVAILABLE',
                        "Stok alokasi untuk produk $productLabel tidak ditemukan."
                    );
                }

                if (! $isSerialTracked) {
                    $totalAllocated = array_sum(array_column($parentAllocations, 'allocated_qty'));
                    if ((int) $totalAllocated !== $qty) {
                        $productLabel = (string) (($line['product_name'] ?? null) ?: (($line['product_code'] ?? null) ?: "#$productId"));
                        throw new PosCheckoutValidationException('STOCK_UNAVAILABLE', "Kuantitas alokasi produk $productLabel tidak sesuai.");
                    }
                }
            }

            $unitPrice = round((float) ($line['unit_price'] ?? 0), 2);
            $lineSubtotal = round((float) ($line['line_subtotal'] ?? ($unitPrice * $qty)), 2);

            $linePostedTax = (float) ($line['line_tax_total'] ?? 0);
            $totalPostedTaxTotal += $linePostedTax;

            $lineDiscount = round(
                (float) ($line['line_discount_amount'] ?? 0) + (float) ($line['bill_discount_amount'] ?? 0),
                2
            );

            $saleDetail = SaleDetails::query()->create([
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

            // 2. Process Parent Stock Deduction (only for stock-managed products)

            // Non-stock parents receive an approved audit-only DispatchDetail instead of
            // any allocation, stock validation, or inventory movement.
            if (! $isStockManaged && $qty > 0 && ! $parentNotFulfilledByGroup) {
                $auditAllocations = $allocations["{$index}_P"] ?? ($allocations[$index] ?? []);

                $this->recordNonStockAuditDispatchDetail(
                    productId: $productId,
                    qty: $qty,
                    sale: $sale,
                    dispatch: $dispatch,
                    taxId: $taxId,
                    bundleId: $bundleId,
                    locationId: $this->resolveNonStockAuditLocationId($settingId, $auditAllocations)
                );
            }

            if ($isStockManaged && $parentAllocations !== []) {
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
                    $serialRecords instanceof \Illuminate\Support\Collection ? $serialRecords->all() : $serialRecords,
                    (int) $saleDetail->id
                );
            }

            // 3. Process Bundle Child Stock Deduction and Persistence
            foreach ($bundleItems as $itemIndex => $item) {
                $childProductId = (int) ($item['product_id'] ?? 0);
                $childQty = $qty * (int) ($item['quantity'] ?? 1);
                $childAllocations = $allocations["{$index}_C_{$itemIndex}"] ?? [];

                // During split posting, a group may not fulfill all bundle components.
                // We skip persistence and stock movement for components not allocated to this group.
                if ($childAllocations === []) {
                    continue;
                }

                $childAllocatedQty = array_sum(array_column($childAllocations, 'allocated_qty'));

                // Split-planned allocations carry an exact minor-unit revenue share
                // (allocated_minor). The simpler stock-resolver allocation path used for
                // wholly-terminal-owned carts does not compute revenue shares at all, so
                // fall back to the captured per-unit informational price for that path.
                $hasRevenueAllocation = $childAllocations !== [] && array_key_exists('allocated_minor', $childAllocations[0]);
                if ($hasRevenueAllocation) {
                    $childAllocatedMinor = array_sum(array_column($childAllocations, 'allocated_minor'));
                } else {
                    $informationalItemPrice = isset($item['informational_item_price']) && is_numeric($item['informational_item_price'])
                        ? (float) $item['informational_item_price']
                        : 0.0;
                    $childAllocatedMinor = $this->toMinor(round($informationalItemPrice * $childAllocatedQty, 2));
                }

                $childUnitPrice = $childAllocatedQty > 0 ? $this->fromMinor((int) round($childAllocatedMinor / $childAllocatedQty)) : 0;

                // Resolve child tax context from allocations where available
                $childTaxId = null;
                $childTaxAmount = 0;
                if ($childAllocations !== []) {
                    $firstSnapshot = $childAllocations[0]['tax_policy_snapshot'] ?? [];
                    $childTaxId = isset($firstSnapshot['tax_id']) ? (int) $firstSnapshot['tax_id'] : null;
                    $childTaxId = ($childTaxId !== null && $childTaxId > 0) ? $childTaxId : null;

                    // Sum up tax contributions from all child allocations
                    foreach ($childAllocations as $ca) {
                        $caSnapshot = $ca['tax_policy_snapshot'] ?? [];
                        $caTaxId = isset($caSnapshot['tax_id']) ? (int) $caSnapshot['tax_id'] : null;
                        if ($caTaxId !== null && $caTaxId > 0) {
                            $caTaxRate = (float) ($caSnapshot['tax_rate'] ?? 0);
                            $childTaxAmount += $this->fromMinor($this->extractIncludedTaxMinor(
                                (int) ($ca['allocated_minor'] ?? 0),
                                $caTaxRate
                            ));
                        }
                    }
                }

                // Resolve bundle_item_id from product_bundle_items
                $bundleItemRecordId = null;
                if ($bundleId > 0) {
                    $bundleItemRecordId = ProductBundleItem::query()
                        ->where('bundle_id', $bundleId)
                        ->where('product_id', $childProductId)
                        ->value('id');
                }

                // Create SaleBundleItem record
                SaleBundleItem::query()->create([
                    'sale_id' => $sale->id,
                    'sale_detail_id' => $saleDetail->id,
                    'bundle_id' => $bundleId,
                    'bundle_item_id' => $bundleItemRecordId,
                    'product_id' => $childProductId,
                    'name' => (string) ($item['product_name'] ?? ''),
                    'quantity' => $childAllocatedQty,
                    'price' => $childUnitPrice,
                    'informational_item_price' => isset($item['informational_item_price']) && is_numeric($item['informational_item_price'])
                        ? round((float) $item['informational_item_price'], 2)
                        : null,
                    'sub_total' => $this->fromMinor((int) $childAllocatedMinor),
                    'tax_id' => $childTaxId,
                    'tax_amount' => round($childTaxAmount, 2),
                    'line_group_key' => "pos-{$index}-{$itemIndex}",
                ]);

                $childIsStockManaged = $this->resolveAuthoritativeStockManaged(
                    productId: $childProductId,
                    snapshotStockManaged: $item['stock_managed'] ?? null,
                    label: (string) (($item['product_name'] ?? null) ?: (($item['product_code'] ?? null) ?: "#$childProductId"))
                );

                if (! $childIsStockManaged) {
                    // Non-stock component: approved audit-only DispatchDetail, no inventory work.
                    if ($childAllocatedQty > 0) {
                        $this->recordNonStockAuditDispatchDetail(
                            productId: $childProductId,
                            qty: (int) $childAllocatedQty,
                            sale: $sale,
                            dispatch: $dispatch,
                            taxId: $childTaxId,
                            bundleId: $bundleId,
                            locationId: $this->resolveNonStockAuditLocationId($settingId, $childAllocations)
                        );
                    }
                } else {
                    if ($childAllocations === []) {
                        $childProductLabel = (string) (($item['product_name'] ?? null) ?: (($item['product_code'] ?? null) ?: "#$childProductId"));
                        throw new PosCheckoutValidationException(
                            'STOCK_UNAVAILABLE',
                            "Stok alokasi untuk produk $childProductLabel dalam paket tidak ditemukan."
                        );
                    }

                    $bundleItemId = (int) ($item['bundle_item_id'] ?? 0);
                    $bundleItemSerials = is_array($line['bundle_item_serials'] ?? null) ? $line['bundle_item_serials'] : [];
                    $rawCompAssignedSerials = array_values(array_filter(
                        (array) ($bundleItemSerials[$bundleItemId] ?? ($item['assigned_serials'] ?? [])),
                        static fn ($serial): bool => is_string($serial) && trim($serial) !== ''
                    ));

                    if (count($rawCompAssignedSerials) !== count(array_unique($rawCompAssignedSerials))) {
                        $childProductLabel = (string) (($item['product_name'] ?? null) ?: (($item['product_code'] ?? null) ?: "#$childProductId"));
                        throw new PosCheckoutValidationException('SERIAL_INVALID', "Komponen $childProductLabel memiliki nomor seri duplikat dalam satu baris.");
                    }

                    $compAssignedSerials = $rawCompAssignedSerials;

                    // During split posting, `bundle_item_serials` still carries every
                    // component serial across all owner groups; only revalidate/lock
                    // the subset the split planner actually allocated to this group's
                    // chunks, or a sibling group's already-posted serial would appear
                    // stale here even though it belongs to that other group.
                    $chunkSerials = [];
                    foreach ($childAllocations as $chunk) {
                        foreach ((array) ($chunk['serial_numbers'] ?? []) as $sn) {
                            $chunkSerials[] = $sn;
                        }
                    }
                    if ($chunkSerials !== []) {
                        $compAssignedSerials = array_values(array_intersect($compAssignedSerials, $chunkSerials));
                    }

                    $childSerialRecords = [];
                    $compSerialRequired = (bool) ($item['serial_number_required'] ?? false);
                    if ($compSerialRequired && $compAssignedSerials !== []) {
                        $childSerialRecords = ProductSerialNumber::query()
                            ->where('product_id', $childProductId)
                            ->whereIn('serial_number', $compAssignedSerials)
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get()
                            ->keyBy('serial_number');

                        $childProductLabel = (string) (($item['product_name'] ?? null) ?: (($item['product_code'] ?? null) ?: "#$childProductId"));
                        foreach ($compAssignedSerials as $sn) {
                            if (! isset($childSerialRecords[$sn])) {
                                throw new PosCheckoutValidationException('SERIAL_INVALID', "Seri $sn tidak ditemukan untuk komponen $childProductLabel.");
                            }
                            $this->assertSerialCurrentlyPostable($childSerialRecords[$sn], $settingId);
                        }
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
                        $childTaxId,
                        $bundleId,
                        $childSerialRecords instanceof \Illuminate\Support\Collection ? $childSerialRecords->all() : $childSerialRecords,
                        (int) $saleDetail->id
                    );
                }
            }
            
            $lineHppWarnings = app(\Modules\Sale\Services\SalesCostSnapshotService::class)->snapshotSaleDetailCost(
                $saleDetail,
                null,
                $settingId,
                $parentNotFulfilledByGroup
            );
            $saleDetail->save();
            $hppWarnings = array_merge($hppWarnings, $lineHppWarnings);
        }

        // Keep payable total aligned with gross cart totals; tax is extracted for reporting only.
        $totalPostedTaxTotal = round($totalPostedTaxTotal, 2);
        $totalPostedGrandTotal = $grandTotal;
        $sale->update([
            'tax_amount' => $totalPostedTaxTotal,
            'total_amount' => $totalPostedGrandTotal,
            'paid_amount' => $paidAmount,
            'is_tax_included' => $totalPostedTaxTotal > 0,
        ]);

        // Create SalePayment(s)
        $lastSalePaymentId = null;

        if ($isMultiPayment) {
            // Create one SalePayment per payment method
            $payments = is_array($payment['payments'] ?? null) ? $payment['payments'] : [];

            $stageMappings = [];

            foreach ($payments as $paymentEntry) {
                $entryAmount = (float) ($paymentEntry['amount_minor_units'] ?? 0) / 100;

                // Task 3.4: Skip creating SalePayment for any payment entry with amount <= 0
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
                    'stage_order' => $paymentEntry['stage_order'] ?? null,
                    'edc_reference' => $paymentEntry['edc_reference'] ?? null,
                ]);

                $lastSalePaymentId = (int) $salePayment->id;
                
                if (isset($paymentEntry['stage_order'])) {
                    $stageMappings[(int) $paymentEntry['stage_order']] = $lastSalePaymentId;
                }

                if (!empty($paymentEntry['payment_image_token'])) {
                    app(\Modules\Pos\Services\PosTemporaryPaymentImageService::class)
                        ->consumeAndAttach($paymentEntry['payment_image_token'], $salePayment);
                }
            }
        } else {
            // Single-payment: keep existing logic (one SalePayment per sale)
            if ($downPaymentAmount > 0 || !$isDebt) {
                $salePayment = SalePayment::query()->create([
                    'sale_id' => $sale->id,
                    'amount' => $paidAmount,
                    'date' => now()->toDateString(),
                    'reference' => $sale->reference,
                    'payment_method' => strtoupper($paymentMethod->name ?? 'CUSTOM'),
                    'note' => $paymentReference,
                    'payment_method_id' => $paymentMethodId,
                ]);

                $lastSalePaymentId = (int) $salePayment->id;
                
                if (isset($payment['stage_order'])) {
                    $stageMappings[(int) $payment['stage_order']] = $lastSalePaymentId;
                }

                if (!empty($payment['payment_image_token'])) {
                    app(\Modules\Pos\Services\PosTemporaryPaymentImageService::class)
                        ->consumeAndAttach($payment['payment_image_token'], $salePayment);
                }
            }
        }

        return [
            'sale_id' => (int) $sale->id,
            'dispatch_ids' => [(int) $dispatch->id],
            'sale_payment_id' => $lastSalePaymentId,
            'receipt_number' => (string) $sale->reference,
            'actual_tax_total' => (float) $totalPostedTaxTotal,
            'actual_grand_total' => (float) $totalPostedGrandTotal,
            'stage_mappings' => $stageMappings ?? [],
            'hpp_warnings' => $hppWarnings,
        ];
    }

    /** @var array<int, bool> */
    private array $stockManagedProductCache = [];

    /**
     * Re-read the persisted product classification and use it as the sole authority for
     * choosing between inventory posting and audit-only posting.
     *
     * A crafted cart snapshot must never be able to downgrade a stock-managed product
     * into audit-only content (skipping deduction), nor force a non-stock product through
     * inventory handling. Any disagreement between the snapshot and the database is a
     * conflict and fails the checkout rather than silently picking a path.
     *
     * @param  bool|int|string|null  $snapshotStockManaged  classification claimed by the cart snapshot
     * @param  bool  $allowSnapshotDowngrade  true only for planner-authored group lines whose
     *                                        parent contributes no quantity to this owner group
     */
    private function resolveAuthoritativeStockManaged(
        int $productId,
        mixed $snapshotStockManaged,
        string $label,
        bool $allowSnapshotDowngrade = false
    ): bool {
        if ($productId <= 0) {
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Baris checkout tidak valid.');
        }

        if (! array_key_exists($productId, $this->stockManagedProductCache)) {
            $product = Product::query()->whereKey($productId)->first(['id', 'stock_managed']);

            if (! $product) {
                throw new PosCheckoutValidationException(
                    'PRODUCT_UNRESOLVED',
                    "Produk $label tidak ditemukan saat posting checkout."
                );
            }

            $this->stockManagedProductCache[$productId] = (bool) $product->stock_managed;
        }

        $persisted = $this->stockManagedProductCache[$productId];

        if ($snapshotStockManaged !== null && ! $allowSnapshotDowngrade) {
            $claimed = (bool) $snapshotStockManaged;

            if ($claimed !== $persisted) {
                throw new PosCheckoutValidationException(
                    'PRODUCT_CLASSIFICATION_CONFLICT',
                    "Klasifikasi stok produk $label pada keranjang tidak sesuai dengan data produk saat ini. Muat ulang keranjang dan ulangi checkout."
                );
            }
        }

        return $persisted;
    }

    /**
     * True when every line in the cart snapshot is non-stock-managed by persisted
     * classification, meaning this Sale has no allocation-derived owner.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function cartIsEntirelyNonStock(array $lines): bool
    {
        $sawLine = false;

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            if ($productId <= 0 || (int) ($line['qty'] ?? 0) <= 0) {
                continue;
            }

            $sawLine = true;

            // A group line whose parent is not fulfilled here still belongs to the owner
            // resolved by stock allocation, so it is not purely non-stock content.
            if ((bool) ($line['parent_not_fulfilled_by_group'] ?? false)) {
                return false;
            }

            if ($this->isPersistedStockManaged($productId)) {
                return false;
            }

            foreach ((is_array($line['bundle_items'] ?? null) ? $line['bundle_items'] : []) as $item) {
                $childProductId = (int) ($item['product_id'] ?? 0);
                if ($childProductId > 0 && $this->isPersistedStockManaged($childProductId)) {
                    return false;
                }
            }
        }

        return $sawLine;
    }

    private function isPersistedStockManaged(int $productId): bool
    {
        if (! array_key_exists($productId, $this->stockManagedProductCache)) {
            $product = Product::query()->whereKey($productId)->first(['id', 'stock_managed']);
            // An unresolvable product is reported by the authoritative check during posting.
            $this->stockManagedProductCache[$productId] = $product ? (bool) $product->stock_managed : true;
        }

        return $this->stockManagedProductCache[$productId];
    }

    /**
     * Resolve the configured first POS sales-location source, or fail with an actionable error.
     *
     * @return array{setting_id:int, location_id:int}
     */
    private function requireNonStockSource(int $settingId): array
    {
        $source = app(\Modules\Pos\Services\PosNonStockSourceResolverService::class)->resolve($settingId);

        if ($source === null) {
            throw new PosCheckoutValidationException(
                'NON_STOCK_SOURCE_UNCONFIGURED',
                'Tidak ada lokasi penjualan POS aktif yang dikonfigurasi untuk konten non-stok.'
            );
        }

        return $source;
    }

    /**
     * Resolve the audit DispatchDetail location for non-stock content: the planned group
     * source location when present, otherwise the first configured POS sales location.
     *
     * Both this location and the Sale's owning setting derive from the same configured
     * source, so audit evidence and financial ownership never disagree.
     *
     * @param  array<int, array<string, mixed>>  $allocations
     */
    private function resolveNonStockAuditLocationId(int $settingId, array $allocations = []): int
    {
        foreach ($allocations as $allocation) {
            $locationId = (int) ($allocation['source_location_id'] ?? 0);
            if ($locationId > 0) {
                return $locationId;
            }
        }

        return $this->requireNonStockSource($settingId)['location_id'];
    }

    /**
     * Persist an approved audit-only DispatchDetail for non-stock content.
     *
     * This is fulfilment evidence, never inventory demand: it deliberately performs no
     * stock lookup, no ProductStock/Product mutation, no serial handling, and no
     * inventory Transaction write.
     */
    private function recordNonStockAuditDispatchDetail(
        int $productId,
        int $qty,
        Sale $sale,
        Dispatch $dispatch,
        ?int $taxId,
        ?int $bundleId,
        int $locationId
    ): void {
        DispatchDetail::query()->create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'tax_id' => $taxId,
            'product_id' => $productId,
            'bundle_id' => $bundleId,
            'dispatched_quantity' => $qty,
            'location_id' => $locationId,
            'serial_numbers' => null,
        ]);
    }

    /**
     * Revalidate a serial's current, locked-row state immediately before it is
     * committed to posting. Assignment during cart mutation only reflects the
     * serial's state at that moment; other checkouts, transfers, returns, or
     * dispatch activity can make it stale before this transaction locks it.
     */
    private function assertSerialCurrentlyPostable(ProductSerialNumber $record, int $settingId): void
    {
        if (strtoupper((string) $record->status) !== 'ACTIVE' || $record->dispatch_detail_id !== null) {
            throw new PosCheckoutValidationException(
                'SERIAL_INVALID',
                "Seri {$record->serial_number} tidak lagi tersedia (status: {$record->status})."
            );
        }

        if (PendingDispatchSerialGuard::isReserved((string) $record->serial_number)) {
            throw new PosCheckoutValidationException(
                'SERIAL_INVALID',
                "Seri {$record->serial_number} sedang dalam proses pengiriman lain."
            );
        }

        $allowedLocationIds = SalesLocationResolver::resolveLocationIds($settingId)->all();
        if (! in_array((int) $record->location_id, $allowedLocationIds, true)) {
            throw new PosCheckoutValidationException(
                'SERIAL_INVALID',
                "Seri {$record->serial_number} berada di lokasi yang tidak diizinkan."
            );
        }
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
        array $serialRecords = [],
        ?int $saleDetailId = null
    ): void {
        // Preload product for better error messages
        $product = Product::query()->whereKey($productId)->first();
        $productLabel = $product
            ? ($product->product_name ?: ($product->product_code ?: "#$productId"))
            : "#$productId";

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
                throw new PosCheckoutValidationException('STOCK_UNAVAILABLE', "Stok produk $productLabel tidak tersedia di lokasi sumber.");
            }

            if ((int) $stock->quantity < $chunkQty) {
                throw new PosCheckoutValidationException('STOCK_UNAVAILABLE', "Stok produk $productLabel tidak cukup di lokasi sumber.");
            }

            if ($taxBucketUsed && (int) $stock->quantity_tax < $chunkQty) {
                throw new PosCheckoutValidationException('STOCK_UNAVAILABLE', "Stok pajak produk $productLabel tidak cukup di lokasi sumber.");
            }

            if (! $taxBucketUsed && (int) $stock->quantity_non_tax < $chunkQty) {
                throw new PosCheckoutValidationException('STOCK_UNAVAILABLE', "Stok non-pajak produk $productLabel tidak cukup di lokasi sumber.");
            }

            $assignedSerialsForChunk = $chunk['serial_numbers'] ?? null;

            $dispatchDetail = DispatchDetail::query()->create([
                'dispatch_id' => $dispatch->id,
                'sale_id' => $sale->id,
                'sale_detail_id' => $saleDetailId,
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
