<?php

namespace Modules\Pos\Services\Adapters;

use Modules\Pos\Services\Contracts\PosCheckoutPostingAdapter;
use Modules\Pos\Services\Exceptions\PosCheckoutPostingException;
use Modules\Pos\Services\Exceptions\PosCheckoutValidationException;
use Modules\Pos\Services\PosCheckoutPaymentSplitService;
use Modules\Pos\Services\PosCheckoutSplitPlannerService;

class SplitPosCheckoutPostingAdapter implements PosCheckoutPostingAdapter
{
    public function __construct(
        private readonly InlinePosCheckoutPostingAdapter $inlinePostingAdapter,
        private readonly PosCheckoutSplitPlannerService $splitPlanner,
        private readonly PosCheckoutPaymentSplitService $paymentSplitService
    ) {
    }

    public function post(array $context): array
    {
        $cartSnapshot = is_array($context['cart_snapshot'] ?? null) ? $context['cart_snapshot'] : [];
        $checkoutGrandTotal = round((float) ($cartSnapshot['totals']['grand_total'] ?? 0), 2);

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

        $paymentAllocations = $this->paymentSplitService->allocate(
            array_map(static fn (array $group): array => [
                'split_key' => (string) ($group['split_key'] ?? ''),
                'grand_total' => (float) ($group['grand_total'] ?? 0),
            ], $groups),
            $checkoutGrandTotal
        );

        $splitGroups = [];
        $sales = [];
        $salePayments = [];

        $actualTaxMinor = 0;
        $actualGrandMinor = 0;
        $allocatedPaidMinor = 0;

        foreach ($groups as $group) {
            $splitKey = (string) ($group['split_key'] ?? '');
            $groupSubtotal = round((float) ($group['subtotal'] ?? 0), 2);
            $groupDiscount = round((float) ($group['discount_total'] ?? 0), 2);
            $groupTax = round((float) ($group['tax_total'] ?? 0), 2);
            $groupGrand = round((float) ($group['grand_total'] ?? 0), 2);

            $groupContext = $context;
            $groupContext['cart_snapshot'] = [
                'setting_id' => (int) ($context['setting_id'] ?? 0),
                'session_id' => (int) ($context['pos_session_id'] ?? 0),
                'lines' => is_array($group['lines'] ?? null) ? $group['lines'] : [],
                'totals' => [
                    'subtotal' => $groupSubtotal,
                    'discount_total' => $groupDiscount,
                    'tax_total' => $groupTax,
                    'grand_total' => $groupGrand,
                ],
                'meta' => [
                    'split_key' => $splitKey,
                ],
            ];
            $groupContext['allocations'] = is_array($group['allocations'] ?? null) ? $group['allocations'] : [];

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
            ];

            $actualTaxMinor += $this->toMinor($groupActualTax);
            $actualGrandMinor += $this->toMinor($groupActualGrand);
            $allocatedPaidMinor += $this->toMinor($groupPaidTotal);
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

        if ($allocatedPaidMinor !== $expectedGrandMinor) {
            throw new PosCheckoutPostingException(
                'POSTING_RECONCILIATION_MISMATCH',
                'Split payment allocation does not reconcile with checkout total.'
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
        ];
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
