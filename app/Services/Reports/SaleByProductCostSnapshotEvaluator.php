<?php

namespace App\Services\Reports;

use Modules\Sale\Services\SalesCostSnapshotService;

class SaleByProductCostSnapshotEvaluator
{
    /**
     * Evaluate a persisted sale detail or bundle item's HPP snapshot.
     *
     * @param  array{
     *     quantity: mixed,
     *     cost_unit_snapshot: mixed,
     *     cost_total_snapshot: mixed,
     *     cost_snapshot_source: ?string,
     *     cost_snapshot_at: mixed,
     *     sale_status?: ?string
     * }  $row
     * @return array{
     *     complete: bool,
     *     known_cost: ?float,
     *     reason: ?string
     * }
     */
    public function evaluate(array $row): array
    {
        $source = isset($row['cost_snapshot_source']) ? trim((string) $row['cost_snapshot_source']) : '';
        $snapshotAt = $row['cost_snapshot_at'] ?? null;
        $quantity = is_numeric($row['quantity'] ?? null) ? (float) $row['quantity'] : 0.0;
        $unitSnapshot = $row['cost_unit_snapshot'] ?? null;
        $totalSnapshot = $row['cost_total_snapshot'] ?? null;
        $saleStatus = isset($row['sale_status']) ? strtoupper(trim((string) $row['sale_status'])) : '';

        // Placeholder parent not physically fulfilled by this group contributes zero phantom cost.
        if ($source === SalesCostSnapshotService::SOURCE_NOT_FULFILLED_BY_GROUP) {
            return [
                'complete' => true,
                'known_cost' => 0.0,
                'reason' => null,
            ];
        }

        // Explicitly missing average price or zero-fallback backfill (no purchase-cost evidence)
        // is always incomplete, even when numeric value is 0.
        if (
            $source === SalesCostSnapshotService::SOURCE_MISSING_AVERAGE_PRICE
            || $source === 'BACKFILL_ZERO_FALLBACK'
            || str_starts_with($source, 'MISSING_')
            || str_ends_with($source, '_ZERO_FALLBACK')
        ) {
            return [
                'complete' => false,
                'known_cost' => null,
                'reason' => $source,
            ];
        }

        // Non-stock-managed items legitimately have 0 cost when snapshotted.
        if ($source === SalesCostSnapshotService::SOURCE_NON_STOCK_MANAGED) {
            if ($snapshotAt === null || $snapshotAt === '') {
                return [
                    'complete' => false,
                    'known_cost' => null,
                    'reason' => 'MISSING_SNAPSHOT_TIMESTAMP',
                ];
            }

            return [
                'complete' => true,
                'known_cost' => 0.0,
                'reason' => null,
            ];
        }

        // Missing source or missing snapshot timestamp means snapshot provenance is incomplete.
        if ($source === '' || $snapshotAt === null || $snapshotAt === '') {
            return [
                'complete' => false,
                'known_cost' => null,
                'reason' => 'MISSING_SNAPSHOT',
            ];
        }

        // Primary path: unit cost snapshot * included current quantity.
        if ($unitSnapshot !== null && $unitSnapshot !== '' && is_numeric($unitSnapshot)) {
            $unitCost = (float) $unitSnapshot;
            if (!is_finite($unitCost) || $unitCost < 0) {
                return [
                    'complete' => false,
                    'known_cost' => null,
                    'reason' => 'INVALID_UNIT_COST',
                ];
            }

            $cost = $this->multiplyDecimal($unitSnapshot, $quantity);

            return [
                'complete' => true,
                'known_cost' => $cost,
                'reason' => null,
            ];
        }

        // Fallback path: total-only snapshot is accepted only when quantity is not settlement-modified
        // (so the stored total reliably corresponds to the included quantity).
        if ($totalSnapshot !== null && $totalSnapshot !== '' && is_numeric($totalSnapshot)) {
            $totalCost = (float) $totalSnapshot;
            if (!is_finite($totalCost) || $totalCost < 0) {
                return [
                    'complete' => false,
                    'known_cost' => null,
                    'reason' => 'INVALID_TOTAL_COST',
                ];
            }

            if ($saleStatus === 'RETURNED PARTIALLY' && $quantity > 0) {
                return [
                    'complete' => false,
                    'known_cost' => null,
                    'reason' => 'UNVERIFIABLE_TOTAL_AFTER_SETTLEMENT',
                ];
            }

            return [
                'complete' => true,
                'known_cost' => $totalCost,
                'reason' => null,
            ];
        }

        return [
            'complete' => false,
            'known_cost' => null,
            'reason' => 'MISSING_COST_VALUE',
        ];
    }

    private function multiplyDecimal(mixed $unitSnapshot, float $quantity): float
    {
        if (function_exists('bcmul')) {
            $unitStr = number_format((float) $unitSnapshot, 6, '.', '');
            $qtyStr = number_format($quantity, 6, '.', '');
            return (float) bcmul($unitStr, $qtyStr, 6);
        }

        return (float) $unitSnapshot * $quantity;
    }
}
