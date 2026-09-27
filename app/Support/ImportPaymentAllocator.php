<?php

namespace App\Support;

class ImportPaymentAllocator
{
    private const TOLERANCE = 0.01;

    /**
     * Allocate a source invoice's paid and outstanding amounts pro-rata across owner groups
     * by each group's adjusted document total.
     *
     * Zero-total groups receive zero paid/due. The final two-decimal rounding remainder is
     * assigned deterministically to the largest positive-total group so the persisted owner
     * document totals still sum back to the source paid and outstanding amounts.
     *
     * @param  array<int|string, float>  $groupTotals  Map of group key => adjusted document total.
     * @return array<int|string, array{paid:float,due:float}>  Map of group key => allocated paid/due.
     */
    public function allocate(array $groupTotals, float $sourcePaid, float $sourceOutstanding): array
    {
        $sourcePaid = round($sourcePaid, 2);
        $sourceOutstanding = round($sourceOutstanding, 2);

        $positiveTotals = array_filter($groupTotals, fn (float $total): bool => $total > self::TOLERANCE);
        $sumPositive = array_sum($positiveTotals);

        $allocations = [];
        foreach ($groupTotals as $key => $total) {
            $allocations[$key] = ['paid' => 0.0, 'due' => 0.0];
        }

        if ($sumPositive <= self::TOLERANCE) {
            return $allocations;
        }

        $largestKey = null;
        $largestTotal = -INF;
        foreach ($positiveTotals as $key => $total) {
            if ($total > $largestTotal) {
                $largestTotal = $total;
                $largestKey = $key;
            }

            $ratio = $total / $sumPositive;
            $allocations[$key] = [
                'paid' => round($sourcePaid * $ratio, 2),
                'due' => round($sourceOutstanding * $ratio, 2),
            ];
        }

        // Assign rounding remainder deterministically to the largest positive-total group.
        $allocatedPaid = array_sum(array_column($allocations, 'paid'));
        $allocatedDue = array_sum(array_column($allocations, 'due'));

        $allocations[$largestKey]['paid'] = round($allocations[$largestKey]['paid'] + ($sourcePaid - $allocatedPaid), 2);
        $allocations[$largestKey]['due'] = round($allocations[$largestKey]['due'] + ($sourceOutstanding - $allocatedDue), 2);

        return $allocations;
    }
}
