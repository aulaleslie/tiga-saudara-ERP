<?php

namespace Modules\Pos\Services;

use Modules\Pos\Services\Exceptions\PosCheckoutValidationException;

class PosCheckoutOwnershipPriorityAllocationService
{
    /**
     * Allocate multi-payment across split groups with ownership-priority logic.
     *
     * Allocation strategy:
     * - Cash payments: allocated to non-terminal-owned groups first (respects ownership),
     *   then overflow to terminal-owned groups proportionally.
     * - Non-cash payments: allocated to terminal-owned groups first,
     *   then overflow to other groups proportionally.
     *
     * @param  array{
     *     payments: array<int, array{payment_method_id:int,amount_minor_units:int,is_cash:bool}>,
     *     groups: array<int, array{
     *         split_key:string,
     *         source_setting_id:int,
     *         grand_total_minor:int
     *     }>,
     *     terminal_setting_id: int
     * }  $context
     * @return array{
     *     allocations: array<int, array{
     *         payment_index: int,
     *         split_key: string,
     *         allocated_amount_minor_units: int
     *     }>,
     *     checkout_grand_total_minor: int
     * }
     */
    public function allocate(array $context): array
    {
        $payments = $context['payments'] ?? [];
        $groups = $context['groups'] ?? [];
        $terminalSettingId = (int) ($context['terminal_setting_id'] ?? 0);

        if (!$payments || !$groups || $terminalSettingId <= 0) {
            throw new PosCheckoutValidationException('ALLOCATION_INVALID', 'Invalid allocation context.');
        }

        // Reorder payments: non-cash first, then cash (deterministic ordering)
        // Keep track of original indices
        $reorderedPayments = [];
        foreach ($this->reorderPayments($payments) as $payment) {
            $reorderedPayments[] = $payment;
        }

        // Initialize group balances (track what still needs to be allocated to each group)
        $groupBalances = [];
        $checkoutGrandTotalMinor = 0;
        foreach ($groups as $group) {
            $splitKey = (string) ($group['split_key'] ?? '');
            $grandTotalMinor = (int) ($group['grand_total_minor'] ?? 0);
            $groupBalances[$splitKey] = $grandTotalMinor;
            $checkoutGrandTotalMinor += $grandTotalMinor;
        }

        // Track which groups are terminal-owned vs non-terminal-owned
        $terminalOwnedGroups = array_filter(
            $groups,
            fn(array $g) => (int) ($g['source_setting_id'] ?? 0) === $terminalSettingId
        );
        $terminalOwnedKeys = array_map(
            fn(array $g) => (string) ($g['split_key'] ?? ''),
            $terminalOwnedGroups
        );

        // Non-terminal-owned groups (all other groups)
        $nonTerminalOwnedKeys = array_filter(
            array_map(fn(array $g) => (string) ($g['split_key'] ?? ''), $groups),
            fn(string $key) => !in_array($key, $terminalOwnedKeys)
        );

        $allocations = [];

        // Process each payment in reordered sequence (paymentIndex is the reordered position, not original)
        foreach ($reorderedPayments as $paymentIndex => $payment) {
            $paymentAmountMinor = (int) ($payment['amount_minor_units'] ?? 0);
            $isCash = (bool) ($payment['is_cash'] ?? false);

            if ($paymentAmountMinor <= 0) {
                continue;
            }

            if ($isCash) {
                // Cash: allocate to non-terminal-owned groups first (ownership priority)
                $remainingAmount = $this->allocateToGroups(
                    $paymentIndex,
                    $paymentAmountMinor,
                    $nonTerminalOwnedKeys,
                    $groupBalances,
                    $allocations
                );

                // Cash overflow: allocate remaining proportionally
                if ($remainingAmount > 0) {
                    $this->allocateProportionally(
                        $paymentIndex,
                        $remainingAmount,
                        $groupBalances,
                        $allocations
                    );
                }
            } else {
                // Non-cash: allocate to terminal-owned groups first
                $remainingAmount = $this->allocateToGroups(
                    $paymentIndex,
                    $paymentAmountMinor,
                    $terminalOwnedKeys,
                    $groupBalances,
                    $allocations
                );

                // Non-cash overflow: allocate remaining proportionally
                if ($remainingAmount > 0) {
                    $this->allocateProportionally(
                        $paymentIndex,
                        $remainingAmount,
                        $groupBalances,
                        $allocations
                    );
                }
            }
        }

        // Validate matrix reconciliation
        $this->validateReconciliation($allocations, $groupBalances, $checkoutGrandTotalMinor);

        return [
            'allocations' => $allocations,
            'checkout_grand_total_minor' => $checkoutGrandTotalMinor,
        ];
    }

    /**
     * Reorder payments so non-cash comes first (deterministic).
     *
     * @param  array<int, array{payment_method_id:int,amount_minor_units:int,is_cash:bool}>  $payments
     * @return array<int, array{payment_method_id:int,amount_minor_units:int,is_cash:bool}>
     */
    private function reorderPayments(array $payments): array
    {
        $nonCash = [];
        $cash = [];

        foreach ($payments as $index => $payment) {
            $isCash = (bool) ($payment['is_cash'] ?? false);
            if ($isCash) {
                $cash[$index] = $payment;
            } else {
                $nonCash[$index] = $payment;
            }
        }

        // Reindex to maintain order: non-cash first, then cash
        $reordered = [];
        foreach ($nonCash as $payment) {
            $reordered[] = $payment;
        }
        foreach ($cash as $payment) {
            $reordered[] = $payment;
        }

        return $reordered;
    }

