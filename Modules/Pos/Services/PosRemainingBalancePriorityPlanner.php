<?php

namespace Modules\Pos\Services;

use Illuminate\Validation\ValidationException;

class PosRemainingBalancePriorityPlanner
{
    /**
     * Plan allocation of a requested POS payment amount across child sales.
     *
     * Mirrors the checkout-time ownership-priority allocation algorithm
     * (PosCheckoutOwnershipPriorityAllocationService): the preferred ownership
     * group (non-terminal-owned for cash, terminal-owned for non-cash) is filled
     * first in deterministic order, then any overflow is split proportionally
     * across all remaining-balance sales at once using integer minor units and
     * largest-remainder rounding, rather than filling fallback sales one at a time.
     *
     * @param array{
     *     amount: float|int|string,
     *     is_cash: bool,
     *     terminal_setting_id: int,
     *     sales: array<int, array{
     *         sale_id: int,
     *         setting_id: int,
     *         split_key?: ?string,
     *         live_due: float|int|string,
     *         total_amount?: float|int|string
     *     }>
     * } $context
     * @return array{
     *     allocations: array<int, array{
     *         sale_id: int,
     *         setting_id: int,
     *         split_key: ?string,
     *         live_due: float,
     *         allocated_amount: float
     *     }>,
     *     total_allocated: float,
     *     total_requested: float,
     *     remaining_due_after: float
     * }
     * @throws ValidationException
     */
    public function plan(array $context): array
    {
        $requestedAmount = round((float) ($context['amount'] ?? 0), 2);
        $isCash = (bool) ($context['is_cash'] ?? false);
        $terminalSettingId = (int) ($context['terminal_setting_id'] ?? 0);
        $sales = $context['sales'] ?? [];

        if ($requestedAmount < 0) {
            throw ValidationException::withMessages([
                'allocations' => 'Jumlah alokasi pembayaran tidak boleh negatif.',
            ]);
        }

        if (empty($sales)) {
            throw ValidationException::withMessages([
                'allocations' => 'Tidak ada penjualan turunan yang dapat dialokasikan.',
            ]);
        }

        // Normalize sales and assign a deterministic key (split_key when present, else sale_id).
        $normalizedSales = [];
        $order = [];
        foreach ($sales as $sale) {
            $settingId = (int) ($sale['setting_id'] ?? 0);
            $saleId = (int) $sale['sale_id'];
            $key = !empty($sale['split_key']) ? (string) $sale['split_key'] : 'sale_' . $saleId;

            $normalizedSales[$key] = [
                'sale_id' => $saleId,
                'setting_id' => $settingId,
                'split_key' => $sale['split_key'] ?? null,
                'live_due' => round((float) ($sale['live_due'] ?? 0), 2),
                'total_amount' => round((float) ($sale['total_amount'] ?? 0), 2),
            ];
            $order[] = $key;
        }

        // Deterministic ordering (by split_key when available, then sale_id) for
        // preferred-group filling order.
        usort($order, function ($a, $b) use ($normalizedSales) {
            $saleA = $normalizedSales[$a];
            $saleB = $normalizedSales[$b];
            if (!empty($saleA['split_key']) && !empty($saleB['split_key']) && $saleA['split_key'] !== $saleB['split_key']) {
                return strcmp($saleA['split_key'], $saleB['split_key']);
            }
            return $saleA['sale_id'] <=> $saleB['sale_id'];
        });

        $totalAggregateDue = 0.0;
        foreach ($normalizedSales as $s) {
            if ($s['live_due'] > 0) {
                $totalAggregateDue += $s['live_due'];
            }
        }
        $totalAggregateDue = round($totalAggregateDue, 2);

        if ($requestedAmount > $totalAggregateDue) {
            throw ValidationException::withMessages([
                'allocations' => "Jumlah alokasi ({$requestedAmount}) melebihi total sisa tagihan ({$totalAggregateDue}).",
            ]);
        }

        // Work in integer minor units (cents) to match the checkout algorithm's
        // exactness and largest-remainder rounding behavior.
        $balancesMinor = [];
        foreach ($order as $key) {
            $balancesMinor[$key] = (int) round(max(0.0, $normalizedSales[$key]['live_due']) * 100);
        }
        $requestedAmountMinor = (int) round($requestedAmount * 100);

        // Preferred group: non-terminal-owned for cash, terminal-owned for non-cash.
        $preferredKeys = array_values(array_filter($order, function ($key) use ($normalizedSales, $terminalSettingId, $isCash) {
            $isTerminalOwned = $normalizedSales[$key]['setting_id'] === $terminalSettingId;
            return $isCash ? !$isTerminalOwned : $isTerminalOwned;
        }));

        $allocatedMinor = array_fill_keys($order, 0);
        $remainingMinor = $requestedAmountMinor;

        // Fill the preferred group first, sequentially in deterministic order.
        foreach ($preferredKeys as $key) {
            if ($remainingMinor <= 0) {
                break;
            }
            $balance = $balancesMinor[$key];
            if ($balance <= 0) {
                continue;
            }
            $take = min($remainingMinor, $balance);
            $allocatedMinor[$key] += $take;
            $balancesMinor[$key] -= $take;
            $remainingMinor -= $take;
        }

        // Overflow: split proportionally across all remaining-balance sales at once,
        // using largest-remainder rounding for deterministic minor-unit distribution.
        if ($remainingMinor > 0) {
            $groupsWithBalance = array_filter($balancesMinor, fn($b) => $b > 0);

            if (!empty($groupsWithBalance)) {
                $distributed = $this->allocateByLargestRemainder($remainingMinor, $groupsWithBalance);
                foreach ($distributed as $key => $amount) {
                    if ($amount <= 0) {
                        continue;
                    }
                    $allocatedMinor[$key] += $amount;
                    $balancesMinor[$key] -= $amount;
                    $remainingMinor -= $amount;
                }
            }
        }

        $allocations = [];
        $totalAllocated = 0.0;
        foreach ($order as $key) {
            $sale = $normalizedSales[$key];
            $allocatedAmount = round($allocatedMinor[$key] / 100, 2);
            $totalAllocated = round($totalAllocated + $allocatedAmount, 2);

            $allocations[] = [
                'sale_id' => $sale['sale_id'],
                'setting_id' => $sale['setting_id'],
                'split_key' => $sale['split_key'],
                'live_due' => $sale['live_due'],
                'allocated_amount' => $allocatedAmount,
            ];
        }

        // Reconcile exact decimal matching
        if (abs($totalAllocated - $requestedAmount) > 0.0001) {
            throw ValidationException::withMessages([
                'allocations' => "Total alokasi ({$totalAllocated}) tidak cocok dengan jumlah yang diminta ({$requestedAmount}).",
            ]);
        }

        $remainingDueAfter = round(max(0.0, $totalAggregateDue - $totalAllocated), 2);

        return [
            'allocations' => $allocations,
            'total_allocated' => $totalAllocated,
            'total_requested' => $requestedAmount,
            'remaining_due_after' => $remainingDueAfter,
        ];
    }

