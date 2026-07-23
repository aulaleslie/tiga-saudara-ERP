<?php

namespace Modules\Pos\Services;

use App\Models\User;
use App\Support\SalesLocationResolver;
use DomainException;
use InvalidArgumentException;
use Modules\Pos\Entities\PosActionApprovalRequest;
use Modules\Pos\Entities\PosCartLine;
use Modules\Pos\Entities\PosSupervisorApproval;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Events\PosCartUpdated;
use Modules\Pos\Services\Exceptions\PosCartMutationException;
use Modules\Pos\Services\Exceptions\PosCheckoutValidationException;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\ProductUnitConversionPrice;
use Modules\Sale\Support\PendingDispatchSerialGuard;
use Illuminate\Support\Facades\DB;
use Modules\People\Entities\Customer;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Pos\Support\PosMergeKeyGenerator;

class PosCartService
{
    public function __construct(
        private readonly PosCartSessionStore $cartSessionStore,
        private readonly PosCartTotalsCalculator $totalsCalculator,
        private readonly PosCheckoutCustomerResolverService $customerResolver,
        private readonly PosCartActionAuthorizationService $actionAuthorizationService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshot(int $settingId, int $sessionId): array
    {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);

        // Generate staged_payment_token if it doesn't exist
        if (!isset($cart['staged_payment_token']) || empty($cart['staged_payment_token'])) {
            $cart['staged_payment_token'] = (string) \Illuminate\Support\Str::uuid();
            $this->cartSessionStore->putCart($settingId, $sessionId, $cart);
        }

        return $this->buildSnapshot($settingId, $sessionId, $cart);
    }

