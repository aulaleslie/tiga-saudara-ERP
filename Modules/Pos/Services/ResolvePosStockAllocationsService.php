<?php

namespace Modules\Pos\Services;

use App\Support\SalesLocationResolver;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Support\PendingDispatchSerialGuard;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Tax;

class ResolvePosStockAllocationsService
{
    /** @var array<string, int|null> */
    private array $productSaleTaxCache = [];

    private ?Tax $fallbackTax = null;

    private bool $fallbackTaxResolved = false;

    /**
     * Resolve stock allocations for a set of cart lines across configured sales locations.
     *
     * For non-serial taxable lines: allocates from non-tax bucket first (all locations),
     * then from tax bucket (all locations) if needed.
     *
     * @param int $settingId Current business setting ID
     * @param array<int, array{
     *     line_id?: int,
     *     product_id: int,
     *     product_code?: string|null,
     *     product_name?: string|null,
     *     qty: int,
     *     tax_id: int|null,
     *     serial_number_required?: bool,
     *     assigned_serials?: array<int, string>
     * }> $cartLines
     * @return array{
     *     allocations: array<int, array<int, array{source_location_id: int, source_setting_id: int, allocated_qty: int, tax_bucket_used: bool}>>,
     *     unfulfilled_lines: array<int>,
     *     unfulfilled_details: array<int, array{
     *         line_index:int,
     *         line_id:int|null,
     *         product_id:int,
     *         product_name:string|null,
     *         product_code:string|null,
     *         reason_code:string,
     *         requested_qty:int,
     *         allocated_qty:int
     *     }>
     * }
     */
    public function resolve(int $settingId, array $cartLines): array
    {
        $saleLocations = SettingSaleLocation::query()
            ->join('locations', 'setting_sale_locations.location_id', '=', 'locations.id')
            ->where('setting_sale_locations.setting_id', $settingId)
            ->where('setting_sale_locations.is_enabled', true)
            ->orderBy('setting_sale_locations.position')
            ->orderBy('locations.name')
            ->orderBy('locations.id')
            ->select([
                'setting_sale_locations.location_id',
                'locations.setting_id as source_setting_id',
            ])
            ->get();

        $configuredSources = [];
        $locationIds = [];
        foreach ($saleLocations as $record) {
            $locId = (int) $record->location_id;
            $srcSettingId = (int) $record->source_setting_id;
            if ($locId > 0) {
                $configuredSources[] = [
                    'location_id' => $locId,
                    'source_setting_id' => $srcSettingId > 0 ? $srcSettingId : $settingId,
                ];
                if (! in_array($locId, $locationIds, true)) {
                    $locationIds[] = $locId;
                }
            }
        }

        $allocations = [];
        $unfulfilledLines = [];
        $unfulfilledDetails = [];

        // Cache for settings to avoid N+1 lookups for source businesses
        $settingsCache = [];
        // Cache for taxes to avoid N+1 lookups
        $taxesCache = [];

        foreach ($cartLines as $index => $line) {
            $productId = (int) $line['product_id'];
            $neededQty = (int) $line['qty'];
            $taxId = isset($line['tax_id']) ? (int) $line['tax_id'] : null;
            $serialRequired = (bool) ($line['serial_number_required'] ?? false);
            $assignedSerials = array_values(array_filter(
                (array) ($line['assigned_serials'] ?? []),
                static fn ($serial): bool => is_string($serial) && trim($serial) !== ''
            ));
            $reasonCode = null;

            if ($serialRequired) {
                $serialResult = $this->allocateSerialLineUsingAssignedSerials(
                    $productId,
                    $neededQty,
                    $assignedSerials,
                    $locationIds,
                    $settingId,
                    $settingsCache,
                    $taxesCache
                );

                $lineAllocations = $serialResult['allocations'];
                $reasonCode = $serialResult['reason_code'];
            } else {
                $lineAllocations = $this->allocateLineBucketFirst(
                    $productId,
                    $neededQty,
                    $taxId,
                    $configuredSources,
                    $settingId,
                    $settingsCache,
                    $taxesCache
                );
            }

            $allocatedQty = array_sum(array_map(
                fn ($alloc): int => (int) ($alloc['allocated_qty'] ?? 0),
                $lineAllocations
            ));

            $allocations[$index] = $lineAllocations;
            if ($allocatedQty < $neededQty || $reasonCode !== null) {
                $unfulfilledLines[] = $index;
                $unfulfilledDetails[] = [
                    'line_index' => $index,
                    'line_id' => isset($line['line_id']) ? (int) $line['line_id'] : null,
                    'product_id' => $productId,
                    'product_name' => isset($line['product_name']) ? (string) $line['product_name'] : null,
                    'product_code' => isset($line['product_code']) ? (string) $line['product_code'] : null,
                    'reason_code' => $reasonCode ?? 'INSUFFICIENT_STOCK',
                    'requested_qty' => $neededQty,
                    'allocated_qty' => $allocatedQty,
                ];
            }
        }

        return [
            'allocations' => $allocations,
            'unfulfilled_lines' => $unfulfilledLines,
            'unfulfilled_details' => $unfulfilledDetails,
        ];
    }

