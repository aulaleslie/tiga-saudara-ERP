<?php

namespace Modules\Pos\Services;

use App\Support\SalesLocationResolver;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
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
    public function addLine(int $settingId, int $sessionId, int $productId, int $qty = 1): array
    {
        if ($qty < 1) {
            throw new DomainException('Quantity must be at least 1.');
        }

        $cart = $this->cartSessionStore->getCart($settingId, $sessionId);
        $lineKey = (string) $productId;
        $existingLine = $cart['lines'][$lineKey] ?? null;

        [$product, $availableQty] = $this->resolveCartProduct($settingId, $productId);

        $baseQty = (int) ($existingLine['qty'] ?? 0);
        $newQty = $baseQty + $qty;

        if ($newQty > $availableQty) {
            throw new DomainException('Requested quantity exceeds available stock for configured sales locations.');
        }

        $priceRow = ProductPrice::query()
            ->forProduct($product->id)
            ->forSetting($settingId)
            ->first();

        $saleTaxId = (int) ($priceRow?->sale_tax_id ?? 0);
        $tax = $saleTaxId > 0 ? Tax::query()->find($saleTaxId) : null;
        $unitPrice = (float) ($existingLine['unit_price'] ?? ($priceRow?->sale_price ?? $product->product_price ?? 0));

        $cart['lines'][$lineKey] = [
            'line_id' => (int) $product->id,
            'product_id' => (int) $product->id,
            'product_name' => (string) $product->product_name,
            'product_code' => (string) ($product->product_code ?? ''),
            'barcode' => $product->barcode !== null ? (string) $product->barcode : null,
            'serial_number_required' => (bool) $product->serial_number_required,
            'assigned_serials' => [],
            'qty' => $newQty,
            'available_qty' => $availableQty,
            'unit_price' => round($unitPrice, 2),
            'line_discount_type' => $this->normalizeDiscountType((string) ($existingLine['line_discount_type'] ?? 'fixed')),
            'line_discount_value' => round((float) ($existingLine['line_discount_value'] ?? 0), 2),
            'tax_id' => $tax ? (int) $tax->id : null,
            'tax_name' => $tax ? (string) $tax->name : null,
            'tax_rate' => $tax ? (float) $tax->value : 0.0,
        ];

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
        $lineKey = (string) $lineId;

        if (! isset($cart['lines'][$lineKey])) {
            throw new DomainException('Cart line was not found.');
        }

        $line = $cart['lines'][$lineKey];
        $qty = (int) ($payload['qty'] ?? $line['qty']);
        if ($qty < 1) {
            throw new DomainException('Quantity must be at least 1.');
        }

        $availableQty = (int) ($line['available_qty'] ?? 0);
        if ($availableQty > 0 && $qty > $availableQty) {
            throw new DomainException('Requested quantity exceeds available stock for configured sales locations.');
        }

        $discountType = $payload['line_discount_type'] ?? $line['line_discount_type'] ?? 'fixed';
        $discountValue = (float) ($payload['line_discount_value'] ?? $line['line_discount_value'] ?? 0);

        $assignedSerials = (array) ($line['assigned_serials'] ?? []);
        if ($qty !== (int) $line['qty']) {
            $assignedSerials = [];
        }

        $cart['lines'][$lineKey] = array_merge($line, [
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
        $lineKey = (string) $lineId;

        if (! isset($cart['lines'][$lineKey])) {
            throw new DomainException('Cart line was not found.');
        }

        unset($cart['lines'][$lineKey]);
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
        $lineKey = (string) $lineId;

        if (! isset($cart['lines'][$lineKey])) {
            throw new DomainException('Cart line was not found.');
        }

        $line = $cart['lines'][$lineKey];
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

        $cart['lines'][$lineKey] = array_merge($line, [
            'unit_price' => round($unitPrice, 2),
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
        $lineKey = (string) $lineId;

        if (! isset($cart['lines'][$lineKey])) {
            throw new DomainException('Cart line was not found.');
        }

        $line = $cart['lines'][$lineKey];
        if (! (bool) ($line['serial_number_required'] ?? false)) {
            throw new DomainException('This product does not require serial numbers.');
        }

        $qty = (int) ($line['qty'] ?? 0);
        if (count($serialNumbers) !== $qty) {
            throw new DomainException("Quantity mismatch: expected $qty serial(s), got " . count($serialNumbers));
        }

        if (count($serialNumbers) !== count(array_unique($serialNumbers))) {
            throw new DomainException('Duplicate serial numbers provided.');
        }

        $productId = (int) ($line['product_id'] ?? 0);
        $taxId = $line['tax_id'] ?? null;
        $allowedLocationIds = SalesLocationResolver::resolveLocationIds($settingId)->all();

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

            // Tax match validation
            $isTaxedItem = ! empty($taxId) && (int) $taxId > 0;
            if ($isTaxedItem && $record->tax_id === null) {
                throw new DomainException("Serial number $sn is non-taxed, but line is taxed.");
            }
            if (! $isTaxedItem && $record->tax_id !== null) {
                throw new DomainException("Serial number $sn is taxed, but line is non-taxed.");
            }
        }

        $cart['lines'][$lineKey]['assigned_serials'] = $serialNumbers;
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
            ->where('setting_id', $settingId)
            ->where('stock_managed', true)
            ->first();

        if (! $product) {
            throw new DomainException('Product was not found for active setting.');
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
}