    /**
     * @return array<string, mixed>
     */
    public function addLine(int $settingId, int $sessionId, int $productId, int $qty = 1, ?int $conversionId = null, ?int $bundleId = null): array
    {
        if ($qty < 1) {
            throw new DomainException('Kuantitas harus minimal 1.');
        }

        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);
        $this->assertActiveTransactionIsMutable($settingId, $cart);
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
                throw new DomainException('Unit konversi tidak ditemukan untuk produk ini.');
            }
        }

        // Resolve bundle if provided
        $bundle = null;
        if ($bundleId !== null && $bundleId > 0) {
            $bundle = ProductBundle::query()
                ->where('id', $bundleId)
                ->where('parent_product_id', $productId)
                ->where('setting_id', $settingId)
                ->with('items.product')
                ->first();

            if (! $bundle) {
                throw new DomainException('Paket tidak ditemukan untuk produk ini.');
            }
        }

        // Resolve pricing: conversion price (if provided) or base product price
        $unitPrice = 0.0;
        $lineTotal = 0;
        $bundlePrice = 0.0;
        $priceSource = 'BASE';
        $taxId = null;
        $taxName = null;
        $taxRate = 0.0;
        $conversionUnitName = null;
        $pricingBasis = null;
        $breakdown = null;

        $selectedCustomerId = isset($cart['selected_customer_id']) ? (int) $cart['selected_customer_id'] : null;
        $selectedCustomerTier = $cart['selected_customer_tier'] ?? null;

        if ($conversion !== null) {
            $qty = $qty * max(1, (int) $conversion->conversion_factor);
            $conversionUnitName = $conversion->unit ? (string) $conversion->unit->name : 'Unit';
        }

        if ($bundle !== null) {
            // Use the final bundle_sale_price instead of legacy parent + add-on price.
            $unitPrice = round((float) ($bundle->bundle_sale_price ?? 0), 2);
            $bundlePrice = round((float) ($bundle->price ?? 0), 2);
            $priceSource = 'BUNDLE';

            $priceResolution = $this->resolveLinePrice($settingId, $product->id, $selectedCustomerId);
            $taxId = $priceResolution['tax_id'];
            $taxName = $priceResolution['tax_name'];
            $taxRate = (float) ($priceResolution['tax_rate'] ?? 0.0);
        } else {
            // Check if product has box conversion - if so, use packing pricing
            $pricingBasis = $this->buildPricingBasis($settingId, $product->id, $selectedCustomerId);

            if ($pricingBasis !== null) {
                // Product has box conversion - use packing pricing
                // Use cached tier to avoid DB query
                $pricingService = new PackedLinePricingService();
                $priceResult = $pricingService->price($qty, $selectedCustomerTier, $pricingBasis);

                $lineTotal = $priceResult['line_total_minor'];
                $unitPrice = $priceResult['blended_unit_price'] / 100.0;
                $breakdown = $priceResult['breakdown'];
                $priceSource = 'PACKED';

                $taxId = $pricingBasis['tax_id'];
                $taxName = $pricingBasis['tax_name'];
                $taxRate = $pricingBasis['tax_rate'];
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
        }

        // Build merge key to determine if we should merge with existing line
        // Use cached tier to avoid DB query
        $mergeKey = PosMergeKeyGenerator::build(
            $product->id,
            $unitPrice,
            $taxId,
            $conversionId,
            $bundleId,
            $selectedCustomerTier,
            $priceSource
        );

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
                throw new DomainException('Kuantitas yang diminta melebihi stok tersedia untuk lokasi penjualan yang dikonfigurasi.');
            }

            // For packed lines, re-pack the total quantity
            $updatedLine = array_merge($existingLine, [
                'qty' => $newQty,
                'available_qty' => $availableQty,
            ]);

            if ($priceSource === 'PACKED' && $pricingBasis !== null) {
                // Use cached tier to avoid DB query
                $pricingService = new PackedLinePricingService();
                $priceResult = $pricingService->price($newQty, $selectedCustomerTier, $pricingBasis);

                $updatedLine['unit_price'] = round($priceResult['blended_unit_price'] / 100.0, 2);
                $updatedLine['line_total'] = $priceResult['line_total_minor'];
                $updatedLine['breakdown'] = $priceResult['breakdown'];
            }

            $cart['lines'][$existingLineId] = $updatedLine;
        } else {
            // No matching line - create new line with next_line_id
            $newLineId = $cart['next_line_id']++;

            if ($qty > $availableQty) {
                throw new DomainException('Kuantitas yang diminta melebihi stok tersedia untuk lokasi penjualan yang dikonfigurasi.');
            }

            $lineData = [
                'line_id' => $newLineId,
                'product_id' => (int) $product->id,
                'product_name' => (string) $product->product_name,
                'product_code' => (string) ($product->product_code ?? ''),
                'barcode' => $product->barcode !== null ? (string) $product->barcode : null,
                'stock_managed' => (bool) $product->stock_managed,
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
                'bundle_id' => $bundleId,
                'bundle_name' => $bundle ? (string) $bundle->name : null,
                'bundle_price' => $bundlePrice,
                'bundle_items' => $bundle ? $bundle->items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->product_name,
                    'quantity' => $item->quantity,
                    'stock_managed' => (bool) $item->product->stock_managed,
                    'serial_number_required' => (bool) $item->product->serial_number_required,
                    'informational_item_price' => (float) ($item->informational_item_price ?? 0),
                ])->toArray() : [],
            ];

            // Add packed pricing data if applicable
            if ($priceSource === 'PACKED' && $lineTotal > 0 && $breakdown !== null) {
                $lineData['line_total'] = $lineTotal;
                $lineData['breakdown'] = $breakdown;
                $lineData['pricing_basis'] = $pricingBasis;
            }

            $cart['lines'][$newLineId] = $lineData;
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
    public function updateLine(int $settingId, int $sessionId, int $lineId, array $payload, ?string $approvalToken = null, ?User $user = null): array
    {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);
        $this->assertActiveTransactionIsMutable($settingId, $cart);

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
            throw new DomainException('Baris keranjang tidak ditemukan.');
        }

        $qty = (int) ($payload['qty'] ?? $line['qty']);
        if ($qty < 1) {
            throw new DomainException('Kuantitas harus minimal 1.');
        }

        // Backend validation: Enforce quantity reduction approval for non-privileged users
        // The frontend prevents direct reduction via input for non-privileged users,
        // but this backend check ensures defense-in-depth against API manipulation.
        if ($qty < (int) $line['qty']) {
            if ($user) {
                $this->actionAuthorizationService->authorize(
                    $user,
                    PosActionApprovalRequest::ACTION_QTY_REDUCE,
                    $approvalToken
                );
            } else {
                throw new DomainException('Jumlah qty tidak dapat dikurangi tanpa otorisasi.');
            }
        }

        // Preserve assigned serials - they will be validated at checkout time
        $assignedSerials = (array) ($line['assigned_serials'] ?? []);

        $availableQty = (int) ($line['available_qty'] ?? 0);
        if ($availableQty > 0 && $qty > $availableQty) {
            throw new DomainException('Requested quantity exceeds available stock for configured sales locations.');
        }

        $discountType = $payload['line_discount_type'] ?? $line['line_discount_type'] ?? 'fixed';
        $discountValue = (float) ($payload['line_discount_value'] ?? $line['line_discount_value'] ?? 0);

        // Preserve assigned serials across qty changes. Serial/qty mismatch is validated at checkout time.
        $updatedLine = array_merge($line, [
            'qty' => $qty,
            'assigned_serials' => $assignedSerials,
            'line_discount_type' => $this->normalizeDiscountType((string) $discountType),
            'line_discount_value' => round(max(0.0, $discountValue), 2),
        ]);

        // For packed lines, re-pack the total quantity from cached pricing_basis
        if (($line['price_source'] ?? null) === 'PACKED' && isset($line['pricing_basis'])) {
            $pricingBasis = $line['pricing_basis'];
            // Use cached tier to avoid DB query
            $tier = $cart['selected_customer_tier'] ?? null;
            $pricingService = new PackedLinePricingService();
            $priceResult = $pricingService->price($qty, $tier, $pricingBasis);

            $updatedLine['unit_price'] = round($priceResult['blended_unit_price'] / 100.0, 2);
            $updatedLine['line_total'] = $priceResult['line_total_minor'];
            $updatedLine['breakdown'] = $priceResult['breakdown'];
        }

        $cart['lines'][$lineId] = $updatedLine;

        $this->cartSessionStore->putCart($settingId, $sessionId, $cart);

        return $this->buildSnapshot($settingId, $sessionId, $cart);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeLine(int $settingId, int $sessionId, int $lineId, ?string $approvalToken = null, ?User $user = null): array
    {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);
        $this->assertActiveTransactionIsMutable($settingId, $cart);

        if ($user) {
            $this->actionAuthorizationService->authorize(
                $user,
                PosActionApprovalRequest::ACTION_LINE_REMOVE,
                $approvalToken
            );
        }

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
            throw new DomainException('Baris keranjang tidak ditemukan.');
        }

        // Check if this would violate loaded transaction empty constraint
        $this->assertNotLastLineOfLoadedTransaction($cart, $lineId);

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
        $this->assertActiveTransactionIsMutable($settingId, $cart);

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
        $this->assertActiveTransactionIsMutable($settingId, $cart);

        $customerTier = null;
        if ($customerId !== null) {
            $customer = Customer::query()
                ->whereKey($customerId)
                ->select(['id', 'tier'])
                ->first();

            if (! $customer) {
                throw new DomainException('Pelanggan yang dipilih tidak valid.');
            }

            $customerTier = (string) ($customer->tier ?? '');
        }

        $cart['selected_customer_id'] = $customerId;
        $cart['selected_customer_tier'] = $customerTier;

        // Reprice all non-OVERRIDE lines based on new customer tier
        $repricedLines = [];
        foreach ($cart['lines'] as $lineId => $line) {
            $priceSource = (string) ($line['price_source'] ?? 'BASE');

            // Skip OVERRIDE or BUNDLE lines - keep existing price.
            // Bundled row prices are authoritative and bypass customer tier repricing.
            if ($priceSource === 'OVERRIDE' || $priceSource === 'BUNDLE') {
                $repricedLines[$lineId] = $line;
                continue;
            }

            // For PACKED lines, re-pack using cached pricing_basis with new tier
            if ($priceSource === 'PACKED' && isset($line['pricing_basis'])) {
                $pricingBasis = $line['pricing_basis'];
                $qty = (int) ($line['qty'] ?? 0);
                // Use cached tier to avoid DB query
                $pricingService = new PackedLinePricingService();
                $priceResult = $pricingService->price($qty, $customerTier, $pricingBasis);

                $line['unit_price'] = round($priceResult['blended_unit_price'] / 100.0, 2);
                $line['line_total'] = $priceResult['line_total_minor'];
                $line['breakdown'] = $priceResult['breakdown'];
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
            $bundlePrice = round((float) ($line['bundle_price'] ?? 0), 2);
            if ((int) ($line['bundle_id'] ?? 0) > 0) {
                $newUnitPrice = round($newUnitPrice + $bundlePrice, 2);
            }
            $newTaxId = $priceResolution['tax_id'];
            $conversionIdForKey = (int) ($line['conversion_id'] ?? 0) > 0 ? (int) $line['conversion_id'] : null;
            $bundleIdForKey = (int) ($line['bundle_id'] ?? 0) > 0 ? (int) $line['bundle_id'] : null;
            $newMergeKey = $this->buildMergeKey($productId, $newUnitPrice, $newTaxId, $conversionIdForKey, $bundleIdForKey);

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
        ?string $approvalToken = null,
        ?User $user = null
    ): array {
        if ($unitPrice < 0) {
            throw new DomainException('Harga satuan tidak boleh negatif.');
        }

        if (! $user) {
            throw new DomainException('Otentikasi diperlukan.');
        }

        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);
        $this->assertActiveTransactionIsMutable($settingId, $cart);

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
            throw new DomainException('Baris keranjang tidak ditemukan.');
        }

        $authorization = $this->actionAuthorizationService->authorize(
            $user,
            PosActionApprovalRequest::ACTION_PRICE_OVERRIDE,
            $approvalToken
        );

        $approvedRequest = $authorization['request'];
        $targetUnitPrice = round($unitPrice, 2);

        if ($approvedRequest instanceof PosActionApprovalRequest) {
            if ((int) $approvedRequest->target_id !== $lineId) {
                throw new DomainException('TOKEN_TIDAK_VALID_ATAU_KEDALUWARSA');
            }

            $requestedUnitPrice = round((float) ($approvedRequest->request_payload['unit_price'] ?? 0), 2);
            if ($requestedUnitPrice < 0) {
                throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
            }

            $targetUnitPrice = $requestedUnitPrice;
        } else {
            $this->recordDirectPriceOverrideApproval(
                $settingId,
                $sessionId,
                $requestedBy,
                $lineId,
                (float) ($line['unit_price'] ?? 0),
                $targetUnitPrice
            );
        }

        if ($targetUnitPrice < 0) {
            throw new DomainException('Harga satuan tidak boleh negatif.');
        }

        $cart['lines'][$lineId] = array_merge($line, [
            'unit_price' => $targetUnitPrice,
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
        $this->assertActiveTransactionIsMutable($settingId, $cart);

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
            throw new DomainException('Baris keranjang tidak ditemukan.');
        }

        if (! (bool) ($line['serial_number_required'] ?? false)) {
            throw new DomainException('Produk ini tidak memerlukan nomor seri.');
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

            if (PendingDispatchSerialGuard::isReserved($sn)) {
                throw new DomainException("Serial number $sn sedang dalam proses pengiriman.");
            }

            if (! in_array((int) $record->location_id, $allowedLocationIds, true)) {
                throw new DomainException("Serial number $sn is located in a restricted location.");
            }

            // Check if serial already assigned in another cart line
            if (in_array($sn, $allAssignedSerials, true)) {
                throw new DomainException("Serial number $sn is already assigned in this cart.");
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
        $reservedSerials = PendingDispatchSerialGuard::getReservedSerialsForProduct($productId);

        return \Modules\Product\Entities\ProductSerialNumber::query()
            ->where('product_id', $productId)
            ->whereIn('location_id', $allowedLocationIds)
            ->where('status', 'ACTIVE')
            ->whereNull('dispatch_detail_id')
            ->when($reservedSerials !== [], fn ($q) => $q->whereNotIn('serial_number', $reservedSerials))
            ->when($query !== '', fn ($q) => $q->where('serial_number', 'like', "%$query%"))
            ->limit($limit)
            ->get(['id', 'serial_number'])
            ->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function updateNote(int $settingId, int $sessionId, ?string $note): array
    {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);
        $this->assertActiveTransactionIsMutable($settingId, $cart);

        $cart['note'] = $note;

        $this->cartSessionStore->putCart($settingId, $sessionId, $cart);

        return $this->buildSnapshot($settingId, $sessionId, $cart);
    }

    /**
     * @return array<string, mixed>
     */
    public function clear(int $settingId, int $sessionId, ?string $approvalToken = null, ?User $user = null): array
    {
        if ($user) {
            $this->actionAuthorizationService->authorize(
                $user,
                PosActionApprovalRequest::ACTION_CART_CLEAR,
                $approvalToken
            );
        }

        // Check if we have a loaded transaction that would become empty
        $currentCart = $this->cartSessionStore->getCart($settingId, $sessionId);
        $this->assertActiveTransactionIsMutable($settingId, $currentCart);

        // For loaded transactions, clearing means "unloading" (status reverts to DRAFT).
        // This is now allowed for any user authorized to clear the cart.
        $activeTransactionId = $currentCart['active_transaction_id'] ?? null;
        if ($activeTransactionId) {
            app(\Modules\Pos\Services\PosTransactionService::class)->unload($settingId, (int) $activeTransactionId);
        }

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

        // Fetch pending approval requests for this session to persist UI state on reload
        $pendingRequests = PosActionApprovalRequest::query()
            ->where('pos_session_id', $sessionId)
            ->whereIn('status', [PosActionApprovalRequest::STATUS_PENDING, PosActionApprovalRequest::STATUS_APPROVED])
            ->with('token')
            ->get();

        $cartPendingApprovals = [];
        $linePendingApprovals = [];

        foreach ($pendingRequests as $req) {
            $approvalData = [
                'request_id' => (int) $req->id,
                'action_type' => $req->action_type,
                'status' => $req->status,
            ];

            // Add approval token if approved
            if ($req->status === PosActionApprovalRequest::STATUS_APPROVED && $req->token) {
                $approvalData['approval_token'] = $req->token->token_hash;
                $approvalData['token'] = $req->token->token_hash;
            }

            // Add request-specific data from payload
            if ($req->request_payload) {
                if (isset($req->request_payload['qty'])) {
                    $approvalData['requested_qty'] = (int) $req->request_payload['qty'];
                }
                if (isset($req->request_payload['unit_price'])) {
                    $approvalData['requested_unit_price'] = (float) $req->request_payload['unit_price'];
                }
            }

            // Add decision reason if rejected or cancelled
            if ($req->decision_reason) {
                $approvalData['decision_reason'] = $req->decision_reason;
            }

            if ($req->action_type === PosActionApprovalRequest::ACTION_CART_CLEAR) {
                // Cart-wide pending approvals
                $cartPendingApprovals[] = $approvalData;
            } else {
                // Line-specific pending approvals
                $lineId = (int) $req->target_id;
                if ($lineId > 0) {
                    $linePendingApprovals[$lineId][] = $approvalData;
                }
            }
        }

        // Map line approvals back to calculated lines
        $finalLines = array_map(function ($line) use ($linePendingApprovals) {
            $lineId = (int) ($line['line_id'] ?? 0);
            $line['pending_approvals'] = $linePendingApprovals[$lineId] ?? [];
            return $line;
        }, $calculated['lines']);

        return [
            'setting_id' => $settingId,
            'session_id' => $sessionId,
            'lines' => $finalLines,
            'bill_discount' => [
                'type' => (string) ($cart['bill_discount_type'] ?? 'fixed'),
                'value' => round((float) ($cart['bill_discount_value'] ?? 0), 2),
            ],
            'totals' => $calculated['totals'],
            'customer' => $customerResolution,
            'meta' => [
                'line_count' => count($finalLines),
                'total_qty' => $totalQty,
                'tax_display_mode' => 'ESTIMATED',
                'tax_mode' => $isPkp ? 'INCLUDED' : 'EXCLUDED',
            ],
            'active_transaction_id' => $cart['active_transaction_id'] ?? null,
            'pending_approvals' => $cartPendingApprovals,
            'staged_payment_token' => $cart['staged_payment_token'] ?? null,
            'note' => $cart['note'] ?? null,
        ];
    }

    /**
     * Assert that removing a line would not leave a loaded transaction empty.
     * If $lineIdToRemove is null, check if clearing would violate the constraint.
     *
     * @throws PosCartMutationException('TRANSACTION_EMPTY_BLOCKED')
     */
    private function assertNotLastLineOfLoadedTransaction(array $cart, ?int $lineIdToRemove = null): void
    {
        $activeTransactionId = $cart['active_transaction_id'] ?? null;

        // Only applies if there's an active loaded transaction
        if (! $activeTransactionId) {
            return;
        }

        $lineCount = count($cart['lines'] ?? []);

        if ($lineIdToRemove === null) {
            // We're clearing the entire cart
            if ($lineCount > 0) {
                throw new PosCartMutationException(
                    'TRANSACTION_EMPTY_BLOCKED',
                    'Transaksi yang dimuat tidak dapat dikosongkan.'
                );
            }
        } else {
            // We're removing a specific line
            // Check if it's the only line (removing it would result in 0 lines)
            if ($lineCount === 1 && isset($cart['lines'][$lineIdToRemove])) {
                throw new PosCartMutationException(
                    'TRANSACTION_EMPTY_BLOCKED',
                    'Transaksi yang dimuat tidak dapat dikosongkan.'
                );
            }
        }
    }

    private function assertActiveTransactionIsMutable(int $settingId, array $cart): void
    {
        $activeTransactionId = (int) ($cart['active_transaction_id'] ?? 0);
        if ($activeTransactionId <= 0) {
            return;
        }

        $status = PosTransaction::query()
            ->where('setting_id', $settingId)
            ->whereKey($activeTransactionId)
            ->value('status');

        if ($status === null) {
            throw new PosCartMutationException(
                'TRANSACTION_NOT_FOUND',
                'Transaksi aktif tidak ditemukan.'
            );
        }

        if ($status !== PosTransaction::STATUS_DRAFT && $status !== PosTransaction::STATUS_LOADED) {
            throw new PosCartMutationException(
                'TRANSACTION_FINALIZED',
                'Transaksi yang sudah selesai tidak dapat diubah.'
            );
        }
    }

    private function recordDirectPriceOverrideApproval(
        int $settingId,
        int $sessionId,
        int $requestedBy,
        int $lineId,
        float $fromUnitPrice,
        float $toUnitPrice
    ): void {
        PosSupervisorApproval::query()->create([
            'setting_id' => $settingId,
            'action_type' => PosSupervisorApproval::ACTION_PRICE_OVERRIDE,
            'target_type' => 'pos_session',
            'target_id' => $sessionId,
            'requested_by' => $requestedBy,
            'approved_by' => $requestedBy,
            'approval_result' => PosSupervisorApproval::RESULT_APPROVED,
            'reason' => 'DIRECT_PERMISSION',
            'context_snapshot' => [
                'line_id' => $lineId,
                'from_unit_price' => round($fromUnitPrice, 2),
                'to_unit_price' => round($toUnitPrice, 2),
            ],
            'occurred_at' => now(),
        ]);
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
        $this->assertActiveTransactionIsMutable($settingId, $cart);

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

        if (PendingDispatchSerialGuard::isReserved($serialNumber)) {
            throw new DomainException("Serial number $serialNumber sedang dalam proses pengiriman.");
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

        $assignedSerials = (array) ($line['assigned_serials'] ?? []);
        $qty = (int) ($line['qty'] ?? 0);

        // Guard: prevent appending if serial count already matches qty.
        // If the line is full (assigned count == qty), auto-increment qty first as requested.
        if (count($assignedSerials) >= $qty) {
            $availableQty = (int) ($line['available_qty'] ?? 0);
            if ($availableQty > 0 && $qty >= $availableQty) {
                throw new PosCheckoutValidationException(
                    'SERIAL_EXCEEDS_STOCK',
                    "Gagal menambahkan serial. Kuantitas stok maksimum ({$availableQty}) telah tercapai."
                );
            }
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
        $this->assertActiveTransactionIsMutable($settingId, $cart);

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
            return $this->buildSnapshot($settingId, $sessionId, $cart);
        }

        unset($assignedSerials[$key]);
        $cart['lines'][$lineId]['assigned_serials'] = array_values($assignedSerials);

        $this->cartSessionStore->putCart($settingId, $sessionId, $cart);

        return $this->buildSnapshot($settingId, $sessionId, $cart);
    }

    /**
     * Resolve line price based on customer tier with setting-based product pricing.
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
     * @param  int|null  $bundleId  Optional bundle ID to include in key
     * @return string
     */

    private function buildPricingBasis(int $settingId, int $productId, ?int $selectedCustomerId): ?array
    {
        $priceRow = ProductPrice::query()
            ->forProduct($productId)
            ->forSetting($settingId)
            ->first();

        if (!$priceRow) {
            return null;
        }

        $product = Product::query()->whereKey($productId)->with('unit')->first();
        if (!$product) {
            return null;
        }

        $boxConversion = ProductUnitConversion::query()
            ->where('product_id', $productId)
            ->with(['unit', 'baseUnit'])
            ->first();

        if (!$boxConversion) {
            return null;
        }

        $conversionPrice = ProductUnitConversionPrice::query()
            ->where('product_unit_conversion_id', $boxConversion->id)
            ->where('setting_id', $settingId)
            ->first();

        if (!$conversionPrice) {
            return null;
        }

        $customerTier = null;
        if ($selectedCustomerId !== null && $selectedCustomerId > 0) {
            $customer = Customer::query()
                ->whereKey($selectedCustomerId)
                ->select(['id', 'tier'])
                ->first();
            $customerTier = $customer ? (string) ($customer->tier ?? '') : null;
        }

        $basePrice = (int) round((float) ($priceRow->sale_price ?? 0) * 100);
        $tier1Price = (int) round((float) ($priceRow->tier_1_price ?? 0) * 100);
        $tier2Price = (int) round((float) ($priceRow->tier_2_price ?? 0) * 100);
        $boxPrice = (int) round((float) $conversionPrice->price * 100);

        $saleTaxId = (int) ($priceRow->sale_tax_id ?? 0);
        $tax = $saleTaxId > 0 ? Tax::query()->find($saleTaxId) : null;

        $conversionUnitLabel = $boxConversion->unit ? ($boxConversion->unit->short_name ?: $boxConversion->unit->name) : 'Box';
        $baseUnitLabel = $boxConversion->baseUnit ? ($boxConversion->baseUnit->short_name ?: $boxConversion->baseUnit->name) : ($product->unit ? ($product->unit->short_name ?: $product->unit->name) : 'Unit');

        return [
            'factor' => (int) $boxConversion->conversion_factor,
            'box_price' => $boxPrice,
            'base_price' => $basePrice,
            'tier_1_price' => $tier1Price,
            'tier_2_price' => $tier2Price,
            'tax_id' => $tax ? (int) $tax->id : null,
            'tax_name' => $tax ? (string) $tax->name : null,
            'tax_rate' => $tax ? (float) $tax->value : 0.0,
            'conversion_unit_label' => $conversionUnitLabel,
            'base_unit_label' => $baseUnitLabel,
        ];
    }



    private function buildMergeKey(int $productId, float $unitPrice, ?int $taxId, ?int $conversionId = null, ?int $bundleId = null): string
    {
        return PosMergeKeyGenerator::build($productId, $unitPrice, $taxId, $conversionId, $bundleId);
    }
}
