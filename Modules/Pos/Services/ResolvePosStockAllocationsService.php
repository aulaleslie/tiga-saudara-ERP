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
     * @param int $settingId Current business setting ID
     * @param array<int, array{product_id: int, qty: int, tax_id: int|null}> $cartLines
     * @return array{
     *     allocations: array<int, array<int, array{source_location_id: int, source_setting_id: int, allocated_qty: int}>>,
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

            $lineAllocations = [];
            $remainingQty = $neededQty;

            // Iterate through allowed locations in priority order
            foreach ($locationIds as $locationId) {
                if ($remainingQty <= 0) {
                    break;
                }

                $stock = ProductStock::query()
                    ->where('product_id', $productId)
                    ->where('location_id', $locationId)
                    ->first();

                if (!$stock) {
                    continue;
                }

                // Load location and source setting to determine effective tax policy BEFORE getting available stock
                $location = Location::query()->find($locationId);
                $sourceSettingId = $location ? (int) $location->setting_id : $settingId;
                
                if (!isset($settingsCache[$sourceSettingId])) {
                    $settingsCache[$sourceSettingId] = Setting::query()->find($sourceSettingId);
                }
                $sourceSetting = $settingsCache[$sourceSettingId];
                $sourceIsPkp = (bool) ($sourceSetting?->is_pkp ?? false);

                // Effective tax policy: only use tax bucket if BOTH terminal wants tax AND source allows it (is PKP)
                $effectiveTaxRequested = ($taxId !== null && $sourceIsPkp);
                $available = $effectiveTaxRequested ? (int) $stock->quantity_tax : (int) $stock->quantity_non_tax;
                
                if ($available > 0) {
                    $take = min($remainingQty, $available);
                    
                    $tax = null;
                    if ($taxId !== null) {
                        if (!isset($taxesCache[$taxId])) {
                            $taxesCache[$taxId] = Tax::query()->find($taxId);
                        }
                        $tax = $taxesCache[$taxId];
                    }
                    
                    $lineAllocations[] = [
                        'source_location_id' => $locationId,
                        'source_setting_id' => $sourceSettingId,
                        'allocated_qty' => $take,
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

            $allocations[$index] = $lineAllocations;
            if ($remainingQty > 0) {
                $unfulfilledLines[] = $index;
            }
        }

        return [
            'allocations' => $allocations,
            'unfulfilled_lines' => $unfulfilledLines,
        ];
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
