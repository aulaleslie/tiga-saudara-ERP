<?php

namespace Modules\Pos\Services;

use App\Support\SalesLocationResolver;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\ProductUnitConversionPrice;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;

class PosCartService
{
    public function __construct(
        private readonly PosCartSessionStore $cartSessionStore,
        private readonly PosCartTotalsCalculator $totalsCalculator,
        private readonly PosSupervisorApprovalService $approvalService,
        private readonly PosCheckoutCustomerResolverService $customerResolver
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshot(int $settingId, int $sessionId): array
    {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);

        return $this->buildSnapshot($settingId, $sessionId, $cart);
    }

    /**
     * @return array<string, mixed>
     */
    public function addLine(int $settingId, int $sessionId, int $productId, int $qty = 1, ?int $conversionId = null): array
    {
        if ($qty < 1) {
            throw new DomainException('Quantity must be at least 1.');
        }

        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);
        [$product, $availableQty] = $this->resolveCartProduct($settingId, $productId);

        // Resolve conversion if provided
        $conversion = null;
        if ($conversionId !== null && $conversionId > 0) {
            $conversion = ProductUnitConversion::query()
                ->where('id', $conversionId)
                ->where('product_id', $productId)
                ->with('unit')
                ->first();

            if (! $conversion) {
                throw new DomainException('Conversion unit not found for this product.');
            }
        }

        // Resolve pricing: conversion price (if provided) or base product price
        $unitPrice = 0.0;
        $priceSource = 'BASE';
        $taxId = null;
        $taxName = null;
        $taxRate = 0.0;
        $conversionUnitName = null;

        if ($conversion !== null) {
            // Use conversion-specific pricing
            $conversionPrice = ProductUnitConversionPrice::query()
                ->where('product_unit_conversion_id', $conversion->id)
                ->where('setting_id', $settingId)
                ->first();

            if ($conversionPrice) {
                $unitPrice = (float) $conversionPrice->price;
                $priceSource = 'CONVERSION';
            } else {
                // Fallback to base product price if conversion price not found
                $unitPrice = (float) ($product->product_price ?? 0);
                $priceSource = 'CONVERSION_FALLBACK';
            }

            $conversionUnitName = $conversion->unit ? (string) $conversion->unit->name : 'Unit';

            // For conversion lines, use base product tax (conversions don't have separate tax)
            $priceRow = ProductPrice::query()
                ->forProduct($product->id)
                ->forSetting($settingId)
                ->first();
            $saleTaxId = (int) ($priceRow?->sale_tax_id ?? 0);
            $tax = $saleTaxId > 0 ? Tax::query()->find($saleTaxId) : null;
            $taxId = $tax ? (int) $tax->id : null;
            $taxName = $tax ? (string) $tax->name : null;
            $taxRate = $tax ? (float) $tax->value : 0.0;
        } else {
            // Use base product pricing
            $priceRow = ProductPrice::query()
                ->forProduct($product->id)
                ->forSetting($settingId)
                ->first();

            $saleTaxId = (int) ($priceRow?->sale_tax_id ?? 0);
            $tax = $saleTaxId > 0 ? Tax::query()->find($saleTaxId) : null;
            $unitPrice = (float) ($priceRow?->sale_price ?? $product->product_price ?? 0);
            $taxId = $tax ? (int) $tax->id : null;
            $taxName = $tax ? (string) $tax->name : null;
            $taxRate = $tax ? (float) $tax->value : 0.0;
        }

        // Build merge key to determine if we should merge with existing line
        // Include conversion_id in key so base and conversion lines don't merge
        $mergeKey = $this->buildMergeKey($product->id, $unitPrice, $taxId, $conversionId);

        // Look for existing line with matching merge key (handles backwards compat + new format)
        $existingLineId = null;
        $existingLine = null;
        foreach ($cart['lines'] as $lineId => $line) {
            if (($line['merge_key'] ?? null) === $mergeKey) {
                $existingLineId = $lineId;
                $existingLine = $line;
                break;
            }
        }

