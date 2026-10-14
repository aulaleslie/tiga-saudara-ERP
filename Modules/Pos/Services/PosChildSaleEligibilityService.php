<?php

namespace Modules\Pos\Services;

use Modules\Sale\Entities\Sale;

class PosChildSaleEligibilityService
{
    public const ELIGIBLE_LIFECYCLE_STATUSES = [
        Sale::STATUS_APPROVED,
        Sale::STATUS_DISPATCHED_PARTIALLY,
        Sale::STATUS_DISPATCHED,
        Sale::STATUS_RETURNED_PARTIALLY,
    ];

    /**
     * Determine if a reachable child Sale is eligible for POS settlement/payment.
     *
     * A Sale is eligible if:
     * 1. It is unarchived and in an ordinary eligible lifecycle status
     *    (APPROVED, DISPATCHED_PARTIALLY, DISPATCHED, RETURNED_PARTIALLY).
     * 2. OR it is fully returned (status = RETURNED) with zero total amount
     *    and zero canonical live due (regardless of whether it is archived or unarchived).
     *
     * Ineligible Sales (such as REJECTED, archived ordinary Sales, or fully returned Sales
     * with residual total or outstanding debt) return false and block transaction payment.
     *
     * @param Sale $sale
     * @param float|null $liveDue
     * @param float|null $totalAmount
     * @return bool
     */
    public function isEligible(Sale $sale, ?float $liveDue = null, ?float $totalAmount = null): bool
    {
        return self::check($sale, $liveDue, $totalAmount);
    }

    /**
     * Static check for child Sale eligibility.
     *
     * @param Sale $sale
     * @param float|null $liveDue
     * @param float|null $totalAmount
     * @return bool
     */
    public static function check(Sale $sale, ?float $liveDue = null, ?float $totalAmount = null): bool
    {
        // 1. Ordinary eligible child Sale
        if ($sale->archived_at === null && in_array($sale->status, self::ELIGIBLE_LIFECYCLE_STATUSES, true)) {
            return true;
        }

        // 2. Exempt fully returned, financially cleared child Sale
        if ($sale->status === Sale::STATUS_RETURNED) {
            $total = $totalAmount !== null ? $totalAmount : (float) ($sale->total_amount ?? 0);
            $due = $liveDue !== null ? $liveDue : (float) ($sale->live_due_amount ?? 0);

            return round($total, 2) == 0.0 && round($due, 2) == 0.0;
        }

        return false;
    }
}
