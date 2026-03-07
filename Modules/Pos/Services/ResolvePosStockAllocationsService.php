<?php

namespace Modules\Pos\Services;

use App\Support\SalesLocationResolver;
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
     * @param array<int, array{product_id: int, qty: int, tax_id: int|null}> $cartLines
     * @return array{
     *     allocations: array<int, array<int, array{source_location_id: int, source_setting_id: int, allocated_qty: int, tax_bucket_used: bool}>>,
     *     unfulfilled_lines: array<int>
     * }
     */
    public function resolve(int $settingId, array $cartLines): array
    {
        $locationIds = SalesLocationResolver::resolveLocationIds($settingId);
        
        $allocations = [];
        $unfulfilledLines = [];

        // Cache for settings to avoid N+1 lookups for source businesses
        $settingsCache = [];
        // Cache for taxes to avoid N+1 lookups
        $taxesCache = [];

        foreach ($cartLines as $index => $line) {
            $productId = (int) $line['product_id'];
            $neededQty = (int) $line['qty'];
            $taxId = isset($line['tax_id']) ? (int) $line['tax_id'] : null;

            // Determine allocation strategy based on tax requirement
            $isTaxable = $taxId !== null && (int) $taxId > 0;

            if ($isTaxable) {
                // Taxable line: use bucket-first strategy
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
                // Non-taxable line: only use non-tax bucket
                $lineAllocations = $this->allocateNonTaxableLineNonTaxBucketOnly(
                    $productId,
                    $neededQty,
                    $locationIds,
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
            if ($allocatedQty < $neededQty) {
                $unfulfilledLines[] = $index;
            }
        }

        return [
            'allocations' => $allocations,
            'unfulfilled_lines' => $unfulfilledLines,
        ];
    }

    /**
     * Allocate a taxable line using bucket-first strategy:
     * Pass 1: non-tax bucket across all locations
     * Pass 2: tax bucket across all locations (if needed)
     *
     * @param  int  $productId
     * @param  int  $neededQty
     * @param  int  $taxId
     * @param  array<int>  $locationIds
     * @param  int  $settingId
     * @param  array<int, Setting|null>  $settingsCache
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
        $lineAllocations = [];
        $remainingQty = $neededQty;

        // Pass 1: Allocate from non-tax bucket across all locations
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

        // Pass 2: If still unfulfilled, allocate from tax bucket across all locations
        if ($remainingQty > 0) {
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

                $available = (int) $stock->quantity_tax;
                if ($available > 0) {
                    $take = min($remainingQty, $available);

                    $location = Location::query()->find($locationId);
                    $sourceSettingId = $location ? (int) $location->setting_id : $settingId;
                    
                    if (! isset($settingsCache[$sourceSettingId])) {
                        $settingsCache[$sourceSettingId] = Setting::query()->find($sourceSettingId);
                    }
                    $sourceSetting = $settingsCache[$sourceSettingId];
                    $sourceIsPkp = (bool) ($sourceSetting?->is_pkp ?? false);

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