        if ($existingLine !== null) {
            // Line exists with matching merge key - increment qty
            $baseQty = (int) ($existingLine['qty'] ?? 0);
            $newQty = $baseQty + $qty;

            if ($newQty > $availableQty) {
                throw new DomainException('Requested quantity exceeds available stock for configured sales locations.');
            }

            $cart['lines'][$existingLineId] = array_merge($existingLine, [
                'qty' => $newQty,
                'available_qty' => $availableQty,
            ]);
        } else {
            // No matching line - create new line with next_line_id
            $newLineId = $cart['next_line_id']++;

            if ($qty > $availableQty) {
                throw new DomainException('Requested quantity exceeds available stock for configured sales locations.');
            }

            $cart['lines'][$newLineId] = [
                'line_id' => $newLineId,
                'product_id' => (int) $product->id,
                'product_name' => (string) $product->product_name,
                'product_code' => (string) ($product->product_code ?? ''),
                'barcode' => $product->barcode !== null ? (string) $product->barcode : null,
                'serial_number_required' => (bool) $product->serial_number_required,
                'assigned_serials' => [],
                'qty' => $qty,
                'available_qty' => $availableQty,
                'unit_price' => round($unitPrice, 2),
                'line_discount_type' => 'fixed',
                'line_discount_value' => 0.0,
                'tax_id' => $taxId,
                'tax_name' => $taxName,
                'tax_rate' => $taxRate,
                'merge_key' => $mergeKey,
                'price_source' => $priceSource,
                'price_valid' => true,
                'price_error' => null,
                'conversion_id' => $conversionId,
                'conversion_unit_name' => $conversionUnitName,
            ];
        }

        $this->cartSessionStore->putCart($settingId, $sessionId, $cart);