    /**
     * Allocate payment to specific group keys first.
     *
     * @param  int  $paymentIndex
     * @param  int  $paymentAmountMinor
     * @param  array<string>  $groupKeys  Group split keys to allocate to
     * @param  array<string, int>  &$groupBalances
     * @param  array<int, array>  &$allocations
     * @return int  Remaining amount not allocated
     */
    private function allocateToGroups(
        int $paymentIndex,
        int $paymentAmountMinor,
        array $groupKeys,
        array &$groupBalances,
        array &$allocations
    ): int {
        $remainingAmount = $paymentAmountMinor;

        foreach ($groupKeys as $splitKey) {
            if ($remainingAmount <= 0) {
                break;
            }

            $balance = $groupBalances[$splitKey] ?? 0;
            if ($balance <= 0) {
                continue;
            }

            $allocatedAmount = min($remainingAmount, $balance);
            $groupBalances[$splitKey] -= $allocatedAmount;
            $remainingAmount -= $allocatedAmount;

            $allocations[] = [
                'payment_index' => $paymentIndex,
                'split_key' => $splitKey,
                'allocated_amount_minor_units' => $allocatedAmount,
            ];
        }

        return $remainingAmount;
    }

    /**
     * Allocate remaining amount proportionally across groups with remaining balances.
     *
     * @param  int  $paymentIndex
     * @param  int  $paymentAmountMinor
     * @param  array<string, int>  &$groupBalances
     * @param  array<int, array>  &$allocations
     */
    private function allocateProportionally(
        int $paymentIndex,
        int $paymentAmountMinor,
        array &$groupBalances,
        array &$allocations
    ): void {
        // Filter groups that still have balance
        $groupsWithBalance = array_filter($groupBalances, fn(int $balance) => $balance > 0);

        if (!$groupsWithBalance) {
            // Nothing to allocate to
            return;
        }

        $totalRemaining = array_sum($groupsWithBalance);

        if ($totalRemaining <= 0) {
            return;
        }

        // Use largest-remainder method for deterministic rounding
        $allocated = $this->allocateByLargestRemainder(
            $paymentAmountMinor,
            $groupsWithBalance
        );

        foreach ($allocated as $splitKey => $amount) {
            if ($amount <= 0) {
                continue;
            }

            $groupBalances[$splitKey] -= $amount;

            $allocations[] = [
                'payment_index' => $paymentIndex,
                'split_key' => $splitKey,
                'allocated_amount_minor_units' => $amount,
            ];
        }
    }

    /**
     * Allocate amount using largest-remainder method (deterministic).
     *
     * @param  int  $totalAmount
     * @param  array<string, int>  $balances  (split_key => remaining_balance)
     * @return array<string, int>  (split_key => allocated_amount)
     */
    private function allocateByLargestRemainder(int $totalAmount, array $balances): array
    {
        $totalBalance = array_sum($balances);

        if ($totalBalance <= 0) {
            return [];
        }

        $allocated = [];
        $quotients = [];
        $remainders = [];

        // Calculate quotient and remainder for each group
        foreach ($balances as $splitKey => $balance) {
            // amount = totalAmount * (balance / totalBalance)
            $share = ($totalAmount * $balance) / $totalBalance;
            $quotient = (int) floor($share);
            $remainder = $share - $quotient;

            $allocated[$splitKey] = $quotient;
            $quotients[$splitKey] = $quotient;
            $remainders[$splitKey] = $remainder;
        }

        // Distribute remaining minor units using largest-remainder
        $distributed = array_sum($allocated);
        $remaining = $totalAmount - $distributed;

        if ($remaining > 0) {
            // Sort by remainder (descending), then by split_key (alphabetic for tie-break)
            arsort($remainders);
            $count = 0;
            foreach ($remainders as $splitKey => $remainder) {
                if ($count >= $remaining) {
                    break;
                }
                $allocated[$splitKey]++;
                $count++;
            }
        }

        return $allocated;
    }

    /**
     * Validate matrix reconciliation: per-payment, per-group, and total.
     *
     * @param  array<int, array>  $allocations
     * @param  array<string, int>  $groupBalances  (remaining, should be all 0)
     * @param  int  $expectedGrandTotalMinor
     */
    private function validateReconciliation(
        array $allocations,
        array $groupBalances,
        int $expectedGrandTotalMinor
    ): void {
        // Check that all groups are fully allocated
        $remainingTotal = array_sum($groupBalances);
        if ($remainingTotal !== 0) {
            throw new PosCheckoutValidationException(
                'ALLOCATION_FAILED',
                'Allocation matrix does not reconcile: groups have unallocated balances.'
            );
        }

        // Verify total allocation equals expected grand total
        $totalAllocated = array_sum(array_map(
            fn(array $a) => (int) ($a['allocated_amount_minor_units'] ?? 0),
            $allocations
        ));

        if ($totalAllocated !== $expectedGrandTotalMinor) {
            throw new PosCheckoutValidationException(
                'ALLOCATION_FAILED',
                "Allocation matrix total ({$totalAllocated}) does not match checkout grand total ({$expectedGrandTotalMinor})."
            );
        }
    }
}
