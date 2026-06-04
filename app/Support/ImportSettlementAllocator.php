<?php

namespace App\Support;

class ImportSettlementAllocator
{
    // Sub-cent epsilon: money is two-decimal, so the smallest meaningful positive value is 0.01.
    // Comparing against half a cent distinguishes a true zero from a legitimate one-cent amount
    // (a 0.01 weight or amount must be treated as positive, not skipped).
    private const EPSILON = 0.005;

    /**
     * Split a source invoice's settlement (cash Pembayaran, the non-cash Jumlah Pemotongan credit,
     * and the outstanding due) across owner groups so that every owner document satisfies
     * cash + deduction + due == group total with all three components non-negative, while the
     * invoice-level sums still equal the source paid, deduction, and outstanding amounts.
     *
     * Allocating cash and deduction independently and deriving due as the residual can over-settle
     * a tiny group (its rounded cash + deduction exceeding its total) and persist a negative due.
     * This allocator instead:
     *   1. allocates the outstanding due pro-rata by group total (due_g <= group_total because
     *      outstanding <= sum(group_total));
     *   2. takes each group's settled amount as group_total - due_g (>= 0);
     *   3. allocates cash pro-rata by each group's settled amount (cash_g <= settled_g because
     *      paid <= sum(settled) = paid + deduction);
     *   4. derives deduction as settled_g - cash_g (>= 0).
     * Remainders are assigned to the largest-weight group, which always has the most headroom.
     *
     * @param  array<int|string, float>  $groupTotals  Map of group key => adjusted document total.
     * @return array<int|string, array{cash:float,deduction:float,due:float}>
     */
    public function allocate(array $groupTotals, float $paid, float $deduction): array
    {
        $paid = round(max($paid, 0.0), 2);
        $deduction = round(max($deduction, 0.0), 2);

        $sumTotals = round(array_sum($groupTotals), 2);

        // Cap settlement to sumTotals so we don't over-settle groups and violate
        // the paid + due == total invariant when a CSV total exceeds calculated totals.
        $totalSettlement = round($paid + $deduction, 2);
        if ($totalSettlement > $sumTotals) {
            $excess = round($totalSettlement - $sumTotals, 2);
            if ($paid >= $excess) {
                $paid = round($paid - $excess, 2);
            } else {
                $deduction = round($deduction - ($excess - $paid), 2);
                $paid = 0.0;
            }
        }

        $outstanding = round($sumTotals - $paid - $deduction, 2);
        if ($outstanding < 0) {
            $outstanding = 0.0;
        }

        $result = [];
        foreach ($groupTotals as $key => $total) {
            $result[$key] = ['cash' => 0.0, 'deduction' => 0.0, 'due' => 0.0];
        }

        // Step 1: allocate due pro-rata by group total.
        $dueByGroup = $this->proRata($groupTotals, $outstanding);

        // Step 2: settled per group = group total - due (never negative).
        $settledByGroup = [];
        foreach ($groupTotals as $key => $total) {
            $settled = round($total - ($dueByGroup[$key] ?? 0.0), 2);
            $settledByGroup[$key] = $settled > 0 ? $settled : 0.0;
        }

        // Step 3: allocate cash pro-rata by settled amount.
        $cashByGroup = $this->proRata($settledByGroup, $paid);

        // Step 4: deduction is the settled residual.
        foreach ($groupTotals as $key => $total) {
            $cash = $cashByGroup[$key] ?? 0.0;
            $settled = $settledByGroup[$key];
            $deductionShare = round($settled - $cash, 2);
            if ($deductionShare < 0) {
                $deductionShare = 0.0;
            }

            $result[$key] = [
                'cash' => $cash,
                'deduction' => $deductionShare,
                'due' => $dueByGroup[$key] ?? 0.0,
            ];
        }

        return $result;
    }

    /**
     * Allocate a single amount across groups pro-rata by weight, assigning the two-decimal rounding
     * remainder to the largest positive-weight group. Non-positive-weight groups receive zero.
     *
     * @param  array<int|string, float>  $weights
     * @return array<int|string, float>
     */
    private function proRata(array $weights, float $amount): array
    {
        $amount = round($amount, 2);

        $allocations = [];
        foreach ($weights as $key => $weight) {
            $allocations[$key] = 0.0;
        }

        $positive = array_filter($weights, fn (float $w): bool => $w > self::EPSILON);
        $sumPositive = array_sum($positive);

        if (abs($amount) <= self::EPSILON || $sumPositive <= self::EPSILON) {
            return $allocations;
        }

        $largestKey = null;
        $largestWeight = -INF;
        foreach ($positive as $key => $weight) {
            if ($weight > $largestWeight) {
                $largestWeight = $weight;
                $largestKey = $key;
            }

            $allocations[$key] = round($amount * ($weight / $sumPositive), 2);
        }

        $allocated = array_sum($allocations);
        $allocations[$largestKey] = round($allocations[$largestKey] + ($amount - $allocated), 2);

        return $allocations;
    }
}