        return $this->buildSnapshot($settingId, $sessionId, $cart);
    }

    /**
     * @param  array{
     *     qty?: int,
     *     line_discount_type?: string,
     *     line_discount_value?: float|int|string
     * }  $payload
     * @return array<string, mixed>
     */
    public function updateLine(int $settingId, int $sessionId, int $lineId, array $payload): array
    {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);

        // Try to find line by line_id first
        $line = $cart['lines'][$lineId] ?? null;

        // Fallback: for backward compat, if not found by line_id, try to find by product_id when unambiguous
        if ($line === null) {
            $matchingLines = array_filter(
                $cart['lines'],
                fn (array $l): bool => ($l['product_id'] ?? 0) === $lineId
            );
            if (count($matchingLines) === 1) {
                $line = reset($matchingLines);
                $lineId = key($matchingLines);
            }
        }

        if ($line === null) {
            throw new DomainException('Cart line was not found.');
        }

        $qty = (int) ($payload['qty'] ?? $line['qty']);
        if ($qty < 1) {
            throw new DomainException('Quantity must be at least 1.');
        }

        // Guard: prevent qty reduction below assigned serial count for serial-required products
        $assignedSerials = (array) ($line['assigned_serials'] ?? []);
        $serialCount = count($assignedSerials);
        if ((bool) ($line['serial_number_required'] ?? false) && $qty < $serialCount) {
            throw new DomainException("Cannot reduce quantity below assigned serial count ($serialCount). Remove serials first.");
        }

        $availableQty = (int) ($line['available_qty'] ?? 0);
        if ($availableQty > 0 && $qty > $availableQty) {
            throw new DomainException('Requested quantity exceeds available stock for configured sales locations.');
        }

        $discountType = $payload['line_discount_type'] ?? $line['line_discount_type'] ?? 'fixed';
        $discountValue = (float) ($payload['line_discount_value'] ?? $line['line_discount_value'] ?? 0);

        // Clear assigned serials if qty changed (for non-serial products, this is a no-op)
        if ($qty !== (int) $line['qty']) {
            $assignedSerials = [];
        }

        $cart['lines'][$lineId] = array_merge($line, [
            'qty' => $qty,
            'assigned_serials' => $assignedSerials,
            'line_discount_type' => $this->normalizeDiscountType((string) $discountType),
            'line_discount_value' => round(max(0.0, $discountValue), 2),
        ]);

        $this->cartSessionStore->putCart($settingId, $sessionId, $cart);

        return $this->buildSnapshot($settingId, $sessionId, $cart);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeLine(int $settingId, int $sessionId, int $lineId): array
    {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);

        // Try to find line by line_id first
        $line = $cart['lines'][$lineId] ?? null;

        // Fallback: for backward compat, if not found by line_id, try to find by product_id when unambiguous
        if ($line === null) {
            $matchingLines = array_filter(
                $cart['lines'],
                fn (array $l): bool => ($l['product_id'] ?? 0) === $lineId
            );
            if (count($matchingLines) === 1) {
                $lineId = key($matchingLines);
                $line = reset($matchingLines);
            }
        }

        if ($line === null) {
            throw new DomainException('Cart line was not found.');
        }

        unset($cart['lines'][$lineId]);
        $this->cartSessionStore->putCart($settingId, $sessionId, $cart);

        return $this->buildSnapshot($settingId, $sessionId, $cart);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateBillDiscount(
        int $settingId,
        int $sessionId,
        string $billDiscountType,
        float $billDiscountValue
    ): array {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);

        $cart['bill_discount_type'] = $this->normalizeDiscountType($billDiscountType);
        $cart['bill_discount_value'] = round(max(0.0, $billDiscountValue), 2);

        $this->cartSessionStore->putCart($settingId, $sessionId, $cart);

        return $this->buildSnapshot($settingId, $sessionId, $cart);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateCustomerSelection(int $settingId, int $sessionId, ?int $customerId): array
    {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);

        if ($customerId !== null) {
            $isValidCustomer = Customer::query()
                ->where('setting_id', $settingId)
                ->whereKey($customerId)
                ->exists();

            if (! $isValidCustomer) {
                throw new DomainException('Selected customer is not valid for active setting.');
            }
        }

        $cart['selected_customer_id'] = $customerId;

        // Reprice all non-OVERRIDE lines based on new customer tier
        $repricedLines = [];
        foreach ($cart['lines'] as $lineId => $line) {
            $priceSource = (string) ($line['price_source'] ?? 'BASE');

            // Skip OVERRIDE lines - keep existing price
            if ($priceSource === 'OVERRIDE') {
                $repricedLines[$lineId] = $line;
                continue;
            }

            $productId = (int) ($line['product_id'] ?? 0);
            $currentTaxId = $line['tax_id'] ?? null;

            // Resolve new price for this line with customer tier
            $priceResolution = $this->resolveLinePrice($settingId, $productId, $customerId, $currentTaxId);

            if (! (bool) ($priceResolution['price_valid'] ?? false)) {
                // Price resolution failed - keep line as-is
                $repricedLines[$lineId] = $line;
                continue;
            }

            // Update line with new price and tax info
            $newUnitPrice = (float) ($priceResolution['unit_price'] ?? 0);
            $newTaxId = $priceResolution['tax_id'];
            $conversionIdForKey = (int) ($line['conversion_id'] ?? 0) > 0 ? (int) $line['conversion_id'] : null;
            $newMergeKey = $this->buildMergeKey($productId, $newUnitPrice, $newTaxId, $conversionIdForKey);

            $line['unit_price'] = round($newUnitPrice, 2);
            $line['tax_id'] = $newTaxId;
            $line['tax_name'] = (string) ($priceResolution['tax_name'] ?? null);
            $line['tax_rate'] = (float) ($priceResolution['tax_rate'] ?? 0);
            $line['merge_key'] = $newMergeKey;
            $line['price_source'] = 'TIER';

            $repricedLines[$lineId] = $line;
        }

        // Merge lines with colliding merge_keys
        $mergedLines = [];
        $mergeKeyMap = []; // Maps merge_key -> first line_id with that key

        foreach ($repricedLines as $lineId => $line) {
            $mergeKey = (string) ($line['merge_key'] ?? '');

            if (isset($mergeKeyMap[$mergeKey])) {
                // Collision detected - merge into existing line
                $targetLineId = $mergeKeyMap[$mergeKey];
                $targetLine = $mergedLines[$targetLineId];

                // Merge quantities and serials
                $targetLine['qty'] = (int) ($targetLine['qty'] ?? 0) + (int) ($line['qty'] ?? 0);
                $targetLine['assigned_serials'] = array_merge(
                    (array) ($targetLine['assigned_serials'] ?? []),
                    (array) ($line['assigned_serials'] ?? [])
                );
                $mergedLines[$targetLineId] = $targetLine;
            } else {
                // No collision - add as new line
                $mergedLines[$lineId] = $line;
                $mergeKeyMap[$mergeKey] = $lineId;
            }
        }

        $cart['lines'] = $mergedLines;
        $this->cartSessionStore->putCart($settingId, $sessionId, $cart);

        return $this->buildSnapshot($settingId, $sessionId, $cart);
    }

    /**
     * @return array<string, mixed>
     */
    public function overrideLinePrice(
        int $settingId,
        int $sessionId,
        int $requestedBy,
        int $lineId,
        float $unitPrice,
        string $supervisorIdentifier,
        string $supervisorPin
    ): array {
        if ($unitPrice <= 0) {
            throw new DomainException('Unit price must be greater than zero.');
        }

        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);

        // Try to find line by line_id first
        $line = $cart['lines'][$lineId] ?? null;

        // Fallback: for backward compat, if not found by line_id, try to find by product_id when unambiguous
        if ($line === null) {
            $matchingLines = array_filter(
                $cart['lines'],
                fn (array $l): bool => ($l['product_id'] ?? 0) === $lineId
            );
            if (count($matchingLines) === 1) {
                $lineId = key($matchingLines);
                $line = reset($matchingLines);
            }
        }

        if ($line === null) {
            throw new DomainException('Cart line was not found.');
        }

        $currentUnitPrice = (float) ($line['unit_price'] ?? 0);

        $approval = $this->approvalService->approvePriceOverride(
            $settingId,
            $sessionId,
            $requestedBy,
            $supervisorIdentifier,
            $supervisorPin,
            $lineId,
            $currentUnitPrice,
            $unitPrice
        );

        if (! (bool) ($approval['approved'] ?? false)) {
            throw new DomainException('Supervisor approval failed for price override.');
        }

        $cart['lines'][$lineId] = array_merge($line, [
            'unit_price' => round($unitPrice, 2),
            'price_source' => 'OVERRIDE',
        ]);

        $this->cartSessionStore->putCart($settingId, $sessionId, $cart);

        return $this->buildSnapshot($settingId, $sessionId, $cart);
    }

    /**
     * @param  array<int, string>  $serialNumbers
     * @return array<string, mixed>
     */
    public function assignSerials(int $settingId, int $sessionId, int $lineId, array $serialNumbers): array
    {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);

        // Try to find line by line_id first
        $line = $cart['lines'][$lineId] ?? null;

        // Fallback: for backward compat, if not found by line_id, try to find by product_id when unambiguous
        if ($line === null) {
            $matchingLines = array_filter(
                $cart['lines'],
                fn (array $l): bool => ($l['product_id'] ?? 0) === $lineId
            );
            if (count($matchingLines) === 1) {
                $lineId = key($matchingLines);
                $line = reset($matchingLines);
            }
        }

        if ($line === null) {
            throw new DomainException('Cart line was not found.');
        }

        if (! (bool) ($line['serial_number_required'] ?? false)) {
            throw new DomainException('This product does not require serial numbers.');
        }

        $qty = (int) ($line['qty'] ?? 0);
        // Allow incremental serial assignment: count($serialNumbers) must be <= qty
        if (count($serialNumbers) > $qty) {
            throw new DomainException("Serial count ($(" . count($serialNumbers) . ")) exceeds line quantity ($qty).");
        }

        if (count($serialNumbers) !== count(array_unique($serialNumbers))) {
            throw new DomainException('Duplicate serial numbers provided.');
        }

        $productId = (int) ($line['product_id'] ?? 0);
        $taxId = $line['tax_id'] ?? null;
        $allowedLocationIds = SalesLocationResolver::resolveLocationIds($settingId)->all();

        // Check for duplicates across all cart lines
        $allAssignedSerials = [];
        foreach ($cart['lines'] as $cartLine) {
            $allAssignedSerials = array_merge($allAssignedSerials, (array) ($cartLine['assigned_serials'] ?? []));
        }

        foreach ($serialNumbers as $sn) {
            $record = \Modules\Product\Entities\ProductSerialNumber::query()
                ->where('product_id', $productId)
                ->where('serial_number', $sn)
                ->first();

            if (! $record) {
                throw new DomainException("Serial number $sn does not exist for this product.");
            }

            if (strtoupper($record->status) !== 'ACTIVE' || $record->dispatch_detail_id !== null) {
                throw new DomainException("Serial number $sn is not available (status: {$record->status}).");
            }

            if (! in_array((int) $record->location_id, $allowedLocationIds, true)) {
                throw new DomainException("Serial number $sn is located in a restricted location.");
            }

            // Check if serial already assigned in another cart line
            if (in_array($sn, $allAssignedSerials, true)) {
                throw new DomainException("Serial number $sn is already assigned in this cart.");
            }

            // Tax match validation
            $isTaxedItem = ! empty($taxId) && (int) $taxId > 0;
            if ($isTaxedItem && $record->tax_id === null) {
                throw new DomainException("Serial number $sn is non-taxed, but line is taxed.");
            }
            if (! $isTaxedItem && $record->tax_id !== null) {
                throw new DomainException("Serial number $sn is taxed, but line is non-taxed.");
            }
        }

        $cart['lines'][$lineId]['assigned_serials'] = $serialNumbers;
        $this->cartSessionStore->putCart($settingId, $sessionId, $cart);

        return $this->buildSnapshot($settingId, $sessionId, $cart);
    }

    /**
     * @return array<int, array{id: int, serial_number: string}>
     */
    public function availableSerialsForProduct(int $settingId, int $productId, string $query, int $limit = 10): array
    {
        $allowedLocationIds = SalesLocationResolver::resolveLocationIds($settingId)->all();

        return \Modules\Product\Entities\ProductSerialNumber::query()
            ->where('product_id', $productId)
            ->whereIn('location_id', $allowedLocationIds)
            ->where('status', 'ACTIVE')
            ->whereNull('dispatch_detail_id')
            ->when($query !== '', fn ($q) => $q->where('serial_number', 'like', "%$query%"))
            ->limit($limit)
            ->get(['id', 'serial_number'])
            ->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function clear(int $settingId, int $sessionId): array
    {
        $cart = $this->cartSessionStore->emptyCart($settingId, $sessionId);
        $this->cartSessionStore->putCart($settingId, $sessionId, $cart);

        return $this->buildSnapshot($settingId, $sessionId, $cart);
    }

    /**
     * @param  array<string, mixed>  $cart
     * @return array<string, mixed>
     */
    private function buildSnapshot(int $settingId, int $sessionId, array $cart): array
    {
        $isPkp = (bool) (Setting::query()->whereKey($settingId)->value('is_pkp') ?? false);
        $lines = array_values($cart['lines'] ?? []);
        $selectedCustomerId = isset($cart['selected_customer_id']) ? (int) $cart['selected_customer_id'] : null;
        $selectedCustomerId = $selectedCustomerId > 0 ? $selectedCustomerId : null;

        $calculated = $this->totalsCalculator->calculate(
            lines: $lines,
            billDiscount: [
                'type' => (string) ($cart['bill_discount_type'] ?? 'fixed'),
                'value' => (float) ($cart['bill_discount_value'] ?? 0),
            ],
            isPkp: $isPkp
        );

        $totalQty = array_sum(array_map(
            fn (array $line): int => max(0, (int) ($line['qty'] ?? 0)),
            $calculated['lines']
        ));
        $customerResolution = $this->customerResolver->resolve($settingId, $selectedCustomerId);

        return [
            'setting_id' => $settingId,
            'session_id' => $sessionId,
            'lines' => $calculated['lines'],
            'bill_discount' => [
                'type' => (string) ($cart['bill_discount_type'] ?? 'fixed'),
                'value' => round((float) ($cart['bill_discount_value'] ?? 0), 2),
            ],
            'totals' => $calculated['totals'],
            'customer' => $customerResolution,
            'meta' => [
                'line_count' => count($calculated['lines']),
                'total_qty' => $totalQty,
                'tax_display_mode' => 'ESTIMATED',
                'tax_mode' => $isPkp ? 'INCLUDED' : 'EXCLUDED',
            ],
        ];
    }

    /**
     * @return array{0: Product, 1: int}
     */
    private function resolveCartProduct(int $settingId, int $productId): array
    {
        $product = Product::query()
            ->where('id', $productId)
            ->where('stock_managed', true)
            ->first();

        if (! $product) {
            throw new DomainException('Product was not found.');
        }

        // Guard: product must have a price row for the active setting
        $hasPriceRow = ProductPrice::query()
            ->forProduct($productId)
            ->forSetting($settingId)
            ->exists();

        if (! $hasPriceRow) {
            throw new DomainException('Product does not have a price configured for the active setting.');
        }

        $allowedLocationIds = SalesLocationResolver::resolveLocationIds($settingId)
            ->map(fn ($locationId): int => (int) $locationId)
            ->filter(fn (int $locationId): bool => $locationId > 0)
            ->values()
            ->all();

        if ($allowedLocationIds === []) {
            throw new DomainException('No sales location is configured for active setting.');
        }

        $availableQty = (int) DB::table('product_stocks')
            ->where('product_id', $productId)
            ->whereIn('location_id', $allowedLocationIds)
            ->sum('quantity');

        if ($availableQty <= 0) {
            throw new DomainException('Product stock is not available in configured sales locations.');
        }

        return [$product, $availableQty];
    }

    private function normalizeDiscountType(string $type): string
    {
        return strtolower($type) === 'percentage' ? 'percentage' : 'fixed';
    }

    /**
     * Append a single serial number to a cart line.
     * If the line is full (assigned count == qty), auto-increment qty first.
     *
     * @return array<string, mixed>
     */
    public function appendSerial(int $settingId, int $sessionId, int $lineId, string $serialNumber): array
    {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);

        // Try to find line by line_id first
        $line = $cart['lines'][$lineId] ?? null;

        // Fallback: for backward compat, if not found by line_id, try to find by product_id when unambiguous
        if ($line === null) {
            $matchingLines = array_filter(
                $cart['lines'],
                fn (array $l): bool => ($l['product_id'] ?? 0) === $lineId
            );
            if (count($matchingLines) === 1) {
                $lineId = key($matchingLines);
                $line = reset($matchingLines);
            }
        }

        if ($line === null) {
            throw new DomainException('Cart line was not found.');
        }

        if (! (bool) ($line['serial_number_required'] ?? false)) {
            throw new DomainException('This product does not require serial numbers.');
        }

        // Validate serial exists and is available
        $productId = (int) ($line['product_id'] ?? 0);
        $taxId = $line['tax_id'] ?? null;
        $allowedLocationIds = SalesLocationResolver::resolveLocationIds($settingId)->all();

        $record = \Modules\Product\Entities\ProductSerialNumber::query()
            ->where('product_id', $productId)
            ->where('serial_number', $serialNumber)
            ->first();

        if (! $record) {
            throw new DomainException("Serial number $serialNumber does not exist for this product.");
        }

        if (strtoupper($record->status) !== 'ACTIVE' || $record->dispatch_detail_id !== null) {
            throw new DomainException("Serial number $serialNumber is not available (status: {$record->status}).");
        }

        if (! in_array((int) $record->location_id, $allowedLocationIds, true)) {
            throw new DomainException("Serial number $serialNumber is located in a restricted location.");
        }

        // Check for duplicate across all cart lines
        $allAssignedSerials = [];
        foreach ($cart['lines'] as $cartLine) {
            $allAssignedSerials = array_merge($allAssignedSerials, (array) ($cartLine['assigned_serials'] ?? []));
        }

        if (in_array($serialNumber, $allAssignedSerials, true)) {
            throw new DomainException("Serial number $serialNumber is already assigned in this cart.");
        }

        // Tax match validation
        $isTaxedItem = ! empty($taxId) && (int) $taxId > 0;
        if ($isTaxedItem && $record->tax_id === null) {
            throw new DomainException("Serial number $serialNumber is non-taxed, but line is taxed.");
        }
        if (! $isTaxedItem && $record->tax_id !== null) {
            throw new DomainException("Serial number $serialNumber is taxed, but line is non-taxed.");
        }

        $assignedSerials = (array) ($line['assigned_serials'] ?? []);
        $qty = (int) ($line['qty'] ?? 0);

        // If line is full, auto-increment qty by 1
        if (count($assignedSerials) >= $qty) {
            $qty++;
            $cart['lines'][$lineId]['qty'] = $qty;
        }

        // Append the serial
        $assignedSerials[] = $serialNumber;
        $cart['lines'][$lineId]['assigned_serials'] = $assignedSerials;

        $this->cartSessionStore->putCart($settingId, $sessionId, $cart);

        return $this->buildSnapshot($settingId, $sessionId, $cart);
    }

    /**
     * Remove a serial number from a cart line.
     * Does NOT auto-decrement qty (user decides qty separately).
     *
     * @return array<string, mixed>
     */
    public function removeSerial(int $settingId, int $sessionId, int $lineId, string $serialNumber): array
    {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);

        // Try to find line by line_id first
        $line = $cart['lines'][$lineId] ?? null;

        // Fallback: for backward compat, if not found by line_id, try to find by product_id when unambiguous
        if ($line === null) {
            $matchingLines = array_filter(
                $cart['lines'],
                fn (array $l): bool => ($l['product_id'] ?? 0) === $lineId
            );
            if (count($matchingLines) === 1) {
                $lineId = key($matchingLines);
                $line = reset($matchingLines);
            }
        }

        if ($line === null) {
            throw new DomainException('Cart line was not found.');
        }

        $assignedSerials = (array) ($line['assigned_serials'] ?? []);
        $key = array_search($serialNumber, $assignedSerials, true);

        if ($key === false) {
            throw new DomainException("Serial number $serialNumber is not assigned to this line.");
        }

        unset($assignedSerials[$key]);
        $cart['lines'][$lineId]['assigned_serials'] = array_values($assignedSerials);

        $this->cartSessionStore->putCart($settingId, $sessionId, $cart);

        return $this->buildSnapshot($settingId, $sessionId, $cart);
    }

    /**
     * Resolve line price based on customer tier with strict setting-based pricing.
     * Tier prices override sale_price when non-zero; fallback to sale_price if tier price is 0/null.
     *
     * @param  int  $settingId
     * @param  int  $productId
     * @param  int|null  $customerId
     * @param  int|null  $taxId (override tax for line; if null, uses ProductPrice.sale_tax_id)
     * @return array{unit_price: float, tax_id: int|null, tax_name: string|null, tax_rate: float, price_valid: bool, price_error: string|null}
     */
    private function resolveLinePrice(int $settingId, int $productId, ?int $customerId, ?int $taxId = null): array
    {
        // Load ProductPrice for active setting - no fallback to product.product_price
        $priceRow = ProductPrice::query()
            ->forProduct($productId)
            ->forSetting($settingId)
            ->first();

        if (! $priceRow) {
            return [
                'unit_price' => 0.0,
                'tax_id' => null,
                'tax_name' => null,
                'tax_rate' => 0.0,
                'price_valid' => false,
                'price_error' => 'No price configured for active setting',
            ];
        }

        // Resolve customer tier
        $customerTier = null;
        if ($customerId !== null && $customerId > 0) {
            $customer = Customer::query()
                ->where('setting_id', $settingId)
                ->whereKey($customerId)
                ->select(['id', 'tier'])
                ->first();
            $customerTier = $customer ? (string) ($customer->tier ?? '') : null;
        }

        // Apply tier pricing with fallback
        $unitPrice = 0.0;
        if ($customerTier === 'WHOLESALER') {
            // Use tier_1_price if non-zero, else fallback to sale_price
            $tier1Price = (float) ($priceRow->tier_1_price ?? 0);
            $unitPrice = $tier1Price > 0 ? $tier1Price : (float) ($priceRow->sale_price ?? 0);
        } elseif ($customerTier === 'RESELLER') {
            // Use tier_2_price if non-zero, else fallback to sale_price
            $tier2Price = (float) ($priceRow->tier_2_price ?? 0);
            $unitPrice = $tier2Price > 0 ? $tier2Price : (float) ($priceRow->sale_price ?? 0);
        } else {
            // No tier or unrecognized tier - use base sale_price
            $unitPrice = (float) ($priceRow->sale_price ?? 0);
        }

        // Resolve tax - use override if provided, else use ProductPrice.sale_tax_id
        $resolveTaxId = $taxId ?? ((int) ($priceRow->sale_tax_id ?? 0) > 0 ? (int) $priceRow->sale_tax_id : null);
        $tax = $resolveTaxId !== null && $resolveTaxId > 0 ? Tax::query()->find($resolveTaxId) : null;

        return [
            'unit_price' => round($unitPrice, 2),
            'tax_id' => $tax ? (int) $tax->id : null,
            'tax_name' => $tax ? (string) $tax->name : null,
            'tax_rate' => $tax ? (float) $tax->value : 0.0,
            'price_valid' => true,
            'price_error' => null,
        ];
    }

    /**
     * Build merge key for line deduplication.
     *
     * @param  int  $productId
     * @param  float  $unitPrice
     * @param  int|null  $taxId
     * @param  int|null  $conversionId  Optional conversion ID to include in key
     * @return string
     */
    private function buildMergeKey(int $productId, float $unitPrice, ?int $taxId, ?int $conversionId = null): string
    {
        return "{$productId}:" . round($unitPrice, 2) . ":{$taxId}:{$conversionId}";
    }
}

