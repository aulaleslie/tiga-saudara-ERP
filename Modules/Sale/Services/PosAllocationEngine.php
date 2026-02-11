<?php

namespace Modules\Sale\Services;

class PosAllocationEngine
{
    /**
     * @param  array<int, int>  $locationPriority
     * @param  array<int, array{available_non_tax:int,available_tax:int}>  $stocksByLocation
     * @param  array<int, array{location_id:int,allocated_non_tax:int,allocated_tax:int}>  $existingAllocations
     * @return array{
     *   allocations: array<int, array{location_id:int,allocated_non_tax:int,allocated_tax:int}>,
     *   allocated_non_tax: int,
     *   allocated_tax: int,
     *   remaining: int
     * }
     */
    public function allocate(
        int $requestedQuantity,
        array $locationPriority,
        array $stocksByLocation,
        array $existingAllocations = []
    ): array {
        $remaining = max(0, $requestedQuantity);

        $allocationByLocation = [];
        foreach ($existingAllocations as $allocation) {
            $locationId = (int) ($allocation['location_id'] ?? 0);
            if ($locationId <= 0) {
                continue;
            }

            if (! isset($allocationByLocation[$locationId])) {
                $allocationByLocation[$locationId] = [
                    'location_id' => $locationId,
                    'allocated_non_tax' => 0,
                    'allocated_tax' => 0,
                ];
            }

            $allocationByLocation[$locationId]['allocated_non_tax'] += max(0, (int) ($allocation['allocated_non_tax'] ?? 0));
            $allocationByLocation[$locationId]['allocated_tax'] += max(0, (int) ($allocation['allocated_tax'] ?? 0));
        }

        $existingTotal = array_sum(array_map(function (array $allocation) {
            return max(0, (int) ($allocation['allocated_non_tax'] ?? 0))
                + max(0, (int) ($allocation['allocated_tax'] ?? 0));
        }, $allocationByLocation));

        $remaining = max(0, $remaining - $existingTotal);

        foreach ($locationPriority as $locationId) {
            if ($remaining <= 0) {
                break;
            }

            $locationId = (int) $locationId;
            $stock = $stocksByLocation[$locationId] ?? null;
            if (! $stock) {
                continue;
            }

            if (! isset($allocationByLocation[$locationId])) {
                $allocationByLocation[$locationId] = [
                    'location_id' => $locationId,
                    'allocated_non_tax' => 0,
                    'allocated_tax' => 0,
                ];
            }

            $alreadyAllocated = max(0, (int) $allocationByLocation[$locationId]['allocated_non_tax']);
            $availableNonTax = max(0, (int) ($stock['available_non_tax'] ?? 0) - $alreadyAllocated);

            if ($availableNonTax <= 0) {
                continue;
            }

            $take = min($remaining, $availableNonTax);
            $allocationByLocation[$locationId]['allocated_non_tax'] += $take;
            $remaining -= $take;
        }

        foreach ($locationPriority as $locationId) {
            if ($remaining <= 0) {
                break;
            }

            $locationId = (int) $locationId;
            $stock = $stocksByLocation[$locationId] ?? null;
            if (! $stock) {
                continue;
            }

            if (! isset($allocationByLocation[$locationId])) {
                $allocationByLocation[$locationId] = [
                    'location_id' => $locationId,
                    'allocated_non_tax' => 0,
                    'allocated_tax' => 0,
                ];
            }

            $alreadyAllocated = max(0, (int) $allocationByLocation[$locationId]['allocated_tax']);
            $availableTax = max(0, (int) ($stock['available_tax'] ?? 0) - $alreadyAllocated);

            if ($availableTax <= 0) {
                continue;
            }

            $take = min($remaining, $availableTax);
            $allocationByLocation[$locationId]['allocated_tax'] += $take;
            $remaining -= $take;
        }

        $sorted = [];
        $seen = [];

        foreach ($locationPriority as $locationId) {
            $locationId = (int) $locationId;
            if (! isset($allocationByLocation[$locationId])) {
                continue;
            }

            $sorted[] = $allocationByLocation[$locationId];
            $seen[$locationId] = true;
        }

        foreach ($allocationByLocation as $locationId => $allocation) {
            if (isset($seen[$locationId])) {
                continue;
            }

            $sorted[] = $allocation;
        }

        $allocatedNonTax = array_sum(array_map(fn ($allocation) => (int) ($allocation['allocated_non_tax'] ?? 0), $sorted));
        $allocatedTax = array_sum(array_map(fn ($allocation) => (int) ($allocation['allocated_tax'] ?? 0), $sorted));

        return [
            'allocations' => $sorted,
            'allocated_non_tax' => $allocatedNonTax,
            'allocated_tax' => $allocatedTax,
            'remaining' => $remaining,
        ];
    }
}
