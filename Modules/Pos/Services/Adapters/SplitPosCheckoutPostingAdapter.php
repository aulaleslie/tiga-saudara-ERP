<?php

namespace Modules\Pos\Services\Adapters;

use Modules\Pos\Services\Contracts\PosCheckoutPostingAdapter;
use Modules\Pos\Services\Exceptions\PosCheckoutPostingException;
use Modules\Pos\Services\Exceptions\PosCheckoutValidationException;
use Modules\Pos\Services\PosCheckoutGroupCustomerResolverService;
use Modules\Pos\Services\PosCheckoutPaymentSplitService;
use Modules\Pos\Services\PosCheckoutSplitPlannerService;

class SplitPosCheckoutPostingAdapter implements PosCheckoutPostingAdapter
{
    public function __construct(
        private readonly InlinePosCheckoutPostingAdapter $inlinePostingAdapter,
        private readonly PosCheckoutSplitPlannerService $splitPlanner,
        private readonly PosCheckoutPaymentSplitService $paymentSplitService,
        private readonly PosCheckoutGroupCustomerResolverService $groupCustomerResolver,
        private readonly ?\Modules\Pos\Services\PosCheckoutOwnershipPriorityAllocationService $ownershipAllocationService = null
    ) {
    }

    public function post(array $context): array
    {
        $cartSnapshot = is_array($context['cart_snapshot'] ?? null) ? $context['cart_snapshot'] : [];
        $checkoutGrandTotal = round((float) ($cartSnapshot['totals']['grand_total'] ?? 0), 2);

        $isDebt = (bool) ($context['is_debt'] ?? false);
        $payment = is_array($context['payment'] ?? null) ? $context['payment'] : [];
        $downPaymentAmount = round((float) ($payment['amount_paid'] ?? 0), 2);

        $plan = $this->splitPlanner->plan([
            'setting_id' => (int) ($context['setting_id'] ?? 0),
            'cart_snapshot' => $cartSnapshot,
            'allocations' => is_array($context['allocations'] ?? null) ? $context['allocations'] : [],
        ]);

        $groups = is_array($plan['groups'] ?? null) ? $plan['groups'] : [];
        if ($groups === []) {
            throw new PosCheckoutValidationException(
                'STOCK_UNAVAILABLE',
                'No split groups were generated for checkout posting.'
            );
        }

        $this->assertBundleComponentSerialsPartitionCleanly($cartSnapshot, $groups);

        // Determine payment allocation strategy: multi-payment ownership-priority or simple proportional
        $isMultiPayment = (bool) ($payment['is_multi_payment'] ?? false);

        // Extract original (reordered) payment array for slicing
        $reorderedPayments = [];
        if ($isMultiPayment && $this->ownershipAllocationService) {
            // Need to reorder payments the same way allocation service does (non-cash first, then cash)
            $allPayments = $payment['payments'] ?? [];
            $nonCash = [];
            $cash = [];
            foreach ($allPayments as $p) {
                if ((bool) ($p['is_cash'] ?? false)) {
                    $cash[] = $p;
                } else {
                    $nonCash[] = $p;
                }
            }
            $reorderedPayments = array_merge($nonCash, $cash);
        }

        if ($isMultiPayment && $this->ownershipAllocationService) {
            $allocationGroups = array_map(fn (array $g) => [
                'split_key' => (string) ($g['split_key'] ?? ''),
                'source_setting_id' => (int) ($g['source_setting_id'] ?? 0),
                'grand_total_minor' => $this->toMinor((float) ($g['grand_total'] ?? 0)),
            ], $groups);

            if ($isDebt) {
                $debtProportions = $this->paymentSplitService->allocate(
                    array_map(static fn (array $group): array => [
                        'split_key' => (string) ($group['split_key'] ?? ''),
                        'grand_total' => (float) ($group['grand_total'] ?? 0),
                    ], $groups),
                    $downPaymentAmount
                );
                
                foreach ($allocationGroups as &$ag) {
                    $ag['grand_total_minor'] = $this->toMinor((float) ($debtProportions[$ag['split_key']] ?? 0));
                }
                unset($ag);
            }

            // Multi-payment: use ownership-priority allocation
            $allocationResult = $this->ownershipAllocationService->allocate([
                'payments' => array_map(static fn (array $p) => [
                    'payment_method_id' => (int) $p['payment_method_id'],
                    'amount_minor_units' => (int) $p['amount_minor_units'],
                    'is_cash' => (bool) $p['is_cash'],
                ], $reorderedPayments),
                'groups' => $allocationGroups,
                'terminal_setting_id' => (int) ($context['setting_id'] ?? 0),
            ]);

            // Extract per-group payment slices
            $paymentSlices = $this->slicePaymentsPerGroup($allocationResult['allocations'] ?? [], $reorderedPayments);

            // Convert allocations to flat map for paid_total calculation
            $paymentAllocations = [];
            foreach ($groups as $group) {
                $paymentAllocations[(string) ($group['split_key'] ?? '')] = 0.0;
            }
            foreach ($allocationResult['allocations'] ?? [] as $allocation) {
                $splitKey = (string) ($allocation['split_key'] ?? '');
                $amountMinor = (int) ($allocation['allocated_amount_minor_units'] ?? 0);
                $paymentAllocations[$splitKey] = ($paymentAllocations[$splitKey] ?? 0) + $this->fromMinor($amountMinor);
            }
        } else {
            // Legacy single-payment: use simple proportional allocation
            $paymentAllocations = $this->paymentSplitService->allocate(
                array_map(static fn (array $group): array => [
                    'split_key' => (string) ($group['split_key'] ?? ''),
                    'grand_total' => (float) ($group['grand_total'] ?? 0),
                ], $groups),
                $isDebt ? $downPaymentAmount : $checkoutGrandTotal
            );
            $paymentSlices = [];
        }

        $splitGroups = [];
        $sales = [];
        $salePayments = [];

        $actualTaxMinor = 0;
        $actualGrandMinor = 0;
        $allocatedPaidMinor = 0;
        $hppWarnings = [];

        foreach ($groups as $group) {
            $splitKey = (string) ($group['split_key'] ?? '');
            $groupSubtotal = round((float) ($group['subtotal'] ?? 0), 2);
            $groupDiscount = round((float) ($group['discount_total'] ?? 0), 2);
            $groupTax = round((float) ($group['tax_total'] ?? 0), 2);
            $groupGrand = round((float) ($group['grand_total'] ?? 0), 2);
            $sourceSettingId = (int) ($group['source_setting_id'] ?? 0);
            $terminalSettingId = (int) ($context['setting_id'] ?? 0);
            $selectedCustomerId = (int) ($context['customer_id'] ?? 0);

            $groupContext = $context;
            $groupContext['setting_id'] = $sourceSettingId;
            $groupContext['customer_id'] = $this->groupCustomerResolver->resolve(
                $terminalSettingId,
                $sourceSettingId,
                $selectedCustomerId > 0 ? $selectedCustomerId : null
            )['customer_id'];
            $groupContext['cart_snapshot'] = [
                'setting_id' => $sourceSettingId,
                'session_id' => (int) ($context['pos_session_id'] ?? 0),
                'lines' => is_array($group['lines'] ?? null) ? $group['lines'] : [],
                'totals' => [
                    'subtotal' => $groupSubtotal,
                    'discount_total' => $groupDiscount,
                    'tax_total' => $groupTax,
                    'grand_total' => $groupGrand,
                ],
                'note' => $cartSnapshot['note'] ?? null,
                'meta' => [
                    'split_key' => $splitKey,
                ],
            ];
            $groupContext['allocations'] = is_array($group['allocations'] ?? null) ? $group['allocations'] : [];

            // Unconditionally override the amount_paid to the allocated slice
            $groupPayment = is_array($context['payment']) ? $context['payment'] : [];
            $groupPayment['amount_paid'] = $paymentAllocations[$splitKey] ?? 0.0;

            // Task 3.2 & 3.3: Inject per-group payment slices into groupContext for multi-payment
            if ($isMultiPayment && isset($paymentSlices[$splitKey])) {
                $groupPayment['payments'] = $paymentSlices[$splitKey];
                $groupPayment['is_multi_payment'] = true;
                // Use first payment's method and reference for backward compatibility
                if (isset($paymentSlices[$splitKey][0])) {
                    $firstPayment = $paymentSlices[$splitKey][0];
                    $groupPayment['payment_method_id'] = (int) ($firstPayment['payment_method_id'] ?? 0);
                    $groupPayment['reference'] = $firstPayment['reference'] ?? null;
                    $groupPayment['is_cash'] = (bool) ($firstPayment['is_cash'] ?? false);
                }
            }
            $groupContext['payment'] = $groupPayment;

            $result = $this->inlinePostingAdapter->post($groupContext);
            $dispatchIds = array_values(array_map(
                static fn ($id): int => (int) $id,
                is_array($result['dispatch_ids'] ?? null) ? $result['dispatch_ids'] : []
            ));

            $groupActualTax = round((float) ($result['actual_tax_total'] ?? $groupTax), 2);
            $groupActualGrand = round((float) ($result['actual_grand_total'] ?? $groupGrand), 2);
            $groupPaidTotal = round((float) ($paymentAllocations[$splitKey] ?? 0), 2);

            $entry = [
                'split_key' => $splitKey,
                'source_setting_id' => (int) ($group['source_setting_id'] ?? 0),
                'source_location_id' => (int) ($group['source_location_id'] ?? 0),
                'tax_bucket' => (string) ($group['tax_bucket'] ?? 'NON_TAX'),
                'sale_id' => (int) ($result['sale_id'] ?? 0),
                'sale_payment_id' => (int) ($result['sale_payment_id'] ?? 0),
                'dispatch_ids' => $dispatchIds,
                'subtotal' => $groupSubtotal,
                'tax_total' => $groupActualTax,
                'grand_total' => $groupActualGrand,
                'paid_total' => $groupPaidTotal,
                'stage_mappings' => is_array($result['stage_mappings'] ?? null) ? $result['stage_mappings'] : [],
            ];

            $splitGroups[] = $entry;
            $sales[] = [
                'split_key' => $splitKey,
                'sale_id' => (int) ($result['sale_id'] ?? 0),
                'source_setting_id' => (int) ($group['source_setting_id'] ?? 0),
                'source_location_id' => (int) ($group['source_location_id'] ?? 0),
                'tax_bucket' => (string) ($group['tax_bucket'] ?? 'NON_TAX'),
                'subtotal' => $groupSubtotal,
                'tax_total' => $groupActualTax,
                'grand_total' => $groupActualGrand,
            ];
            $salePayments[] = [
                'split_key' => $splitKey,
                'sale_id' => (int) ($result['sale_id'] ?? 0),
                'sale_payment_id' => (int) ($result['sale_payment_id'] ?? 0),
                'amount' => $groupPaidTotal,
                'stage_mappings' => is_array($result['stage_mappings'] ?? null) ? $result['stage_mappings'] : [],
            ];

            $actualTaxMinor += $this->toMinor($groupActualTax);
            $actualGrandMinor += $this->toMinor($groupActualGrand);
            $allocatedPaidMinor += $this->toMinor($groupPaidTotal);

            $groupHppWarnings = is_array($result['hpp_warnings'] ?? null) ? $result['hpp_warnings'] : [];
            foreach ($groupHppWarnings as $warning) {
                $hppWarnings[] = array_merge($warning, ['split_key' => $splitKey]);
            }
        }

        usort($splitGroups, static fn (array $left, array $right): int => strcmp($left['split_key'], $right['split_key']));
        usort($sales, static fn (array $left, array $right): int => strcmp($left['split_key'], $right['split_key']));
        usort($salePayments, static fn (array $left, array $right): int => strcmp($left['split_key'], $right['split_key']));

        $expectedGrandMinor = $this->toMinor($checkoutGrandTotal);
        if ($actualGrandMinor !== $expectedGrandMinor) {
            throw new PosCheckoutPostingException(
                'POSTING_RECONCILIATION_MISMATCH',
                'Split posting grand total does not reconcile with checkout total.'
            );
        }

        $expectedPaidMinor = $isDebt ? $this->toMinor($downPaymentAmount) : $expectedGrandMinor;
        if ($allocatedPaidMinor !== $expectedPaidMinor) {
            throw new PosCheckoutPostingException(
                'POSTING_RECONCILIATION_MISMATCH',
                'Split payment allocation does not reconcile with checkout total paid amount.'
            );
        }

        $firstGroup = $splitGroups[0];

        return [
            'sale_id' => (int) ($firstGroup['sale_id'] ?? 0),
            'dispatch_ids' => array_values(array_map(
                static fn ($id): int => (int) $id,
                is_array($firstGroup['dispatch_ids'] ?? null) ? $firstGroup['dispatch_ids'] : []
            )),
            'sale_payment_id' => (int) ($firstGroup['sale_payment_id'] ?? 0),
            'receipt_number' => '',
            'actual_tax_total' => $this->fromMinor($actualTaxMinor),
            'actual_grand_total' => $this->fromMinor($actualGrandMinor),
            'split_groups' => $splitGroups,
            'sales' => $sales,
            'sale_payments' => $salePayments,
            'split_summary' => [
                'group_count' => count($splitGroups),
                'groups' => $splitGroups,
            ],
            'hpp_warnings' => $hppWarnings,
        ];
    }