    /**
     * @param  int  $productId
     * @param  int  $neededQty
     * @param  array<int, string>  $assignedSerials
     * @param  array<int>  $locationIds
     * @param  int  $settingId
     * @param  array<int, Setting|null>  $settingsCache
     * @param  array<int, Tax|null>  $taxesCache
     * @return array{
     *     allocations: array<int, array<string, mixed>>,
     *     reason_code: string|null
     * }
     */
    private function allocateSerialLineUsingAssignedSerials(
        int $productId,
        int $neededQty,
        array $assignedSerials,
        array $locationIds,
        int $settingId,
        array &$settingsCache,
        array &$taxesCache
    ): array {
        if ($neededQty <= 0) {
            return [
                'allocations' => [],
                'reason_code' => 'LINE_QTY_INVALID',
            ];
        }

        if ($assignedSerials === [] || count($assignedSerials) !== $neededQty) {
            return [
                'allocations' => [],
                'reason_code' => 'SERIAL_ASSIGNMENT_MISMATCH',
            ];
        }

        $serialRows = ProductSerialNumber::query()
            ->where('product_id', $productId)
            ->whereIn('serial_number', $assignedSerials)
            ->get()
            ->keyBy('serial_number');

        $grouped = [];
        foreach ($assignedSerials as $serialNumber) {
            $record = $serialRows->get($serialNumber);
            if (! $record) {
                return [
                    'allocations' => [],
                    'reason_code' => 'SERIAL_NOT_FOUND',
                ];
            }

            if (strtoupper((string) $record->status) !== ProductSerialNumber::STATUS_ACTIVE || $record->dispatch_detail_id !== null) {
                return [
                    'allocations' => [],
                    'reason_code' => 'SERIAL_NOT_ACTIVE',
                ];
            }

            if (PendingDispatchSerialGuard::isReserved($serialNumber)) {
                return [
                    'allocations' => [],
                    'reason_code' => 'SERIAL_PENDING_DISPATCH',
                ];
            }

            $sourceLocationId = (int) $record->location_id;
            if ($sourceLocationId <= 0) {
                return [
                    'allocations' => [],
                    'reason_code' => 'SERIAL_LOCATION_INVALID',
                ];
            }

            if (! in_array($sourceLocationId, $locationIds, true)) {
                return [
                    'allocations' => [],
                    'reason_code' => 'SERIAL_LOCATION_NOT_ALLOWED',
                ];
            }

            $location = Location::query()->find($sourceLocationId);
            $sourceSettingId = $location ? (int) $location->setting_id : $settingId;

            if (! isset($settingsCache[$sourceSettingId])) {
                $settingsCache[$sourceSettingId] = Setting::query()->find($sourceSettingId);
            }
            $sourceSetting = $settingsCache[$sourceSettingId];
            $sourceIsPkp = (bool) ($sourceSetting?->is_pkp ?? false);

            $resolvedTaxId = ((int) ($record->tax_id ?? 0) > 0) ? (int) $record->tax_id : null;

            $tax = null;
            if ($resolvedTaxId !== null) {
                if (! isset($taxesCache[$resolvedTaxId])) {
                    $taxesCache[$resolvedTaxId] = Tax::query()->find($resolvedTaxId);
                }
                $tax = $taxesCache[$resolvedTaxId];
            }

            $groupKey = $sourceSettingId
                . ':'
                . $sourceLocationId
                . ':'
                . ($resolvedTaxId !== null ? 'TAX:' . $resolvedTaxId : 'NON_TAX');

            if (! isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [
                    'source_location_id' => $sourceLocationId,
                    'source_setting_id' => $sourceSettingId,
                    'allocated_qty' => 0,
                    'serial_numbers' => [],
                    'tax_bucket_used' => $resolvedTaxId !== null,
                    'tax_policy_snapshot' => [
                        'source_is_pkp' => $sourceIsPkp,
                        'tax_id' => $resolvedTaxId,
                        'tax_name' => $tax ? (string) $tax->name : null,
                        'tax_rate' => $tax ? (float) $tax->value : 0.0,
                    ],
                ];
            }

            $grouped[$groupKey]['allocated_qty']++;
            $grouped[$groupKey]['serial_numbers'][] = $serialNumber;
        }

        $allocations = array_values($grouped);
        foreach ($allocations as $allocation) {
            $chunkQty = (int) ($allocation['allocated_qty'] ?? 0);
            $chunkLocationId = (int) ($allocation['source_location_id'] ?? 0);
            $snapshot = is_array($allocation['tax_policy_snapshot'] ?? null)
                ? $allocation['tax_policy_snapshot']
                : [];
            $chunkTaxId = isset($snapshot['tax_id']) ? (int) $snapshot['tax_id'] : null;
            $chunkTaxId = $chunkTaxId !== null && $chunkTaxId > 0 ? $chunkTaxId : null;

            $stock = ProductStock::query()
                ->where('product_id', $productId)
                ->where('location_id', $chunkLocationId)
                ->first();

            if (! $stock) {
                return [
                    'allocations' => [],
                    'reason_code' => 'SERIAL_STOCK_MISSING',
                ];
            }

            if ((int) $stock->quantity < $chunkQty) {
                return [
                    'allocations' => [],
                    'reason_code' => 'SERIAL_STOCK_UNAVAILABLE',
                ];
            }

            if ($chunkTaxId !== null && (int) $stock->quantity_tax < $chunkQty) {
                return [
                    'allocations' => [],
                    'reason_code' => 'SERIAL_TAX_STOCK_UNAVAILABLE',
                ];
            }

            if ($chunkTaxId === null && (int) $stock->quantity_non_tax < $chunkQty) {
                return [
                    'allocations' => [],
                    'reason_code' => 'SERIAL_NON_TAX_STOCK_UNAVAILABLE',
                ];
            }
        }

        return [
            'allocations' => $allocations,
            'reason_code' => null,
        ];
    }

