<?php

namespace Modules\Pos\Services;

use App\Support\SalesLocationResolver;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;

class ResolvePosStockAllocationsService
{
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
     *         product_code:string|null,
     *         reason_code:string,
     *         requested_qty:int,
     *         allocated_qty:int
     *     }>
     * }
     */
    public function resolve(int $settingId, array $cartLines): array
    {
        $locationIds = array_values(array_map(
            static fn ($locationId): int => (int) $locationId,
            SalesLocationResolver::resolveLocationIds($settingId)->all()
        ));

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
                    $taxId,
                    $assignedSerials,
                    $locationIds,
                    $settingId,
                    $settingsCache,
                    $taxesCache
                );

                $lineAllocations = $serialResult['allocations'];
                $reasonCode = $serialResult['reason_code'];
            } else {
                // Determine allocation strategy based on tax requirement.
                $isTaxable = $taxId !== null && (int) $taxId > 0;

                if ($isTaxable) {
                    // Taxable line: use bucket-first strategy.
                    $lineAllocations = $this->allocateTaxableLineBucketFirst(
                        $productId,
                        $neededQty,
                        $taxId,
                        $locationIds,
                        $settingId,
                        $settingsCache,
                        $taxesCache
                    );
                } else {
                    // Non-taxable line: only use non-tax bucket.
                    $lineAllocations = $this->allocateNonTaxableLineNonTaxBucketOnly(
                        $productId,
                        $neededQty,
                        $locationIds,
                        $settingId,
                        $settingsCache,
                        $taxesCache
                    );
                }
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
     * @param  int|null  $lineTaxId
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
        ?int $lineTaxId,
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

            $resolvedTaxId = $lineTaxId !== null && $lineTaxId > 0
                ? (int) $lineTaxId
                : ((int) ($record->tax_id ?? 0) > 0 ? (int) $record->tax_id : null);

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
     * Allocate a taxable line using owner-priority bucket-first strategy:
     * Phase 1: Allocate from non-tax bucket prioritized by owner (non-PKP first, then PKP)
     * Phase 2: Allocate from tax bucket prioritized by owner (non-PKP first, then PKP)
     *
     * Within each owner-priority group, configured location order is preserved.
     *
     * @param  int  $productId
     * @param  int  $neededQty
     * @param  int  $taxId
     * @param  array<int>  $locationIds
     * @param  int  $settingId
     * @param  array<int, Setting|null>  $settingsCache
     * @param  array<int, Location|null>  $locationsCache
     * @param  array<int, Tax|null>  $taxesCache
     * @return array<int, array<string, mixed>>
     */
    private function allocateTaxableLineBucketFirst(
        int $productId,
        int $neededQty,
        int $taxId,
        $locationIds,
        int $settingId,
        array &$settingsCache,
        array &$taxesCache
    ): array {
        // Build owner-priority partitions from location IDs
        $nonPkpLocations = [];
        $pkpLocations = [];
        $locationsCache = [];

        foreach ($locationIds as $locationId) {
            $location = Location::query()->find($locationId);
            $sourceSettingId = $location ? (int) $location->setting_id : $settingId;

            if (! isset($settingsCache[$sourceSettingId])) {
                $settingsCache[$sourceSettingId] = Setting::query()->find($sourceSettingId);
            }
            $sourceSetting = $settingsCache[$sourceSettingId];
            $sourceIsPkp = (bool) ($sourceSetting?->is_pkp ?? false);

            $locationsCache[$locationId] = [
                'location' => $location,
                'source_setting_id' => $sourceSettingId,
                'source_is_pkp' => $sourceIsPkp,
            ];

            if ($sourceIsPkp) {
                $pkpLocations[] = $locationId;
            } else {
                $nonPkpLocations[] = $locationId;
            }
        }

        // Merge: non-PKP first, then PKP, within each preserve original location order
        $prioritizedLocations = array_merge($nonPkpLocations, $pkpLocations);

        $lineAllocations = [];
        $remainingQty = $neededQty;

        // Phase 1: Allocate from non-tax bucket (owner-priority order)
        foreach ($prioritizedLocations as $locationId) {
            if ($remainingQty <= 0) {
                break;
            }

            $stock = ProductStock::query()
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->first();

            if (! $stock) {
                continue;
            }

            $available = (int) $stock->quantity_non_tax;
            if ($available > 0) {
                $take = min($remainingQty, $available);

                $locInfo = $locationsCache[$locationId];
                $sourceSettingId = $locInfo['source_setting_id'];
                $sourceIsPkp = $locInfo['source_is_pkp'];

                $tax = null;
                if (! isset($taxesCache[$taxId])) {
                    $taxesCache[$taxId] = Tax::query()->find($taxId);
                }
                $tax = $taxesCache[$taxId];

                $lineAllocations[] = [
                    'source_location_id' => $locationId,
                    'source_setting_id' => $sourceSettingId,
                    'allocated_qty' => $take,
                    'tax_bucket_used' => false,
                    'tax_policy_snapshot' => [
                        'source_is_pkp' => $sourceIsPkp,
                        'tax_id' => $taxId,
                        'tax_name' => $tax ? (string) $tax->name : null,
                        'tax_rate' => $tax ? (float) $tax->value : 0.0,
                    ],
                ];

                $remainingQty -= $take;
            }
        }

        // Phase 2: If still unfulfilled, allocate from tax bucket (owner-priority order)
        if ($remainingQty > 0) {
            foreach ($prioritizedLocations as $locationId) {
                if ($remainingQty <= 0) {
                    break;
                }

                $stock = ProductStock::query()
                    ->where('product_id', $productId)
                    ->where('location_id', $locationId)
                    ->first();

                if (! $stock) {
                    continue;
                }

                $available = (int) $stock->quantity_tax;
                if ($available > 0) {
                    $take = min($remainingQty, $available);

                    $locInfo = $locationsCache[$locationId];
                    $sourceSettingId = $locInfo['source_setting_id'];
                    $sourceIsPkp = $locInfo['source_is_pkp'];

                    $tax = null;
                    if (! isset($taxesCache[$taxId])) {
                        $taxesCache[$taxId] = Tax::query()->find($taxId);
                    }
                    $tax = $taxesCache[$taxId];

                    $lineAllocations[] = [
                        'source_location_id' => $locationId,
                        'source_setting_id' => $sourceSettingId,
                        'allocated_qty' => $take,
                        'tax_bucket_used' => true,
                        'tax_policy_snapshot' => [
                            'source_is_pkp' => $sourceIsPkp,
                            'tax_id' => $taxId,
                            'tax_name' => $tax ? (string) $tax->name : null,
                            'tax_rate' => $tax ? (float) $tax->value : 0.0,
                        ],
                    ];

                    $remainingQty -= $take;
                }
            }
        }

        return $lineAllocations;
    }

    /**
     * Allocate a non-taxable line using only non-tax bucket.
     *
     * @param  int  $productId
     * @param  int  $neededQty
     * @param  array<int>  $locationIds
     * @param  int  $settingId
     * @param  array<int, Setting|null>  $settingsCache
     * @param  array<int, Tax|null>  $taxesCache
     * @return array<int, array<string, mixed>>
     */
    private function allocateNonTaxableLineNonTaxBucketOnly(
        int $productId,
        int $neededQty,
        $locationIds,
        int $settingId,
        array &$settingsCache,
        array &$taxesCache
    ): array {
        $lineAllocations = [];
        $remainingQty = $neededQty;

        foreach ($locationIds as $locationId) {
            if ($remainingQty <= 0) {
                break;
            }

            $stock = ProductStock::query()
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->first();

            if (! $stock) {
                continue;
            }

            $available = (int) $stock->quantity_non_tax;
            if ($available > 0) {
                $take = min($remainingQty, $available);

                $location = Location::query()->find($locationId);
                $sourceSettingId = $location ? (int) $location->setting_id : $settingId;
                
                if (! isset($settingsCache[$sourceSettingId])) {
                    $settingsCache[$sourceSettingId] = Setting::query()->find($sourceSettingId);
                }
                $sourceSetting = $settingsCache[$sourceSettingId];
                $sourceIsPkp = (bool) ($sourceSetting?->is_pkp ?? false);

                $lineAllocations[] = [
                    'source_location_id' => $locationId,
                    'source_setting_id' => $sourceSettingId,
                    'allocated_qty' => $take,
                    'tax_bucket_used' => false,
                    'tax_policy_snapshot' => [
                        'source_is_pkp' => $sourceIsPkp,
                        'tax_id' => null,
                        'tax_name' => null,
                        'tax_rate' => 0.0,
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
}