    /**
     * Guard against the split planner silently dropping or duplicating a
     * bundle-component serial across owner groups. The planner re-derives
     * each group's `"{lineIndex}_C_{itemIndex}"` allocation chunks from the
     * resolver's per-serial-location grouping, while every group line still
     * carries the *original*, unfiltered `bundle_item_serials` map — nothing
     * upstream asserts those two views stay reconciled, so verify it here
     * before any group is posted.
     *
     * @param  array<string, mixed>  $cartSnapshot
     * @param  array<int, array<string, mixed>>  $groups
     */
    private function assertBundleComponentSerialsPartitionCleanly(array $cartSnapshot, array $groups): void
    {
        $lines = is_array($cartSnapshot['lines'] ?? null) ? $cartSnapshot['lines'] : [];

        foreach ($lines as $lineIndex => $line) {
            $bundleItemSerials = is_array($line['bundle_item_serials'] ?? null) ? $line['bundle_item_serials'] : [];

            foreach ($bundleItemSerials as $bundleItemId => $expectedSerials) {
                $expectedSerials = array_values(array_filter(
                    (array) $expectedSerials,
                    static fn ($sn): bool => is_string($sn) && trim($sn) !== ''
                ));

                if ($expectedSerials === []) {
                    continue;
                }

                $itemIndex = $this->resolveBundleItemIndex($line, (int) $bundleItemId);
                if ($itemIndex === null) {
                    continue;
                }

                $childKey = "{$lineIndex}_C_{$itemIndex}";
                $seenSerials = [];

                foreach ($groups as $group) {
                    $groupAllocations = is_array($group['allocations'] ?? null) ? $group['allocations'] : [];
                    $chunks = is_array($groupAllocations[$childKey] ?? null) ? $groupAllocations[$childKey] : [];

                    foreach ($chunks as $chunk) {
                        $chunkSerials = is_array($chunk['serial_numbers'] ?? null) ? $chunk['serial_numbers'] : [];
                        foreach ($chunkSerials as $sn) {
                            $seenSerials[] = $sn;
                        }
                    }
                }

                $expectedCounts = array_count_values($expectedSerials);
                $seenCounts = array_count_values($seenSerials);

                if ($expectedCounts != $seenCounts) {
                    $productLabel = (string) (($line['product_name'] ?? null) ?: "#{$line['product_id']}");
                    throw new PosCheckoutPostingException(
                        'POSTING_RECONCILIATION_MISMATCH',
                        "Alokasi seri komponen paket untuk produk $productLabel tidak sesuai antar grup pemilik."
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function resolveBundleItemIndex(array $line, int $bundleItemId): ?int
    {
        $bundleItems = is_array($line['bundle_items'] ?? null) ? $line['bundle_items'] : [];

        foreach ($bundleItems as $idx => $item) {
            if ((int) ($item['bundle_item_id'] ?? 0) === $bundleItemId) {
                return (int) $idx;
            }
        }

        return null;
    }

    /**
     * Extract per-group payment slices from the allocation matrix.
     *
     * Returns an array where each group gets only the payment methods allocated to it,
     * with amounts already determined by the allocation matrix.
     *
     * @param  array<int, array{payment_index:int,split_key:string,allocated_amount_minor_units:int}>  $allocations
     * @param  array<int, array{payment_method_id:int,amount_minor_units:int,is_cash:bool,reference?:string}>  $originalPayments
     * @return array<string, array<int, array{payment_method_id:int,amount_minor_units:int,is_cash:bool,reference?:string}>>
     */
    private function slicePaymentsPerGroup(array $allocations, array $originalPayments): array
    {
        $slices = [];

        // Group allocations by split_key
        $allocationsByGroup = [];
        foreach ($allocations as $allocation) {
            $splitKey = (string) ($allocation['split_key'] ?? '');
            $paymentIndex = (int) ($allocation['payment_index'] ?? 0);
            $amountMinor = (int) ($allocation['allocated_amount_minor_units'] ?? 0);

            if (!isset($allocationsByGroup[$splitKey])) {
                $allocationsByGroup[$splitKey] = [];
            }

            $allocationsByGroup[$splitKey][] = [
                'payment_index' => $paymentIndex,
                'amount_minor_units' => $amountMinor,
            ];
        }

        // Build per-group payment slices
        foreach ($allocationsByGroup as $splitKey => $groupAllocations) {
            $slices[$splitKey] = [];

            // Group allocations by payment_index to collect all amounts for a single payment method
            $allocationsByPaymentIndex = [];
            foreach ($groupAllocations as $alloc) {
                $paymentIndex = (int) ($alloc['payment_index'] ?? 0);
                $amountMinor = (int) ($alloc['amount_minor_units'] ?? 0);

                if (!isset($allocationsByPaymentIndex[$paymentIndex])) {
                    $allocationsByPaymentIndex[$paymentIndex] = 0;
                }

                $allocationsByPaymentIndex[$paymentIndex] += $amountMinor;
            }

            // Create payment entries from allocations
            foreach ($allocationsByPaymentIndex as $paymentIndex => $totalAmountMinor) {
                if ($totalAmountMinor <= 0) {
                    continue;
                }

                $originalPayment = $originalPayments[$paymentIndex] ?? null;
                if (!$originalPayment) {
                    continue;
                }

                $slices[$splitKey][] = [
                    'payment_method_id' => (int) ($originalPayment['payment_method_id'] ?? 0),
                    'amount_minor_units' => $totalAmountMinor,
                    'is_cash' => (bool) ($originalPayment['is_cash'] ?? false),
                    'reference' => $originalPayment['reference'] ?? null,
                    'edc_reference' => $originalPayment['edc_reference'] ?? null,
                    'stage_order' => $originalPayment['stage_order'] ?? null,
                    'payment_image_token' => $originalPayment['payment_image_token'] ?? null,
                ];
            }
        }

        return $slices;
    }

    private function toMinor(float $value): int
    {
        return (int) round($value * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function fromMinor(int $value): float
    {
        return round($value / 100, 2);
    }
}
