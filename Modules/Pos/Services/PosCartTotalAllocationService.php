<?php

namespace Modules\Pos\Services;

use DomainException;

class PosCartTotalAllocationService
{
    /**
     * Allocate a target total proportionally across cart lines using deterministic largest-remainder rounding.
     *
     * @param  array<int, array{line_id:int, qty:int, unit_price:float, line_total?:int}>  $lines
     * @param  int  $targetTotalMinorUnits  Target total in minor units (Rp)
     * @return array{
     *     allocated: array<int, array{line_id:int, allocated_line_total:int, effective_unit_price:float}>,
     *     total_allocated: int,
     *     fingerprint: string
     * }
     */
    public function allocate(array $lines, int $targetTotalMinorUnits): array
    {
        if ($targetTotalMinorUnits < 0) {
            throw new DomainException('Target total must be non-negative.');
        }

        if (empty($lines)) {
            if ($targetTotalMinorUnits !== 0) {
                throw new DomainException('Cannot allocate to an empty cart.');
            }

            return [
                'allocated' => [],
                'total_allocated' => 0,
                'fingerprint' => $this->generateFingerprint([]),
            ];
        }

        // Calculate source totals for each line
        $lineGrosses = [];
        $sourceTotalMinorUnits = 0;

        foreach ($lines as $line) {
            $lineTotal = isset($line['line_total']) ? (int) $line['line_total'] : (int) round($line['unit_price'] * $line['qty'] * 100);
            $lineGrosses[$line['line_id']] = $lineTotal;
            $sourceTotalMinorUnits += $lineTotal;
        }

        // If source total is zero, cannot allocate unless target is also zero
        if ($sourceTotalMinorUnits === 0) {
            if ($targetTotalMinorUnits !== 0) {
                throw new DomainException('Cannot allocate non-zero amount to a zero-value cart.');
            }

            return [
                'allocated' => [],
                'total_allocated' => 0,
                'fingerprint' => $this->generateFingerprint([]),
            ];
        }

        // Proportional allocation with largest-remainder rounding
        $allocated = $this->proportionallyAllocate($lines, $lineGrosses, $sourceTotalMinorUnits, $targetTotalMinorUnits);

        // Generate fingerprint from line state
        $fingerprint = $this->generateFingerprint($lines);

        return [
            'allocated' => $allocated,
            'total_allocated' => array_sum(array_column($allocated, 'allocated_line_total')),
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * @param  array<int, array{line_id:int, qty:int, unit_price:float}>  $lines
     * @param  array<int, int>  $lineGrosses  Map of line_id => gross total in minor units
     * @param  int  $sourceTotalMinorUnits
     * @param  int  $targetTotalMinorUnits
     * @return array<int, array{line_id:int, allocated_line_total:int, effective_unit_price:float}>
     */
    private function proportionallyAllocate(
        array $lines,
        array $lineGrosses,
        int $sourceTotalMinorUnits,
        int $targetTotalMinorUnits
    ): array {
        $allocated = [];
        $totalAllocated = 0;
        $proportions = [];

        // Calculate fractional shares for each line
        foreach ($lines as $line) {
            $lineId = $line['line_id'];
            $proportion = ($lineGrosses[$lineId] / $sourceTotalMinorUnits) * $targetTotalMinorUnits;
            $proportions[$lineId] = [
                'proportion' => $proportion,
                'integer_part' => (int) floor($proportion),
                'fractional_part' => $proportion - floor($proportion),
                'qty' => $line['qty'],
            ];
            $totalAllocated += $proportions[$lineId]['integer_part'];
        }

        // Distribute remaining minor units using largest-remainder ordering
        // Sort by fractional part descending, then by line_id ascending for ties
        $remainder = $targetTotalMinorUnits - $totalAllocated;
        $lineIdsForRemainder = array_keys($proportions);
        usort($lineIdsForRemainder, function ($a, $b) use ($proportions) {
            $fractionalDiff = $proportions[$b]['fractional_part'] <=> $proportions[$a]['fractional_part'];
            if ($fractionalDiff !== 0) {
                return $fractionalDiff;
            }
            return $a <=> $b;
        });

        foreach ($lineIdsForRemainder as $lineId) {
            if ($remainder <= 0) {
                break;
            }

            $proportions[$lineId]['integer_part']++;
            $remainder--;
        }

        // Build result with effective unit prices
        foreach ($lines as $line) {
            $lineId = $line['line_id'];
            $qty = $line['qty'];
            $allocatedTotal = $proportions[$lineId]['integer_part'];

            $allocated[] = [
                'line_id' => $lineId,
                'allocated_line_total' => $allocatedTotal,
                'effective_unit_price' => $qty > 0 ? round($allocatedTotal / 100.0 / $qty, 2) : 0.0,
            ];
        }

        return $allocated;
    }

    /**
     * Generate a canonical fingerprint from cart lines representing mutable pricing inputs.
     * This includes quantities, conversion IDs, customer assignments, and prices.
     *
     * @param  array<int, array{line_id:int, qty:int, unit_price:float, conversion_id?:int, bundle_id?:int, ...}>  $lines
     * @return string  SHA256 hex hash
     */
    public function generateFingerprint(array $lines): string
    {
        $components = [];

        foreach ($lines as $line) {
            $components[] = implode(':', [
                (int) $line['line_id'],
                (int) $line['qty'],
                (int) round($line['unit_price'] * 100),
                $line['conversion_id'] ?? 0,
                $line['bundle_id'] ?? 0,
            ]);
        }

        return hash('sha256', implode('|', $components));
    }

    /**
     * Verify that a cart fingerprint matches the current cart state.
     *
     * @param  array<int, array{...}>  $currentLines
     * @param  string  $previousFingerprint
     * @return bool
     */
    public function fingerprintMatches(array $currentLines, string $previousFingerprint): bool
    {
        return $this->generateFingerprint($currentLines) === $previousFingerprint;
    }
}
