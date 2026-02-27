<?php

namespace Modules\Pos\Services;

use App\Support\SalesLocationResolver;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;

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

                $available = $this->getAvailableStock($stock, $taxId);
                
                if ($available > 0) {
                    $take = min($remainingQty, $available);
                    
                    // Load location to get owning business ID (for borrowed location tax/reporting)
                    $location = Location::query()->find($locationId);
                    
                    $lineAllocations[] = [
                        'source_location_id' => $locationId,
                        'source_setting_id' => $location ? (int) $location->setting_id : $settingId,
                        'allocated_qty' => $take,
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

    /**
     * Get available stock quantity based on tax split.
     */
    private function getAvailableStock(ProductStock $stock, ?int $taxId): int
    {
        if ($taxId !== null) {
            return (int) $stock->quantity_tax;
        }
        
        return (int) $stock->quantity_non_tax;
    }
}