    /**
     * Allocate a non-serial line sequentially across configured (location_id, setting_id) sources.
     *
     * Each source's PKP status determines which single bucket it is allowed to consume:
     * a PKP source may only consume quantity_tax, and a non-PKP source may only consume
     * quantity_non_tax. A source whose stock exists only in the bucket incompatible with its
     * PKP status contributes nothing here; it is surfaced by the caller as insufficient stock
     * (an actionable validation failure) rather than silently falling back to the wrong bucket.
     *
     * @param  int  $productId
     * @param  int  $neededQty
     * @param  int|null  $taxId
     * @param  array<int, array{location_id: int, source_setting_id: int}>  $configuredSources
     * @param  int  $settingId
     * @param  array<int, Setting|null>  $settingsCache
     * @param  array<int, Tax|null>  $taxesCache
     * @return array<int, array<string, mixed>>
     */
    private function allocateLineBucketFirst(
        int $productId,
        int $neededQty,
        ?int $taxId,
        array $configuredSources,
        int $settingId,
        array &$settingsCache,
        array &$taxesCache
    ): array {
        $lineAllocations = [];
        $remainingQty = $neededQty;

        foreach ($configuredSources as $source) {
            if ($remainingQty <= 0) {
                break;
            }

            $locationId = (int) $source['location_id'];
            $sourceSettingId = (int) $source['source_setting_id'];

            if (! isset($settingsCache[$sourceSettingId])) {
                $settingsCache[$sourceSettingId] = Setting::query()->find($sourceSettingId);
            }
            $sourceSetting = $settingsCache[$sourceSettingId];
            $sourceIsPkp = (bool) ($sourceSetting?->is_pkp ?? false);

            $stock = ProductStock::query()
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->first();

            if (! $stock) {
                continue;
            }

            if ($sourceIsPkp) {
                // PKP sources hold and consume only quantity_tax.
                $availableTax = (int) $stock->quantity_tax;
                if ($availableTax <= 0) {
                    continue;
                }

                $take = min($remainingQty, $availableTax);

                [$effectiveTaxId, $taxName, $taxRate] = $this->resolveAllocationTaxPolicySnapshot(
                    productId: $productId,
                    terminalSettingId: $settingId,
                    explicitLineTaxId: $taxId,
                    stockTaxId: isset($stock->tax_id) ? (int) $stock->tax_id : null,
                    sourceIsPkp: $sourceIsPkp,
                    taxBucketUsed: true,
                    taxesCache: $taxesCache,
                );

                $lineAllocations[] = [
                    'source_location_id' => $locationId,
                    'source_setting_id' => $sourceSettingId,
                    'allocated_qty' => $take,
                    'tax_bucket_used' => true,
                    'tax_policy_snapshot' => [
                        'source_is_pkp' => $sourceIsPkp,
                        'tax_id' => $effectiveTaxId,
                        'tax_name' => $taxName,
                        'tax_rate' => $taxRate,
                    ],
                ];

                $remainingQty -= $take;
            } else {
                // Non-PKP sources hold and consume only quantity_non_tax.
                $availableNonTax = (int) $stock->quantity_non_tax;
                if ($availableNonTax <= 0) {
                    continue;
                }

                $take = min($remainingQty, $availableNonTax);

                [$effectiveTaxId, $taxName, $taxRate] = $this->resolveAllocationTaxPolicySnapshot(
                    productId: $productId,
                    terminalSettingId: $settingId,
                    explicitLineTaxId: $taxId,
                    stockTaxId: isset($stock->tax_id) ? (int) $stock->tax_id : null,
                    sourceIsPkp: $sourceIsPkp,
                    taxBucketUsed: false,
                    taxesCache: $taxesCache,
                );

                $lineAllocations[] = [
                    'source_location_id' => $locationId,
                    'source_setting_id' => $sourceSettingId,
                    'allocated_qty' => $take,
                    'tax_bucket_used' => false,
                    'tax_policy_snapshot' => [
                        'source_is_pkp' => $sourceIsPkp,
                        'tax_id' => $effectiveTaxId,
                        'tax_name' => $taxName,
                        'tax_rate' => $taxRate,
                    ],
                ];

                $remainingQty -= $take;
            }
        }

        return $lineAllocations;
    }