    /**
     * Allocate amount using largest-remainder method (deterministic), mirroring
     * PosCheckoutOwnershipPriorityAllocationService::allocateByLargestRemainder.
     *
     * @param  int  $totalAmount
     * @param  array<string, int>  $balances  (key => remaining_balance, minor units)
     * @return array<string, int>  (key => allocated_amount, minor units)
     */
    private function allocateByLargestRemainder(int $totalAmount, array $balances): array
    {
        $totalBalance = array_sum($balances);

        if ($totalBalance <= 0) {
            return [];
        }

        // Never allocate more than what is owed in aggregate.
        $totalAmount = min($totalAmount, $totalBalance);

        $allocated = [];
        $remainders = [];

        foreach ($balances as $key => $balance) {
            $share = ($totalAmount * $balance) / $totalBalance;
            $quotient = (int) floor($share);
            $remainder = $share - $quotient;

            $allocated[$key] = $quotient;
            $remainders[$key] = $remainder;
        }

        $distributed = array_sum($allocated);
        $remaining = $totalAmount - $distributed;

        if ($remaining > 0) {
            arsort($remainders);
            $count = 0;
            foreach ($remainders as $key => $remainder) {
                if ($count >= $remaining) {
                    break;
                }
                $allocated[$key]++;
                $count++;
            }
        }

        return $allocated;
    }
}
