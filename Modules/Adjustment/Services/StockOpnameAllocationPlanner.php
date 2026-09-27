<?php

declare(strict_types=1);

namespace Modules\Adjustment\Services;

use InvalidArgumentException;

/**
 * Pure, deterministic condition-specific allocation planner for multi-location
 * Stock Opname differences.
 *
 * Implements authoritative ordering:
 * - Shortage (deductions): (is_pkp ASC, stock DESC, location_id ASC)
 *   Deducts waterfall through non-PKP locations first (highest stock to lowest),
 *   then PKP locations (highest stock to lowest).
 * - Surplus (additions): (is_pkp ASC, stock ASC, location_id ASC)
 *   Assigns the ENTIRE surplus to the first destination in order (non-PKP with
 *   lowest stock, tie-broken by lowest location_id).
 */
class StockOpnameAllocationPlanner
{
    /**
     * Plan allocation for a condition difference across a location pool.
     *
     * @param array<int, array{location_id:int,setting_id?:int,is_pkp:bool,stock:int,location_name?:string}> $locations
     * @param int $difference Physical count minus pool stock sum (positive = surplus, negative = shortage, 0 = matched)
     * @param string $condition 'good' or 'bad'
     * @return array{
     *     condition: string,
     *     difference: int,
     *     type: string,
     *     steps: array<int, array{
     *         location_id: int,
     *         setting_id: int,
     *         is_pkp: bool,
     *         location_name: string,
     *         before_stock: int,
     *         delta: int,
     *         after_stock: int
     *     }>,
     *     unallocated: int
     * }
     */
    public function plan(array $locations, int $difference, string $condition = 'good'): array
    {
        if (empty($locations)) {
            throw new InvalidArgumentException('Daftar lokasi tidak boleh kosong untuk alokasi.');
        }

        $normalizedCondition = in_array(strtolower(trim($condition)), ['good', 'bad'], true)
            ? strtolower(trim($condition))
            : 'good';

        if ($difference === 0) {
            return $this->planNoChange($locations, $normalizedCondition);
        }

        if ($difference > 0) {
            return $this->planSurplus($locations, $difference, $normalizedCondition);
        }

        return $this->planShortage($locations, $difference, $normalizedCondition);
    }

    /**
     * No difference: all locations remain unchanged.
     */
    private function planNoChange(array $locations, string $condition): array
    {
        $steps = [];
        foreach ($locations as $loc) {
            $stock = max(0, (int) ($loc['stock'] ?? 0));
            $steps[] = [
                'location_id' => (int) $loc['location_id'],
                'setting_id' => (int) ($loc['setting_id'] ?? 0),
                'is_pkp' => (bool) ($loc['is_pkp'] ?? false),
                'location_name' => (string) ($loc['location_name'] ?? ''),
                'before_stock' => $stock,
                'delta' => 0,
                'after_stock' => $stock,
            ];
        }

        return [
            'condition' => $condition,
            'difference' => 0,
            'type' => 'none',
            'steps' => $steps,
            'unallocated' => 0,
        ];
    }

    /**
     * Surplus: (is_pkp ASC, stock ASC, location_id ASC)
     * Entire surplus goes to the first ordered location.
     */
    private function planSurplus(array $locations, int $surplus, string $condition): array
    {
        // Sort copy of locations by surplus order
        $sorted = $locations;
        usort($sorted, function ($a, $b) {
            $pkpA = (int) (bool) ($a['is_pkp'] ?? false);
            $pkpB = (int) (bool) ($b['is_pkp'] ?? false);
            if ($pkpA !== $pkpB) {
                return $pkpA <=> $pkpB; // Non-PKP (0) before PKP (1)
            }

            $stockA = max(0, (int) ($a['stock'] ?? 0));
            $stockB = max(0, (int) ($b['stock'] ?? 0));
            if ($stockA !== $stockB) {
                return $stockA <=> $stockB; // Ascending stock
            }

            return ((int) $a['location_id']) <=> ((int) $b['location_id']); // Ascending location_id
        });

        $primaryTargetId = (int) $sorted[0]['location_id'];

        $steps = [];
        foreach ($locations as $loc) {
            $locId = (int) $loc['location_id'];
            $stock = max(0, (int) ($loc['stock'] ?? 0));
            $delta = ($locId === $primaryTargetId) ? $surplus : 0;

            $steps[] = [
                'location_id' => $locId,
                'setting_id' => (int) ($loc['setting_id'] ?? 0),
                'is_pkp' => (bool) ($loc['is_pkp'] ?? false),
                'location_name' => (string) ($loc['location_name'] ?? ''),
                'before_stock' => $stock,
                'delta' => $delta,
                'after_stock' => $stock + $delta,
            ];
        }

        return [
            'condition' => $condition,
            'difference' => $surplus,
            'type' => 'surplus',
            'steps' => $steps,
            'unallocated' => 0,
        ];
    }

    /**
     * Shortage: (is_pkp ASC, stock DESC, location_id ASC)
     * Waterfall deduction through eligible locations with stock.
     */
    private function planShortage(array $locations, int $difference, string $condition): array
    {
        $shortageToDeduct = abs($difference);

        // Sort copy of locations by shortage order
        $sorted = $locations;
        usort($sorted, function ($a, $b) {
            $pkpA = (int) (bool) ($a['is_pkp'] ?? false);
            $pkpB = (int) (bool) ($b['is_pkp'] ?? false);
            if ($pkpA !== $pkpB) {
                return $pkpA <=> $pkpB; // Non-PKP (0) before PKP (1)
            }

            $stockA = max(0, (int) ($a['stock'] ?? 0));
            $stockB = max(0, (int) ($b['stock'] ?? 0));
            if ($stockA !== $stockB) {
                return $stockB <=> $stockA; // Descending stock
            }

            return ((int) $a['location_id']) <=> ((int) $b['location_id']); // Ascending location_id
        });

        // Compute deductions per location ID
        $deductionsByLocationId = [];
        $remaining = $shortageToDeduct;

        foreach ($sorted as $loc) {
            $locId = (int) $loc['location_id'];
            $stock = max(0, (int) ($loc['stock'] ?? 0));

            if ($remaining <= 0 || $stock <= 0) {
                $deductionsByLocationId[$locId] = 0;
                continue;
            }

            $deduct = min($remaining, $stock);
            $deductionsByLocationId[$locId] = $deduct;
            $remaining -= $deduct;
        }

        // Build result steps preserving original location order
        $steps = [];
        foreach ($locations as $loc) {
            $locId = (int) $loc['location_id'];
            $stock = max(0, (int) ($loc['stock'] ?? 0));
            $deduct = $deductionsByLocationId[$locId] ?? 0;

            $steps[] = [
                'location_id' => $locId,
                'setting_id' => (int) ($loc['setting_id'] ?? 0),
                'is_pkp' => (bool) ($loc['is_pkp'] ?? false),
                'location_name' => (string) ($loc['location_name'] ?? ''),
                'before_stock' => $stock,
                'delta' => -$deduct,
                'after_stock' => max(0, $stock - $deduct),
            ];
        }

        return [
            'condition' => $condition,
            'difference' => $difference,
            'type' => 'shortage',
            'steps' => $steps,
            'unallocated' => $remaining,
        ];
    }
}
