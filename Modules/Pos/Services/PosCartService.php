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
        private readonly PosCartActionAuthorizationService $actionAuthorizationService,
        private readonly PosCartTotalAllocationService $allocationService,
        private readonly PosCartTotalOverrideRequestService $overrideRequestService,
        private readonly PosApprovalTokenService $approvalTokenService,
        private readonly PosCartMutationLock $cartMutationLock,
        private readonly PosRowOverrideExecutionCoordinator $overrideExecutionCoordinator,
        private readonly PosRowOverrideArithmetic $rowOverrideArithmetic,
        private readonly PosRowOverrideApprovalPayloadBuilder $rowOverridePayloadBuilder,
        private readonly PosCartLineFingerprintService $lineFingerprintService
    ) {
    }

    /**
     * Run a cart mutation while holding this cart's mutation lock.
     *
     * Every operation that persists, clears, replaces, or hydrates the cart
     * must route through here — the guard set is defined by whether the
     * operation writes the cart, not by whether the write affects approval
     * validity. Override compensation restores an exact snapshot, so an
     * unguarded concurrent writer would have its write silently erased.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function withCartLock(int $settingId, int $sessionId, callable $callback): mixed
    {
        return $this->cartMutationLock->withLock($settingId, $sessionId, $callback);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshot(int $settingId, int $sessionId): array
    {
        // Despite the name this is a cart writer: it persists a generated
        // staged_payment_token. It therefore takes the mutation lock like any
        // other writer.
        return $this->withCartLock($settingId, $sessionId, function () use ($settingId, $sessionId): array {
            $cart = $this->cartSessionStore->getCart($settingId, $sessionId);

            // Generate staged_payment_token if it doesn't exist
            if (!isset($cart['staged_payment_token']) || empty($cart['staged_payment_token'])) {
                $cart['staged_payment_token'] = (string) \Illuminate\Support\Str::uuid();
                $this->cartSessionStore->putCart($settingId, $sessionId, $cart);
            }

            return $this->buildSnapshot($settingId, $sessionId, $cart);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function addLine(int $settingId, int $sessionId, int $productId, int $qty = 1, ?int $conversionId = null, ?int $bundleId = null): array
    {
        return $this->withCartLock($settingId, $sessionId, function () use ($settingId, $sessionId, $productId, $qty, $conversionId, $bundleId): array {
            return $this->addLineWithinLock($settingId, $sessionId, $productId, $qty, $conversionId, $bundleId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function addLineWithinLock(int $settingId, int $sessionId, int $productId, int $qty = 1, ?int $conversionId = null, ?int $bundleId = null): array
    {
        if ($qty < 1) {
            throw new DomainException('Kuantitas harus minimal 1.');
        }

        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);
        $this->assertActiveTransactionIsMutable($settingId, $cart);
        $this->invalidateTotalPriceOverride($sessionId);
        $this->clearAppliedTotalOverride($cart);
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

            $evaluator = app(\Modules\Product\Services\BundleLifecycle\ProductBundleLifecycleEvaluator::class);
            $evalResult = $evaluator->evaluateForSelection($bundle, $settingId, $productId);
            if (! $evalResult->isEligible) {
                throw new DomainException($evalResult->primaryMessage() ?? 'Paket tidak memenuhi syarat operasional.');
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

            if ($availableQty !== null && $newQty > $availableQty) {
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

            if ($availableQty !== null && $qty > $availableQty) {
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
                    'bundle_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->product_name ?? "Produk #{$item->product_id}",
                    'quantity_per_bundle' => (float) $item->quantity,
                    'quantity' => (float) $item->quantity,
                    'stock_managed' => (bool) ($item->product?->stock_managed ?? true),
                    'serial_number_required' => (bool) ($item->product?->serial_number_required ?? false),
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
        return $this->withCartLock($settingId, $sessionId, function () use ($settingId, $sessionId, $lineId, $payload, $approvalToken, $user): array {
            return $this->updateLineWithinLock($settingId, $sessionId, $lineId, $payload, $approvalToken, $user);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function updateLineWithinLock(int $settingId, int $sessionId, int $lineId, array $payload, ?string $approvalToken = null, ?User $user = null): array
    {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);
        $this->assertActiveTransactionIsMutable($settingId, $cart);
        $this->invalidateTotalPriceOverride($sessionId);
        $this->invalidateLineTotalOverride($sessionId, $lineId);
        $this->clearAppliedTotalOverride($cart);

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

        $availableQty = $line['available_qty'] ?? null;
        if ($availableQty !== null && $qty > $availableQty) {
            throw new DomainException('Requested quantity exceeds available stock for configured sales locations.');
        }

        $discountType = $payload['line_discount_type'] ?? $line['line_discount_type'] ?? 'fixed';
        $discountValue = (float) ($payload['line_discount_value'] ?? $line['line_discount_value'] ?? 0);

        $priceSource = $line['price_source'] ?? 'BASE';
        $unitPrice = (float) ($line['unit_price'] ?? 0);

        // Preserve assigned serials across qty changes. Serial/qty mismatch is validated at checkout time.
        $updatedLine = array_merge($line, [
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'price_source' => $priceSource,
            'assigned_serials' => $assignedSerials,
            'line_discount_type' => $this->normalizeDiscountType((string) $discountType),
            'line_discount_value' => round(max(0.0, $discountValue), 2),
        ]);

        if ($priceSource !== 'PACKED') {
            // Drop every canonical field, not just line_total: the calculator
            // trusts them over recalculation, so a surviving set computed for
            // the previous quantity or discount would report a stale total.
            $updatedLine = $this->clearCanonicalOverrideMetadata($updatedLine);
        }

        // Quantity, discount, or tax context just changed. A unit-price
        // override keeps its authoritative price and gets fresh metadata; a
        // row-total override reverts to resolved standard pricing.
        $updatedLine = $this->refreshOrInvalidateRowOverride($settingId, $updatedLine, $cart);
        $priceSource = (string) ($updatedLine['price_source'] ?? $priceSource);

        // For packed lines, re-pack the total quantity from cached pricing_basis
        // Skip if total-override is active (frozen pricing)
        if (($priceSource === 'PACKED') && isset($line['pricing_basis'])) {
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
        return $this->withCartLock($settingId, $sessionId, function () use ($settingId, $sessionId, $lineId, $approvalToken, $user): array {
            return $this->removeLineWithinLock($settingId, $sessionId, $lineId, $approvalToken, $user);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function removeLineWithinLock(int $settingId, int $sessionId, int $lineId, ?string $approvalToken = null, ?User $user = null): array
    {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);
        $this->assertActiveTransactionIsMutable($settingId, $cart);
        $this->invalidateTotalPriceOverride($sessionId);
        $this->invalidateLineTotalOverride($sessionId, $lineId);
        $this->clearAppliedTotalOverride($cart);

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
    ): array
    {
        return $this->withCartLock($settingId, $sessionId, function () use ($settingId, $sessionId, $billDiscountType, $billDiscountValue): array {
            return $this->updateBillDiscountWithinLock($settingId, $sessionId, $billDiscountType, $billDiscountValue);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function updateBillDiscountWithinLock(
        int $settingId,
        int $sessionId,
        string $billDiscountType,
        float $billDiscountValue
    ): array {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);
        $this->assertActiveTransactionIsMutable($settingId, $cart);
        $this->invalidateTotalPriceOverride($sessionId);
        $this->clearAppliedTotalOverride($cart);

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
        return $this->withCartLock($settingId, $sessionId, function () use ($settingId, $sessionId, $customerId): array {
            return $this->updateCustomerSelectionWithinLock($settingId, $sessionId, $customerId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function updateCustomerSelectionWithinLock(int $settingId, int $sessionId, ?int $customerId): array
    {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);
        $this->assertActiveTransactionIsMutable($settingId, $cart);
        $this->invalidateTotalPriceOverride($sessionId);
        $this->invalidateAllLineTotalOverrides($sessionId);
        $this->clearAppliedTotalOverride($cart);

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

            // Skip OVERRIDE, TOTAL_OVERRIDE, LINE_UNIT_PRICE_OVERRIDE, LINE_TOTAL_OVERRIDE, or BUNDLE lines - keep existing price.
            // Bundled row prices and explicit overrides are authoritative and bypass customer tier repricing.
            if ($priceSource === 'OVERRIDE' || $priceSource === 'BUNDLE' || $priceSource === 'TOTAL_OVERRIDE' || $priceSource === 'LINE_UNIT_PRICE_OVERRIDE' || $priceSource === 'LINE_TOTAL_OVERRIDE') {
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

            // Repricing replaces an applied override with a standard source, so
            // every canonical override field must go with it. Leaving them
            // behind would let the calculator keep reporting the overridden
            // total under a TIER price.
            $line = $this->clearCanonicalOverrideMetadata($line);

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

                // The merged quantity invalidates any canonical metadata the
                // target carried, which was computed for its pre-merge qty.
                $targetLine = $this->clearCanonicalOverrideMetadata($targetLine);
                $targetLine = $this->refreshOrInvalidateRowOverride($settingId, $targetLine, $cart);

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
     * Apply or request a unit-price override for one cart row.
     *
     * @return array<string, mixed>
     */
    public function overrideLineUnitPrice(
        int $settingId,
        int $sessionId,
        int $requestedBy,
        int $lineId,
        float|int|string $unitPrice,
        ?string $reason = null,
        ?string $approvalToken = null,
        ?User $user = null
    ): array {
        return $this->executeRowOverride(
            PosActionApprovalRequest::ACTION_LINE_UNIT_PRICE_OVERRIDE,
            $settingId,
            $sessionId,
            $requestedBy,
            $lineId,
            $unitPrice,
            $reason,
            $approvalToken,
            $user
        );
    }

    /**
     * Apply or request a row-total override for one cart row.
     *
     * @return array<string, mixed>
     */
    public function overrideLineTotal(
        int $settingId,
        int $sessionId,
        int $requestedBy,
        int $lineId,
        float|int|string $lineTotal,
        ?string $reason = null,
        ?string $approvalToken = null,
        ?User $user = null
    ): array {
        return $this->executeRowOverride(
            PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE,
            $settingId,
            $sessionId,
            $requestedBy,
            $lineId,
            $lineTotal,
            $reason,
            $approvalToken,
            $user
        );
    }

    /**
     * Shared execution for both active row overrides.
     *
     * The two actions differ only in which value is authoritative; everything
     * else — authorization, locking, calculation, persistence ordering,
     * consumption, audit, and compensation — is identical and lives here so the
     * paths cannot drift apart.
     *
     * @return array<string, mixed>
     */
    private function executeRowOverride(
        string $actionType,
        int $settingId,
        int $sessionId,
        int $requestedBy,
        int $lineId,
        float|int|string $requestedValue,
        ?string $reason,
        ?string $approvalToken,
        ?User $user
    ): array {
        if (! $user) {
            throw new DomainException('Otentikasi diperlukan.');
        }

        if (! is_numeric($requestedValue) || (float) $requestedValue < 0) {
            throw new DomainException($actionType === PosActionApprovalRequest::ACTION_LINE_UNIT_PRICE_OVERRIDE
                ? 'Harga satuan tidak boleh negatif.'
                : 'Total baris tidak boleh negatif.');
        }

        $requestedValueMinor = (int) round(((float) $requestedValue) * 100);

        $authorization = $this->actionAuthorizationService->authorizeWithoutConsuming(
            $user,
            $actionType,
            $approvalToken,
            [
                'pos_session_id' => $sessionId,
                'target_type' => 'pos_cart_line',
                'target_id' => $lineId,
                'requested_by' => $requestedBy,
            ]
        );

        $approvedRequest = $authorization['request'];

        if ($approvedRequest instanceof PosActionApprovalRequest) {
            return $this->executeApprovedRowOverride(
                $actionType,
                $settingId,
                $sessionId,
                $requestedBy,
                $lineId,
                $requestedValueMinor,
                $reason,
                (string) $approvalToken,
                $user
            );
        }

        return $this->executeDirectRowOverride(
            $actionType,
            $settingId,
            $sessionId,
            $requestedBy,
            $lineId,
            $requestedValueMinor,
            $reason,
            $user
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function executeDirectRowOverride(
        string $actionType,
        int $settingId,
        int $sessionId,
        int $requestedBy,
        int $lineId,
        int $requestedValueMinor,
        ?string $reason,
        User $user
    ): array {
        $auditPayload = null;

        $this->overrideExecutionCoordinator->executeDirect(
            $settingId,
            $sessionId,
            function (array $cart) use (
                $actionType,
                $settingId,
                $sessionId,
                $lineId,
                $requestedValueMinor,
                $requestedBy,
                $reason,
                &$auditPayload
            ): array {
                $this->assertActiveTransactionIsMutable($settingId, $cart);

                $line = $this->requireLine($cart, $lineId);
                $context = $this->lineFingerprintService->buildContext($settingId, $cart);

                // Built before mutation so the audit records the pre-change
                // source values and the fingerprint of what was actually edited.
                $auditPayload = $this->rowOverridePayloadBuilder->build(
                    $actionType,
                    $settingId,
                    $sessionId,
                    $lineId,
                    $cart,
                    $line,
                    $requestedValueMinor,
                    $requestedBy,
                    $reason
                );

                return $this->applyRowOverrideToCart(
                    $cart,
                    $lineId,
                    $actionType,
                    $requestedValueMinor,
                    $context
                );
            },
            function () use ($actionType, $settingId, $sessionId, $requestedBy, &$auditPayload): void {
                $this->recordRowOverrideAudit(
                    $actionType,
                    $settingId,
                    $sessionId,
                    $requestedBy,
                    $requestedBy,
                    $auditPayload
                );
            }
        );

        return $this->getSnapshot($settingId, $sessionId);
    }

    /**
     * @return array<string, mixed>
     */
    private function executeApprovedRowOverride(
        string $actionType,
        int $settingId,
        int $sessionId,
        int $requestedBy,
        int $lineId,
        int $requestedValueMinor,
        ?string $reason,
        string $approvalToken,
        User $user
    ): array {
        $this->overrideExecutionCoordinator->executeApproved(
            $settingId,
            $sessionId,
            $approvalToken,
            (int) $user->id,
            [
                'action_type' => $actionType,
                'target_id' => $lineId,
                'pos_session_id' => $sessionId,
            ],
            function (PosActionApprovalRequest $request, array $cart) use (
                $actionType,
                $settingId,
                $sessionId,
                $lineId,
                $requestedBy,
                $requestedValueMinor
            ): void {
                $this->assertActiveTransactionIsMutable($settingId, $cart);
                $this->assertApprovalMatchesExecution(
                    $request,
                    $actionType,
                    $settingId,
                    $sessionId,
                    $lineId,
                    $requestedBy,
                    $requestedValueMinor,
                    $cart
                );
            },
            function (PosActionApprovalRequest $request, array $cart) use (
                $actionType,
                $settingId,
                $lineId,
                $requestedValueMinor
            ): array {
                return $this->applyRowOverrideToCart(
                    $cart,
                    $lineId,
                    $actionType,
                    $requestedValueMinor,
                    $this->lineFingerprintService->buildContext($settingId, $cart)
                );
            },
            function (PosActionApprovalRequest $request) use (
                $actionType,
                $settingId,
                $sessionId,
                $requestedBy
            ): void {
                $this->recordRowOverrideAudit(
                    $actionType,
                    $settingId,
                    $sessionId,
                    $requestedBy,
                    (int) ($request->decided_by ?? $requestedBy),
                    $request->request_payload ?? []
                );
            }
        );

        return $this->getSnapshot($settingId, $sessionId);
    }

    /**
     * Validate an approved execution against its approval.
     *
     * Every dimension is checked explicitly, and the submitted value must equal
     * the approved value exactly — never silently substituted.
     *
     * @param  array<string, mixed>  $cart
     */
    private function assertApprovalMatchesExecution(
        PosActionApprovalRequest $request,
        string $actionType,
        int $settingId,
        int $sessionId,
        int $lineId,
        int $requestedBy,
        int $submittedValueMinor,
        array $cart
    ): void {
        if (strcasecmp((string) $request->action_type, $actionType) !== 0) {
            throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
        }

        if (strcasecmp((string) $request->target_type, 'pos_cart_line') !== 0) {
            throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
        }

        if ((int) $request->pos_session_id !== $sessionId) {
            throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
        }

        // Exact line only: no product-ID fallback, which could resolve to an
        // unintended row when the same product appears more than once.
        if ((int) $request->target_id !== $lineId) {
            throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
        }

        if ((int) $request->requested_by !== $requestedBy) {
            throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
        }

        $payload = $request->request_payload ?? [];

        $this->rowOverridePayloadBuilder->assertSubmittedValueMatchesApproved(
            $payload,
            $actionType,
            $submittedValueMinor
        );

        $fingerprint = (string) ($payload['fingerprint'] ?? '');

        if ($fingerprint === '') {
            throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
        }

        $line = $this->requireLine($cart, $lineId);
        $context = $this->lineFingerprintService->buildContext($settingId, $cart);

        $matches = $this->lineFingerprintService->approvalFingerprintMatches(
            $line,
            $context,
            $actionType,
            $submittedValueMinor,
            $sessionId,
            $lineId,
            $requestedBy,
            $fingerprint
        );

        if (! $matches) {
            throw new DomainException('Baris keranjang telah berubah. Permintaan dibatalkan.');
        }
    }

    /**
     * Apply canonical override arithmetic to one row.
     *
     * @param  array<string, mixed>  $cart
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function applyRowOverrideToCart(
        array $cart,
        int $lineId,
        string $actionType,
        int $requestedValueMinor,
        array $context
    ): array {
        $line = $this->requireLine($cart, $lineId);

        $qty = max(0, (int) ($line['qty'] ?? 0));
        $discountType = (string) ($line['line_discount_type'] ?? 'fixed');
        $discountValue = (float) ($line['line_discount_value'] ?? 0.0);
        $taxRate = (float) ($line['tax_rate'] ?? 0.0);
        $isPkp = ! empty($context['is_pkp']) && (int) ($line['tax_id'] ?? 0) > 0;

        $result = $actionType === PosActionApprovalRequest::ACTION_LINE_UNIT_PRICE_OVERRIDE
            ? $this->rowOverrideArithmetic->applyUnitPrice(
                $requestedValueMinor,
                $qty,
                $discountType,
                $discountValue,
                $taxRate,
                $isPkp
            )
            : $this->rowOverrideArithmetic->applyRowTotal(
                $requestedValueMinor,
                $qty,
                $discountType,
                $discountValue,
                $taxRate,
                $isPkp
            );

        $cart['lines'][$lineId] = array_merge($line, [
            'unit_price' => round(((int) $result['unit_price_minor']) / 100, 2),
            'price_source' => (string) $result['price_source'],
            // Canonical derived metadata, persisted so display, approval,
            // draft, checkout, posting, receipt, and audit all agree rather
            // than reconstructing contradictory values later.
            // Explicit unit contract. Raw cart lines historically carried
            // `line_total` in minor units while calculated snapshots expose it
            // in major units, which is easy for a new consumer to misread.
            // `line_total_minor` is unambiguous; `line_total` is kept in step
            // for existing readers.
            'line_total_minor' => (int) $result['line_net_minor'],
            'line_total' => (int) $result['line_net_minor'],
            'line_gross_minor' => (int) $result['line_gross_minor'],
            'line_discount_minor' => (int) $result['line_discount_minor'],
            'line_net_minor' => (int) $result['line_net_minor'],
            'line_tax_minor' => (int) $result['line_tax_minor'],
            'line_taxable_base_minor' => (int) $result['line_taxable_base_minor'],
        ]);

        // Any row mutation invalidates pending approvals for both active
        // override actions on this line.
        $this->invalidateRowOverrideApprovals($cart['session_id'] ?? 0, $lineId);

        return $cart;
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

        // Enrich lines with current parent operational classifications while retaining captured flags
        $parentProductIds = array_filter(array_map(
            fn (array $line): ?int => ! empty($line['product_id']) ? (int) $line['product_id'] : null,
            $calculated['lines']
        ));
        $liveParentProducts = ! empty($parentProductIds)
            ? \Modules\Product\Entities\Product::whereIn('id', array_unique($parentProductIds))->get(['id', 'stock_managed', 'serial_number_required'])->keyBy('id')
            : collect();

        $calculated['lines'] = array_map(function (array $line) use ($liveParentProducts): array {
            $pid = (int) ($line['product_id'] ?? 0);
            $liveProduct = $pid > 0 ? $liveParentProducts->get($pid) : null;
            if ($liveProduct) {
                $line['captured_stock_managed'] = $line['captured_stock_managed'] ?? ($line['stock_managed'] ?? true);
                $line['captured_serial_number_required'] = $line['captured_serial_number_required'] ?? ($line['serial_number_required'] ?? false);
                $line['stock_managed'] = (bool) $liveProduct->stock_managed;
                $line['serial_number_required'] = (bool) $liveProduct->serial_number_required;

                // Re-evaluate serial_status based on current operational requirement
                $assignedCount = count((array) ($line['assigned_serials'] ?? []));
                $qty = (int) ($line['qty'] ?? 0);
                $parentSerialOk = ! $line['serial_number_required'] || ($assignedCount === $qty);

                $componentsSerialOk = true;
                if (! empty($line['bundle_items']) && is_array($line['bundle_items'])) {
                    foreach ($line['bundle_items'] as $bItem) {
                        $cSerialRequired = (bool) ($bItem['serial_number_required'] ?? false);
                        if ($cSerialRequired) {
                            $bItemId = (int) ($bItem['bundle_item_id'] ?? 0);
                            try {
                                $cRequiredQty = $this->resolveRequiredComponentSerialQty($qty, $bItem);
                            } catch (DomainException) {
                                $componentsSerialOk = false;
                                break;
                            }
                            $cAssignedCount = count((array) ($line['bundle_item_serials'][$bItemId] ?? ($bItem['assigned_serials'] ?? [])));
                            if ($cAssignedCount < $cRequiredQty) {
                                $componentsSerialOk = false;
                                break;
                            }
                        }
                    }
                }

                $line['serial_status'] = ($parentSerialOk && $componentsSerialOk) ? 'ok' : 'incomplete';
            }
            return $line;
        }, $calculated['lines']);

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
        $totalPriceOverrideApproval = null;

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
                // Canonical minor-unit payload written by the shared builder.
                if (isset($req->request_payload['requested_value_minor'])) {
                    $requestedMinor = (int) $req->request_payload['requested_value_minor'];
                    $sourceMinor = (int) ($req->request_payload['source_value_minor'] ?? 0);

                    $approvalData['value_kind'] = $req->request_payload['value_kind'] ?? null;
                    $approvalData['source_value_minor'] = $sourceMinor;
                    $approvalData['requested_value_minor'] = $requestedMinor;
                    $approvalData['delta_minor'] = $requestedMinor - $sourceMinor;

                    // Surface each action's value under its own key so a control
                    // can never read the other action's amount.
                    if ($req->action_type === PosActionApprovalRequest::ACTION_LINE_UNIT_PRICE_OVERRIDE) {
                        $approvalData['requested_unit_price'] = round($requestedMinor / 100, 2);
                    } elseif ($req->action_type === PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE) {
                        $approvalData['requested_line_total'] = round($requestedMinor / 100, 2);
                    }
                }

                // Legacy payload shapes, retained so historical records render.
                if (isset($req->request_payload['unit_price'])) {
                    $approvalData['requested_unit_price'] = (float) $req->request_payload['unit_price'];
                }
                if (isset($req->request_payload['line_total'])) {
                    $approvalData['requested_line_total'] = (float) $req->request_payload['line_total'];
                    $approvalData['line_total'] = (float) $req->request_payload['line_total'];
                }
                if (isset($req->request_payload['source_total'])) {
                    $approvalData['source_total'] = (int) $req->request_payload['source_total'];
                }
                if (isset($req->request_payload['target_total'])) {
                    $approvalData['target_total'] = (int) $req->request_payload['target_total'];
                    $approvalData['delta'] = (int) $req->request_payload['target_total'] - ((int) $req->request_payload['source_total'] ?? 0);
                    if (! isset($approvalData['requested_line_total'])) {
                        $approvalData['requested_line_total'] = round((int) $req->request_payload['target_total'] / 100, 2);
                    }
                }
                if (isset($req->request_payload['reason'])) {
                    $approvalData['reason'] = $req->request_payload['reason'];
                }
            }

            // Add decision reason if rejected or cancelled
            if ($req->decision_reason) {
                $approvalData['decision_reason'] = $req->decision_reason;
            }

            if ($req->action_type === PosActionApprovalRequest::ACTION_CART_CLEAR) {
                // Cart-wide pending approvals
                $cartPendingApprovals[] = $approvalData;
            } elseif (
                PosActionApprovalRequest::isRowOverrideAction((string) $req->action_type)
                || $req->action_type === PosActionApprovalRequest::ACTION_QTY_REDUCE
                || $req->action_type === PosActionApprovalRequest::ACTION_LINE_REMOVE
            ) {
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
            // Revision of the cart this snapshot describes. Read from the store
            // rather than the in-memory array: callers build snapshots after
            // persisting, so the in-memory copy predates the write that
            // advanced the revision. A compare-and-set consumer (checkout)
            // carries this value, so a stale one would never match.
            'cart_revision' => $this->cartSessionStore->currentRevision($settingId, $sessionId),
            'note' => $cart['note'] ?? null,
        ];
    }

    /**
     * Every canonical field written by PosRowOverrideArithmetic.
     *
     * These are persisted so display, approval, draft, checkout, posting,
     * receipt, and audit agree without re-deriving. That makes them dangerous
     * to leave behind: PosCartTotalsCalculator trusts them over recalculation,
     * so a surviving set computed for an older quantity or discount would
     * silently report a stale row total.
     *
     * @var array<int, string>
     */
    private const CANONICAL_OVERRIDE_FIELDS = [
        'line_total',
        'line_total_minor',
        'line_gross_minor',
        'line_discount_minor',
        'line_net_minor',
        'line_tax_minor',
        'line_taxable_base_minor',
    ];

    /**
     * Strip all canonical override metadata from a line.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function clearCanonicalOverrideMetadata(array $line): array
    {
        foreach (self::CANONICAL_OVERRIDE_FIELDS as $field) {
            unset($line[$field]);
        }

        return $line;
    }

    /**
     * Keep an applied row override consistent after the row changed underneath it.
     *
     * A unit-price override stays authoritative — the cashier set a price, not a
     * total — so its metadata is recomputed against the new quantity, discount,
     * and tax context. A row-total override cannot survive: the total the
     * cashier approved was for the old row, so the line reverts to resolved
     * standard pricing and every canonical field is removed.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $cart
     * @return array<string, mixed>
     */
    private function refreshOrInvalidateRowOverride(int $settingId, array $line, array $cart): array
    {
        $priceSource = (string) ($line['price_source'] ?? 'BASE');

        if ($priceSource === 'LINE_UNIT_PRICE_OVERRIDE') {
            $context = $this->lineFingerprintService->buildContext($settingId, $cart);

            $recalculated = $this->rowOverrideArithmetic->applyUnitPrice(
                (int) round(((float) ($line['unit_price'] ?? 0)) * 100),
                max(0, (int) ($line['qty'] ?? 0)),
                (string) ($line['line_discount_type'] ?? 'fixed'),
                (float) ($line['line_discount_value'] ?? 0.0),
                (float) ($line['tax_rate'] ?? 0.0),
                ! empty($context['is_pkp']) && (int) ($line['tax_id'] ?? 0) > 0
            );

            return array_merge($line, [
                'price_source' => 'LINE_UNIT_PRICE_OVERRIDE',
                'line_total_minor' => (int) $recalculated['line_net_minor'],
                'line_total' => (int) $recalculated['line_net_minor'],
                'line_gross_minor' => (int) $recalculated['line_gross_minor'],
                'line_discount_minor' => (int) $recalculated['line_discount_minor'],
                'line_net_minor' => (int) $recalculated['line_net_minor'],
                'line_tax_minor' => (int) $recalculated['line_tax_minor'],
                'line_taxable_base_minor' => (int) $recalculated['line_taxable_base_minor'],
            ]);
        }

        if ($priceSource === 'LINE_TOTAL_OVERRIDE') {
            return $this->restoreStandardPricing($settingId, $line, $cart);
        }

        return $line;
    }

    /**
     * Restore a line to its authoritative standard pricing, preserving line kind.
     *
     * A blanket fall back to the parent product's ordinary price would be wrong
     * for anything other than an ordinary row: a bundle parent's standard price
     * is the selected bundle's sale price, and a packed row's is its packed
     * calculation. Restoring those to the standalone product price would
     * silently reprice the row.
     *
     * Bundle identity, component snapshots, and informational allocations are
     * left untouched throughout.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $cart
     * @return array<string, mixed>
     */
    private function restoreStandardPricing(int $settingId, array $line, array $cart): array
    {
        $line = $this->clearCanonicalOverrideMetadata($line);

        $selectedCustomerId = isset($cart['selected_customer_id']) ? (int) $cart['selected_customer_id'] : null;
        $bundleId = (int) ($line['bundle_id'] ?? 0);

        // Bundle parent: authoritative price is the bundle's sale price.
        if ($bundleId > 0) {
            $bundle = ProductBundle::query()->whereKey($bundleId)->first();

            if ($bundle) {
                $line['unit_price'] = round((float) ($bundle->bundle_sale_price ?? 0), 2);
                $line['bundle_price'] = round((float) ($bundle->price ?? 0), 2);
            }

            $line['price_source'] = 'BUNDLE';

            return $line;
        }

        // Packed row: re-run packed pricing for the current quantity and tier.
        $pricingBasis = $line['pricing_basis']
            ?? $this->buildPricingBasis($settingId, (int) ($line['product_id'] ?? 0), $selectedCustomerId);

        if ($pricingBasis !== null) {
            $priceResult = (new PackedLinePricingService())->price(
                max(0, (int) ($line['qty'] ?? 0)),
                $cart['selected_customer_tier'] ?? null,
                $pricingBasis
            );

            $line['pricing_basis'] = $pricingBasis;
            $line['unit_price'] = round($priceResult['blended_unit_price'] / 100.0, 2);
            $line['line_total'] = $priceResult['line_total_minor'];
            $line['breakdown'] = $priceResult['breakdown'];
            $line['price_source'] = 'PACKED';

            return $line;
        }

        // Ordinary row: resolved price, tagged TIER when a customer tier drove it.
        $resolved = $this->resolveLinePrice(
            $settingId,
            (int) ($line['product_id'] ?? 0),
            $selectedCustomerId,
            $line['tax_id'] ?? null
        );

        $line['unit_price'] = round((float) ($resolved['unit_price'] ?? 0), 2);
        $line['price_source'] = $selectedCustomerId !== null && ! empty($cart['selected_customer_tier'])
            ? 'TIER'
            : 'BASE';

        return $line;
    }

    /**
     * @param  array<string, mixed>  $cart
     * @return array<string, mixed>
     */
    private function requireLine(array $cart, int $lineId): array
    {
        $line = $cart['lines'][$lineId] ?? null;

        if (! is_array($line)) {
            throw new DomainException('Baris keranjang tidak ditemukan.');
        }

        return $line;
    }

    /**
     * Record a successful row-override execution.
     *
     * Only ever called after the cart mutation (and, when supervised, token
     * consumption) has succeeded.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function recordRowOverrideAudit(
        string $actionType,
        int $settingId,
        int $sessionId,
        int $requestedBy,
        int $authorizedBy,
        ?array $payload
    ): void {
        $payload ??= [];

        PosSupervisorApproval::create([
            'setting_id' => $settingId,
            'action_type' => $actionType === PosActionApprovalRequest::ACTION_LINE_UNIT_PRICE_OVERRIDE
                ? PosSupervisorApproval::ACTION_LINE_UNIT_PRICE_OVERRIDE
                : PosSupervisorApproval::ACTION_LINE_TOTAL_OVERRIDE,
            'target_type' => 'pos_cart_line',
            'target_id' => (int) ($payload['line_id'] ?? 0),
            'requested_by' => $requestedBy,
            'approved_by' => $authorizedBy,
            'approval_result' => PosSupervisorApproval::RESULT_APPROVED,
            'reason' => $payload['reason'] ?? null,
            'context_snapshot' => [
                'action_type' => $actionType,
                'value_kind' => $payload['value_kind'] ?? null,
                'pos_session_id' => $sessionId,
                'line_id' => $payload['line_id'] ?? null,
                'product_id' => $payload['product_id'] ?? null,
                'source_value_minor' => $payload['source_value_minor'] ?? null,
                'requested_value_minor' => $payload['requested_value_minor'] ?? null,
                'source_unit_price_minor' => $payload['source_unit_price_minor'] ?? null,
                'source_total_minor' => $payload['source_total_minor'] ?? null,
                'fingerprint' => $payload['fingerprint'] ?? null,
                'requester_id' => $requestedBy,
                'authorizer_id' => $authorizedBy,
                'executed_at' => now()->toIso8601String(),
                'result' => 'SUCCESS',
            ],
            'occurred_at' => now(),
        ]);
    }

    /**
     * Invalidate unconsumed approvals for BOTH active override actions on a row.
     */
    private function invalidateRowOverrideApprovals(int $sessionId, int $lineId): void
    {
        if ($sessionId <= 0) {
            return;
        }

        PosActionApprovalRequest::query()
            ->where('pos_session_id', $sessionId)
            ->whereIn('action_type', PosActionApprovalRequest::ROW_OVERRIDE_ACTIONS)
            ->where('target_id', $lineId)
            ->whereIn('status', [
                PosActionApprovalRequest::STATUS_PENDING,
                PosActionApprovalRequest::STATUS_APPROVED,
            ])
            ->update(['status' => PosActionApprovalRequest::STATUS_CANCELLED]);
    }

    /**
     * Retired: cart-wide total override is no longer supported.
     */
    public function overrideTotalPrice(
        int $settingId,
        int $sessionId,
        int $requestedBy,
        int $targetTotalMinorUnits,
        ?string $reason = null,
        ?string $approvalToken = null,
        ?User $user = null
    ): array {
        throw new DomainException('Fitur penyesuaian total keranjang telah digantikan oleh penyesuaian total per baris.');
    }

    /**
     * @param  array<int, string>  $serialNumbers
     * @return array<string, mixed>
     */
    public function assignSerials(int $settingId, int $sessionId, int $lineId, array $serialNumbers, ?int $bundleItemId = null): array
    {
        return $this->withCartLock($settingId, $sessionId, function () use ($settingId, $sessionId, $lineId, $serialNumbers, $bundleItemId): array {
            return $this->assignSerialsWithinLock($settingId, $sessionId, $lineId, $serialNumbers, $bundleItemId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function assignSerialsWithinLock(int $settingId, int $sessionId, int $lineId, array $serialNumbers, ?int $bundleItemId = null): array
    {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);
        $this->assertActiveTransactionIsMutable($settingId, $cart);
        $this->invalidateTotalPriceOverride($sessionId);
        $this->invalidateLineTotalOverride($sessionId, $lineId);
        $this->clearAppliedTotalOverride($cart);

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

        if ($bundleItemId !== null) {
            $bundleItems = (array) ($line['bundle_items'] ?? []);
            $foundItemIndex = null;
            foreach ($bundleItems as $idx => $bItem) {
                if ((int) ($bItem['bundle_item_id'] ?? 0) === $bundleItemId) {
                    $foundItemIndex = $idx;
                    break;
                }
            }

            if ($foundItemIndex === null) {
                throw new DomainException('Komponen paket tidak ditemukan pada baris keranjang ini.');
            }

            $targetItem = $bundleItems[$foundItemIndex];
            $componentProductId = (int) ($targetItem['product_id'] ?? 0);
            $liveComponentProduct = $componentProductId > 0 ? \Modules\Product\Entities\Product::find($componentProductId) : null;
            $isSerialRequired = $liveComponentProduct ? (bool) $liveComponentProduct->serial_number_required : (bool) ($targetItem['serial_number_required'] ?? false);

            if (! $isSerialRequired) {
                throw new DomainException('Komponen ini tidak memerlukan nomor seri.');
            }

            $parentQty = (int) ($line['qty'] ?? 0);
            $requiredComponentQty = $this->resolveRequiredComponentSerialQty($parentQty, $targetItem);

            if (count($serialNumbers) > $requiredComponentQty) {
                throw new DomainException('Serial count (' . count($serialNumbers) . ") exceeds component quantity ($requiredComponentQty).");
            }

            if (count($serialNumbers) !== count(array_unique($serialNumbers))) {
                throw new DomainException('Duplicate serial numbers provided.');
            }

            $allowedLocationIds = SalesLocationResolver::resolveLocationIds($settingId)->all();

            // Check for duplicates across all cart lines and components (excluding this line's own component)
            $allAssignedSerials = $this->collectCartWideAssignedSerials($cart, $lineId, $bundleItemId);

            foreach ($serialNumbers as $sn) {
                $record = \Modules\Product\Entities\ProductSerialNumber::query()
                    ->where('product_id', $componentProductId)
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

                if (in_array($sn, $allAssignedSerials, true)) {
                    throw new DomainException("Serial number $sn is already assigned in this cart.");
                }
            }

            $cart['lines'][$lineId]['bundle_item_serials'][$bundleItemId] = $serialNumbers;
            $cart['lines'][$lineId]['bundle_items'][$foundItemIndex]['assigned_serials'] = $serialNumbers;
            $this->cartSessionStore->putCart($settingId, $sessionId, $cart);

            return $this->buildSnapshot($settingId, $sessionId, $cart);
        }

        $productId = (int) ($line['product_id'] ?? 0);
        $liveProduct = $productId > 0 ? \Modules\Product\Entities\Product::find($productId) : null;
        $isSerialRequired = $liveProduct ? (bool) $liveProduct->serial_number_required : (bool) ($line['serial_number_required'] ?? false);

        if (! $isSerialRequired) {
            throw new DomainException('Produk ini tidak memerlukan nomor seri.');
        }

        $qty = (int) ($line['qty'] ?? 0);
        // Allow incremental serial assignment: count($serialNumbers) must be <= qty
        if (count($serialNumbers) > $qty) {
            throw new DomainException("Serial count (" . count($serialNumbers) . ") exceeds line quantity ($qty).");
        }

        if (count($serialNumbers) !== count(array_unique($serialNumbers))) {
            throw new DomainException('Duplicate serial numbers provided.');
        }

        $taxId = $line['tax_id'] ?? null;
        $allowedLocationIds = SalesLocationResolver::resolveLocationIds($settingId)->all();

        // Check for duplicates across all cart lines and components (excluding this line's own parent assignment)
        $allAssignedSerials = $this->collectCartWideAssignedSerials($cart, $lineId, null);

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
     * Compute the required serial count for a bundle component, rejecting
     * fractional physical demand instead of rounding it into an authorized
     * serial count that would not equal actual component demand.
     *
     * @param  array<string, mixed>  $targetItem
     */
    private function resolveRequiredComponentSerialQty(int $parentQty, array $targetItem): int
    {
        $qtyPerBundle = (float) ($targetItem['quantity_per_bundle'] ?? ($targetItem['quantity'] ?? 1));
        $rawRequiredQty = $parentQty * $qtyPerBundle;

        if (abs($rawRequiredQty - round($rawRequiredQty)) > 1e-9 || $rawRequiredQty < 0) {
            $componentLabel = (string) ($targetItem['product_name'] ?? $targetItem['name'] ?? 'komponen');
            throw new DomainException(
                "Kuantitas nomor seri untuk komponen \"$componentLabel\" tidak bulat ($rawRequiredQty). Sesuaikan kuantitas paket atau komponen."
            );
        }

        return (int) round($rawRequiredQty);
    }

    /**
     * Flatten every parent (`assigned_serials`) and bundle-component
     * (`bundle_item_serials`) serial assignment across the whole cart into
     * one normalized set, optionally excluding one line/component position
     * so that position's own current assignment does not collide with itself.
     *
     * @param  array<string, mixed>  $cart
     * @return array<int, string>
     */
    private function collectCartWideAssignedSerials(array $cart, ?int $excludeLineId = null, ?int $excludeBundleItemId = null): array
    {
        $allAssignedSerials = [];

        foreach ($cart['lines'] as $cLineId => $cartLine) {
            $isExcludedLine = $excludeLineId !== null && (int) $cLineId === (int) $excludeLineId;

            if (! ($isExcludedLine && $excludeBundleItemId === null)) {
                $allAssignedSerials = array_merge($allAssignedSerials, (array) ($cartLine['assigned_serials'] ?? []));
            }

            foreach ((array) ($cartLine['bundle_item_serials'] ?? []) as $bId => $serials) {
                if ($isExcludedLine && $excludeBundleItemId !== null && (int) $bId === $excludeBundleItemId) {
                    continue;
                }
                $allAssignedSerials = array_merge($allAssignedSerials, (array) $serials);
            }
        }

        return $allAssignedSerials;
    }

    /**
     * @return array<string, mixed>
     */
    public function updateNote(int $settingId, int $sessionId, ?string $note): array
    {
        // A note is irrelevant to approval validity but losing it is still data
        // loss, so it is guarded like every other cart writer.
        return $this->withCartLock($settingId, $sessionId, function () use ($settingId, $sessionId, $note): array {
            $cart = $this->cartSessionStore->getCart($settingId, $sessionId);
            $this->assertActiveTransactionIsMutable($settingId, $cart);

            $cart['note'] = $note;

            $this->cartSessionStore->putCart($settingId, $sessionId, $cart);

            return $this->buildSnapshot($settingId, $sessionId, $cart);
        });
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

        return $this->withCartLock($settingId, $sessionId, function () use ($settingId, $sessionId): array {
            return $this->clearWithinLock($settingId, $sessionId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function clearWithinLock(int $settingId, int $sessionId): array
    {
        // Check if we have a loaded transaction that would become empty
        $currentCart = $this->cartSessionStore->getCart($settingId, $sessionId);
        $this->assertActiveTransactionIsMutable($settingId, $currentCart);
        $this->invalidateTotalPriceOverride($sessionId);
        $this->invalidateAllLineTotalOverrides($sessionId);

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
     * @return array{0: \Modules\Product\Entities\Product, 1: int|null}
     */
    private function resolveCartProduct(int $settingId, int $productId): array
    {
        $product = \Modules\Product\Entities\Product::query()
            ->active()
            ->where('id', $productId)
            ->where(function ($q) {
                $q->where('stock_managed', true)
                  ->orWhere('is_sold', true);
            })
            ->first();

        if (! $product) {
            throw new DomainException('Product was not found or is not sellable.');
        }

        // Guard: product must have a price row for the active setting
        $hasPriceRow = \Modules\Product\Entities\ProductPrice::query()
            ->forProduct($productId)
            ->forSetting($settingId)
            ->exists();

        if (! $hasPriceRow) {
            throw new DomainException('Product does not have a price configured for the active setting.');
        }

        if (! $product->stock_managed) {
            return [$product, null];
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
            ->when($query !== '', fn ($q) => $q->where('serial_number', 'LIKE', "%{$query}%"))
            ->limit($limit)
            ->get(['id', 'serial_number'])
            ->toArray();
    }

    /**
     * @return array<int, array{id: int, serial_number: string}>
     */
    public function availableSerialsForLine(int $settingId, int $sessionId, int $lineId, string $query, int $limit = 10): array
    {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);
        $line = $cart['lines'][$lineId] ?? null;

        if ($line === null) {
            return [];
        }

        $productId = (int) ($line['product_id'] ?? 0);
        if ($productId <= 0) {
            return [];
        }

        return $this->availableSerialsForProduct($settingId, $productId, $query, $limit);
    }

    /**
     * Look up a serial number and find or create a matching cart line, appending the serial.
     *
     * @return array<string, mixed>
     */
    public function appendSerialByLookup(int $settingId, int $sessionId, string $serialNumber): array
    {
        return $this->withCartLock($settingId, $sessionId, function () use ($settingId, $sessionId, $serialNumber): array {
            return $this->appendSerialByLookupWithinLock($settingId, $sessionId, $serialNumber);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function appendSerialByLookupWithinLock(int $settingId, int $sessionId, string $serialNumber): array
    {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);
        $this->assertActiveTransactionIsMutable($settingId, $cart);

        $allowedLocationIds = SalesLocationResolver::resolveLocationIds($settingId)->all();

        // 1. Find the active serial record
        $serialRecord = \Modules\Product\Entities\ProductSerialNumber::query()
            ->where('serial_number', $serialNumber)
            ->where('status', 'ACTIVE')
            ->whereNull('dispatch_detail_id')
            ->whereIn('location_id', $allowedLocationIds)
            ->first();

        if (! $serialRecord) {
            throw new DomainException("Serial number $serialNumber is not available or does not exist.");
        }

        $productId = (int) $serialRecord->product_id;

        // 2. Look for existing lines for this product
        $matchingLineKeys = [];
        foreach ($cart['lines'] as $k => $l) {
            if ((int) ($l['product_id'] ?? 0) === $productId) {
                $matchingLineKeys[] = $k;
            }
        }

        // Check if serial already assigned anywhere in the cart
        $allAssignedSerials = [];
        foreach ($cart['lines'] as $cartLine) {
            $allAssignedSerials = array_merge($allAssignedSerials, (array) ($cartLine['assigned_serials'] ?? []));
            foreach ((array) ($cartLine['bundle_item_serials'] ?? []) as $bId => $serials) {
                $allAssignedSerials = array_merge($allAssignedSerials, (array) $serials);
            }
        }

        if (in_array($serialNumber, $allAssignedSerials, true)) {
            throw new DomainException("Serial number $serialNumber is already assigned in this cart.");
        }

        // Try to find a matching line that has empty serial slots
        $targetLineId = null;
        foreach ($matchingLineKeys as $k) {
            $l = $cart['lines'][$k];
            $assigned = (array) ($l['assigned_serials'] ?? []);
            $qty = (int) ($l['qty'] ?? 0);
            if (count($assigned) < $qty) {
                $targetLineId = $k;
                break;
            }
        }

        // If no unfilled line exists, use the first matching line (which will auto-increment qty in appendSerialWithinLock)
        if ($targetLineId === null && ! empty($matchingLineKeys)) {
            $targetLineId = $matchingLineKeys[0];
        }

        // If no matching line exists at all, add a new line first with qty=1
        if ($targetLineId === null) {
            $this->addToCartWithinLock($settingId, $sessionId, $productId, 1);
            // Re-fetch the cart to find the newly created line ID
            $cart = $this->cartSessionStore->getCart($settingId, $sessionId);
            // The newest line will have product_id == $productId and empty assigned_serials
            foreach ($cart['lines'] as $k => $l) {
                if ((int) ($l['product_id'] ?? 0) === $productId && empty($l['assigned_serials'])) {
                    $targetLineId = $k;
                    break;
                }
            }
        }

        if ($targetLineId === null) {
            throw new DomainException('Could not create or find a cart line for this serial.');
        }

        return $this->appendSerialWithinLock($settingId, $sessionId, $targetLineId, $serialNumber);
    }

    /**
     * Append a serial number to a cart line.
     * If the line is full (assigned count == qty), auto-increment qty first.
     *
     * @return array<string, mixed>
     */
    public function appendSerial(int $settingId, int $sessionId, int $lineId, string $serialNumber, ?int $bundleItemId = null): array
    {
        return $this->withCartLock($settingId, $sessionId, function () use ($settingId, $sessionId, $lineId, $serialNumber, $bundleItemId): array {
            return $this->appendSerialWithinLock($settingId, $sessionId, $lineId, $serialNumber, $bundleItemId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function appendSerialWithinLock(int $settingId, int $sessionId, int $lineId, string $serialNumber, ?int $bundleItemId = null): array
    {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);
        $this->assertActiveTransactionIsMutable($settingId, $cart);
        $this->invalidateTotalPriceOverride($sessionId);
        $this->invalidateLineTotalOverride($sessionId, $lineId);
        $this->clearAppliedTotalOverride($cart);

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

        if ($bundleItemId !== null) {
            $bundleItems = (array) ($line['bundle_items'] ?? []);
            $foundItemIndex = null;
            foreach ($bundleItems as $idx => $bItem) {
                if ((int) ($bItem['bundle_item_id'] ?? 0) === $bundleItemId) {
                    $foundItemIndex = $idx;
                    break;
                }
            }

            if ($foundItemIndex === null) {
                throw new DomainException('Komponen paket tidak ditemukan pada baris keranjang ini.');
            }

            $targetItem = $bundleItems[$foundItemIndex];
            $componentProductId = (int) ($targetItem['product_id'] ?? 0);
            $liveComponentProduct = $componentProductId > 0 ? \Modules\Product\Entities\Product::find($componentProductId) : null;
            $isSerialRequired = $liveComponentProduct ? (bool) $liveComponentProduct->serial_number_required : (bool) ($targetItem['serial_number_required'] ?? false);

            if (! $isSerialRequired) {
                throw new DomainException('Komponen ini tidak memerlukan nomor seri.');
            }

            $allowedLocationIds = SalesLocationResolver::resolveLocationIds($settingId)->all();

            $record = \Modules\Product\Entities\ProductSerialNumber::query()
                ->where('product_id', $componentProductId)
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

            $assignedSerials = (array) ($line['bundle_item_serials'][$bundleItemId] ?? ($targetItem['assigned_serials'] ?? []));

            // Check for duplicate across all cart lines and components (excluding this
            // line's own component's already-assigned set, which is checked separately
            // below) — the exclusion only covers the OTHER-position collision check, so
            // a serial already present in this exact component's own set is still caught.
            $allAssignedSerials = $this->collectCartWideAssignedSerials($cart, $lineId, $bundleItemId);

            if (in_array($serialNumber, $allAssignedSerials, true) || in_array($serialNumber, $assignedSerials, true)) {
                throw new DomainException("Serial number $serialNumber is already assigned in this cart.");
            }

            $parentQty = (int) ($line['qty'] ?? 0);
            $requiredComponentQty = $this->resolveRequiredComponentSerialQty($parentQty, $targetItem);

            if (count($assignedSerials) >= $requiredComponentQty) {
                throw new PosCheckoutValidationException(
                    'SERIAL_CAPACITY_EXCEEDED',
                    "Kapasitas nomor seri untuk komponen ini telah penuh ({$requiredComponentQty})."
                );
            }

            $assignedSerials[] = $serialNumber;
            $cart['lines'][$lineId]['bundle_item_serials'][$bundleItemId] = $assignedSerials;
            $cart['lines'][$lineId]['bundle_items'][$foundItemIndex]['assigned_serials'] = $assignedSerials;

            $this->cartSessionStore->putCart($settingId, $sessionId, $cart);

            return $this->buildSnapshot($settingId, $sessionId, $cart);
        }

        $productId = (int) ($line['product_id'] ?? 0);
        $liveProduct = $productId > 0 ? \Modules\Product\Entities\Product::find($productId) : null;
        $isSerialRequired = $liveProduct ? (bool) $liveProduct->serial_number_required : (bool) ($line['serial_number_required'] ?? false);

        if (! $isSerialRequired) {
            throw new DomainException('This product does not require serial numbers.');
        }

        // Validate serial exists and is available
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

        $assignedSerials = (array) ($line['assigned_serials'] ?? []);

        // Check for duplicate across all cart lines and components (excluding this
        // line's own already-assigned set, which is checked separately below) — a
        // serial already present in this exact parent line's own set is still caught.
        $allAssignedSerials = $this->collectCartWideAssignedSerials($cart, $lineId, null);

        if (in_array($serialNumber, $allAssignedSerials, true) || in_array($serialNumber, $assignedSerials, true)) {
            throw new DomainException("Serial number $serialNumber is already assigned in this cart.");
        }

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

            // The quantity just changed, so any canonical override metadata is
            // stale: recompute it for a unit-price override, or revert a
            // row-total override to standard pricing.
            $cart['lines'][$lineId] = $this->refreshOrInvalidateRowOverride(
                $settingId,
                $cart['lines'][$lineId],
                $cart
            );
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
    public function removeSerial(int $settingId, int $sessionId, int $lineId, string $serialNumber, ?int $bundleItemId = null): array
    {
        return $this->withCartLock($settingId, $sessionId, function () use ($settingId, $sessionId, $lineId, $serialNumber, $bundleItemId): array {
            return $this->removeSerialWithinLock($settingId, $sessionId, $lineId, $serialNumber, $bundleItemId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function removeSerialWithinLock(int $settingId, int $sessionId, int $lineId, string $serialNumber, ?int $bundleItemId = null): array
    {
        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);
        $this->assertActiveTransactionIsMutable($settingId, $cart);
        $this->invalidateTotalPriceOverride($sessionId);
        $this->invalidateLineTotalOverride($sessionId, $lineId);
        $this->clearAppliedTotalOverride($cart);

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

        if ($bundleItemId !== null) {
            $bundleItems = (array) ($line['bundle_items'] ?? []);
            $foundItemIndex = null;
            foreach ($bundleItems as $idx => $bItem) {
                if ((int) ($bItem['bundle_item_id'] ?? 0) === $bundleItemId) {
                    $foundItemIndex = $idx;
                    break;
                }
            }

            if ($foundItemIndex === null) {
                return $this->buildSnapshot($settingId, $sessionId, $cart);
            }

            $targetItem = $bundleItems[$foundItemIndex];
            $assignedSerials = (array) ($line['bundle_item_serials'][$bundleItemId] ?? ($targetItem['assigned_serials'] ?? []));
            $key = array_search($serialNumber, $assignedSerials, true);

            if ($key === false) {
                return $this->buildSnapshot($settingId, $sessionId, $cart);
            }

            unset($assignedSerials[$key]);
            $assignedSerials = array_values($assignedSerials);
            $cart['lines'][$lineId]['bundle_item_serials'][$bundleItemId] = $assignedSerials;
            $cart['lines'][$lineId]['bundle_items'][$foundItemIndex]['assigned_serials'] = $assignedSerials;

            $this->cartSessionStore->putCart($settingId, $sessionId, $cart);

            return $this->buildSnapshot($settingId, $sessionId, $cart);
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

        $product = Product::query()->active()->whereKey($productId)->with('unit')->first();
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

    /**
     * Invalidate total-price override requests and clear applied overrides.
     * Called before any relevant cart mutation.
     */
    private function invalidateTotalPriceOverride(int $sessionId): void
    {
        PosActionApprovalRequest::query()
            ->where('pos_session_id', $sessionId)
            ->where('action_type', PosActionApprovalRequest::ACTION_TOTAL_PRICE_OVERRIDE)
            ->whereIn('status', [
                PosActionApprovalRequest::STATUS_PENDING,
                PosActionApprovalRequest::STATUS_APPROVED,
            ])
            ->update(['status' => PosActionApprovalRequest::STATUS_CANCELLED]);
    }

    /**
     * Invalidate pending/approved requests for BOTH active row overrides on a line.
     */
    private function invalidateLineTotalOverride(int $sessionId, int $lineId): void
    {
        PosActionApprovalRequest::query()
            ->where('pos_session_id', $sessionId)
            ->where('target_id', $lineId)
            ->whereIn('action_type', PosActionApprovalRequest::ROW_OVERRIDE_ACTIONS)
            ->whereIn('status', [
                PosActionApprovalRequest::STATUS_PENDING,
                PosActionApprovalRequest::STATUS_APPROVED,
            ])
            ->update(['status' => PosActionApprovalRequest::STATUS_CANCELLED]);
    }

    /**
     * Invalidate pending/approved requests for BOTH active row overrides across a session.
     */
    private function invalidateAllLineTotalOverrides(int $sessionId): void
    {
        PosActionApprovalRequest::query()
            ->where('pos_session_id', $sessionId)
            ->whereIn('action_type', PosActionApprovalRequest::ROW_OVERRIDE_ACTIONS)
            ->whereIn('status', [
                PosActionApprovalRequest::STATUS_PENDING,
                PosActionApprovalRequest::STATUS_APPROVED,
            ])
            ->update(['status' => PosActionApprovalRequest::STATUS_CANCELLED]);
    }

    /**
     * Clear price_source = TOTAL_OVERRIDE from cart lines and restore original state.
     */
    private function clearAppliedTotalOverride(array &$cart): void
    {
        foreach ($cart['lines'] as &$line) {
            if (($line['price_source'] ?? null) === 'TOTAL_OVERRIDE') {
                // Restore original state from override metadata
                if (isset($line['_original_unit_price'])) {
                    $line['unit_price'] = $line['_original_unit_price'];
                }
                if (isset($line['_original_line_total'])) {
                    $line['line_total'] = $line['_original_line_total'];
                } else {
                    unset($line['line_total']);
                }
                if (isset($line['_original_price_source'])) {
                    $line['price_source'] = $line['_original_price_source'];
                } else {
                    $line['price_source'] = 'BASE';
                }
                // Clean up metadata
                unset($line['_original_unit_price'], $line['_original_line_total'], $line['_original_price_source']);
            }
        }
    }

    private function buildMergeKey(int $productId, float $unitPrice, ?int $taxId, ?int $conversionId = null, ?int $bundleId = null): string
    {
        return PosMergeKeyGenerator::build($productId, $unitPrice, $taxId, $conversionId, $bundleId);
    }
}