    private function getAvailableStock(ProductStock $stock, ?int $taxId): int
    {
        // This method is now unused by resolve() but kept for compatibility/other callers if any
        if ($taxId !== null) {
            return (int) $stock->quantity_tax;
        }
        
        return (int) $stock->quantity_non_tax;
    }

    /**
     * @param  array<int, Tax|null>  $taxesCache
     * @return array{0:int|null,1:string|null,2:float}
     */
    private function resolveAllocationTaxPolicySnapshot(
        int $productId,
        int $terminalSettingId,
        ?int $explicitLineTaxId,
        ?int $stockTaxId,
        bool $sourceIsPkp,
        bool $taxBucketUsed,
        array &$taxesCache
    ): array {
        // Tax fallback resolution only ever applies once the source owner is established as
        // PKP; a non-PKP source is never taxable regardless of which bucket it consumed from.
        if (! $sourceIsPkp) {
            return [null, null, 0.0];
        }

        $resolvedTaxId = $this->resolveTaxCandidateId(
            $productId,
            $terminalSettingId,
            $explicitLineTaxId,
            $stockTaxId
        );

        if ($resolvedTaxId === null) {
            return [null, null, 0.0];
        }

        if (! isset($taxesCache[$resolvedTaxId])) {
            $taxesCache[$resolvedTaxId] = Tax::query()->find($resolvedTaxId);
        }

        $tax = $taxesCache[$resolvedTaxId];

        if (! $tax) {
            return [null, null, 0.0];
        }

        return [
            (int) $tax->id,
            (string) $tax->name,
            (float) $tax->value,
        ];
    }

    private function resolveTaxCandidateId(
        int $productId,
        int $terminalSettingId,
        ?int $explicitLineTaxId,
        ?int $stockTaxId
    ): ?int {
        $explicitLineTaxId = $explicitLineTaxId !== null && $explicitLineTaxId > 0 ? $explicitLineTaxId : null;
        if ($explicitLineTaxId !== null) {
            return $explicitLineTaxId;
        }

        $productSaleTaxId = $this->productSaleTaxId($productId, $terminalSettingId);
        if ($productSaleTaxId !== null) {
            return $productSaleTaxId;
        }

        $stockTaxId = $stockTaxId !== null && $stockTaxId > 0 ? $stockTaxId : null;
        if ($stockTaxId !== null) {
            return $stockTaxId;
        }

        return $this->fallbackTaxId();
    }

    private function productSaleTaxId(int $productId, int $terminalSettingId): ?int
    {
        $cacheKey = $productId . ':' . $terminalSettingId;
        if (array_key_exists($cacheKey, $this->productSaleTaxCache)) {
            return $this->productSaleTaxCache[$cacheKey];
        }

        $productSaleTaxId = Product::query()->whereKey($productId)->value('sale_tax_id');
        if ((int) $productSaleTaxId > 0) {
            return $this->productSaleTaxCache[$cacheKey] = (int) $productSaleTaxId;
        }

        $productPriceSaleTaxId = ProductPrice::query()
            ->where('product_id', $productId)
            ->where('setting_id', $terminalSettingId)
            ->orderByDesc('id')
            ->value('sale_tax_id');

        return $this->productSaleTaxCache[$cacheKey] = ((int) $productPriceSaleTaxId > 0)
            ? (int) $productPriceSaleTaxId
            : null;
    }

    private function fallbackTaxId(): ?int
    {
        if (! $this->fallbackTaxResolved) {
            $this->fallbackTax = Tax::query()
                ->where('is_default', true)
                ->orderByDesc('id')
                ->first();

            if (! $this->fallbackTax) {
                $this->fallbackTax = Tax::query()->orderByDesc('id')->first();
            }

            $this->fallbackTaxResolved = true;
        }

        return $this->fallbackTax ? (int) $this->fallbackTax->id : null;
    }
}
