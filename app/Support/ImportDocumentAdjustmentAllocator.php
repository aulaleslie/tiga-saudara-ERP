<?php

namespace App\Support;

class ImportDocumentAdjustmentAllocator
{
    private const TOLERANCE = 0.01;

    /**
     * Allocate a single source-invoice document-level amount (e.g. Diskon or Biaya Pengiriman)
     * pro-rata across owner groups by each group's gross line total.
     *
     * The document amount is repeated on every source row but represents the whole invoice, not
     * each generated owner document. Splitting it pro-rata keeps the summed owner-document amounts
     * equal to the source value so source-invoice reconciliation holds.
     *
     * Groups with a zero (or non-positive) gross total receive zero. The final two-decimal rounding
     * remainder is assigned deterministically to the largest positive-total group.
     *
     * @param  array<int|string, float>  $groupGrossTotals  Map of group key => gross line total (before document adjustment).
     * @return array<int|string, float>  Map of group key => allocated amount.
     */
    public function allocate(array $groupGrossTotals, float $documentAmount): array
    {

        $allocations = [];
        foreach ($groupGrossTotals as $key => $total) {
            $allocations[$key] = 0.0;
        }

        if (abs($documentAmount) <= self::TOLERANCE) {
            return $allocations;
        }

        $positiveTotals = array_filter($groupGrossTotals, fn (float $total): bool => $total > self::TOLERANCE);
        $sumPositive = array_sum($positiveTotals);

        if ($sumPositive <= self::TOLERANCE) {
            // No positive gross to weight by. A single owner group can still carry a document-level
            // amount (e.g. an all-zero-line invoice with only Biaya Pengiriman), so assign the full
            // amount to it. With multiple zero-gross groups the split would be ambiguous, so leave
            // all zero rather than guess.
            if (count($groupGrossTotals) === 1) {
                $allocations[array_key_first($groupGrossTotals)] = $documentAmount;
            }

            return $allocations;
        }

        // Single positive group: assign the whole amount to it (avoids rounding drift).
        if (count($positiveTotals) === 1) {
            $allocations[array_key_first($positiveTotals)] = $documentAmount;

            return $allocations;
        }

        $largestKey = null;
        $largestTotal = -INF;
        foreach ($positiveTotals as $key => $total) {
            if ($total > $largestTotal) {
                $largestTotal = $total;
                $largestKey = $key;
            }

            $allocations[$key] = $documentAmount * ($total / $sumPositive);
        }

        $allocated = array_sum($allocations);
        $allocations[$largestKey] = $allocations[$largestKey] + ($documentAmount - $allocated);

        return $allocations;
    }
}
