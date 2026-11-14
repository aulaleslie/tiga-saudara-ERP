<?php

namespace Modules\Sale\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;

/**
 * Global Sales summary card reads (outstanding, overdue, recent collections) computed in SQL.
 *
 * Each settlement ledger is aggregated independently into a one-row-per-Sale derived table and
 * left-joined to the scoped eligible Sales, so multiple payments/credits/transfers/reductions never
 * multiply each other and no Sale model or per-Sale balance query is needed.
 *
 * Semantics mirror the existing reads exactly:
 *  - Membership uses the canonical (unclamped) live-due predicate, as whereLiveDueAmountGreaterThan /
 *    whereLiveDueAmountLessThanOrEqual do.
 *  - Monetary totals sum the per-Sale accessor value Sale::live_due_amount:
 *      paid = max(0, round(payments + credits + incoming - outgoing, 2))
 *      due  = max(0, round(total_amount - paid - round(debt_reduction, 2), 2))
 *    i.e. per-Sale rounding and clamping, never a clamp over the grand sum.
 */
class GlobalSaleSummaryQuery
{
    /**
     * @param array<int> $businessIds
     * @return array{outstanding: array{count:int,total:float}, overdue: array{count:int,total:float}, recent: array{count:int,total:float}}
     */
    public function summarize(
        ?int $customerId,
        array $businessIds,
        ?string $documentDateFrom,
        ?string $documentDateTo,
        ?string $dueDateFrom,
        ?string $dueDateTo
    ): array {
        $row = $this->summaryAggregateQuery($customerId, $businessIds, $documentDateFrom, $documentDateTo, $dueDateFrom, $dueDateTo)
            ->first();

        return [
            'outstanding' => [
                'count' => (int) ($row->outstanding_count ?? 0),
                'total' => (float) ($row->outstanding_total ?? 0),
            ],
            'overdue' => [
                'count' => (int) ($row->overdue_count ?? 0),
                'total' => (float) ($row->overdue_total ?? 0),
            ],
            'recent' => $this->recentCollections($customerId, $businessIds, $documentDateFrom, $documentDateTo, $dueDateFrom, $dueDateTo),
        ];
    }

    /**
     * Single grouped aggregate query producing outstanding/overdue counts and totals.
     */
    public function summaryAggregateQuery(
        ?int $customerId,
        array $businessIds,
        ?string $documentDateFrom,
        ?string $documentDateTo,
        ?string $dueDateFrom,
        ?string $dueDateTo
    ): Builder {
        $raw = $this->rawDueExpression();
        $due = $this->clampedAccessorDueExpression();
        $overdueCondition = "({$raw}) > 0 AND sales.due_date < ?";
        $today = Carbon::today();

        return $this->scopedSalesWithSettlement($customerId, $businessIds, $documentDateFrom, $documentDateTo, $dueDateFrom, $dueDateTo)
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN ({$raw}) > 0 THEN 1 ELSE 0 END), 0) AS outstanding_count, "
                . "COALESCE(SUM(CASE WHEN ({$raw}) > 0 THEN {$due} ELSE 0 END), 0) AS outstanding_total, "
                . "COALESCE(SUM(CASE WHEN {$overdueCondition} THEN 1 ELSE 0 END), 0) AS overdue_count, "
                . "COALESCE(SUM(CASE WHEN {$overdueCondition} THEN {$due} ELSE 0 END), 0) AS overdue_total",
                [$today, $today]
            );
    }

    /**
     * Active payment amounts in the inclusive recent 30-day window over currently eligible,
     * fully settled Sales (canonical live due <= 0), with distinct Sale counts.
     *
     * @param array<int> $businessIds
     * @return array{count:int,total:float}
     */
    public function recentCollections(
        ?int $customerId,
        array $businessIds,
        ?string $documentDateFrom,
        ?string $documentDateTo,
        ?string $dueDateFrom,
        ?string $dueDateTo
    ): array {
        $settledSaleIds = $this->scopedSalesWithSettlement($customerId, $businessIds, $documentDateFrom, $documentDateTo, $dueDateFrom, $dueDateTo)
            ->whereRaw('(' . $this->rawDueExpression() . ') <= 0')
            ->select('sales.id');

        $result = SalePayment::query()
            ->where('date', '>=', Carbon::today()->subDays(30)->format('Y-m-d'))
            ->where('date', '<=', Carbon::today()->endOfDay())
            ->where('status', SalePayment::STATUS_ACTIVE)
            ->whereIn('sale_id', $settledSaleIds->toBase())
            ->selectRaw('COUNT(DISTINCT sale_id) as cnt, SUM(amount) as total')
            ->first();

        return [
            'count' => (int) ($result->cnt ?? 0),
            'total' => (float) ($result->total ?? 0),
        ];
    }

    /**
     * Eligible, non-archived, scoped Sales left-joined to one-row-per-Sale settlement aggregates.
     *
     * @param array<int> $businessIds
     */
    protected function scopedSalesWithSettlement(
        ?int $customerId,
        array $businessIds,
        ?string $documentDateFrom,
        ?string $documentDateTo,
        ?string $dueDateFrom,
        ?string $dueDateTo
    ): Builder {
        $active = SalePayment::STATUS_ACTIVE;
        $scope = fn () => $this->scopedSales($customerId, $businessIds, $documentDateFrom, $documentDateTo, $dueDateFrom, $dueDateTo);
        // With a narrowing scope (customer/business/dates), aggregate each ledger only for Sales in
        // scope (semi-join). The unscoped default reads nearly every eligible Sale, where a plain
        // full grouping measured faster than the semi-join on local MySQL (see benchmark.md).
        $narrowed = !empty($customerId) || !empty($businessIds) || !empty($documentDateFrom)
            || !empty($documentDateTo) || !empty($dueDateFrom) || !empty($dueDateTo);
        $restrict = function ($query, string $column) use ($narrowed, $scope) {
            return $narrowed ? $query->whereIn($column, $scope()->select('sales.id')->toBase()) : $query;
        };

        $payments = DB::table('sale_payments')
            ->where('status', $active)
            ->groupBy('sale_id')
            ->selectRaw('sale_id, SUM(amount) AS amount_sum');

        $credits = DB::table('sale_payment_credit_applications as spca')
            ->join('sale_payments as sp', 'spca.sale_payment_id', '=', 'sp.id')
            ->where('sp.status', $active)
            ->groupBy('sp.sale_id')
            ->selectRaw('sp.sale_id AS sale_id, SUM(spca.amount) AS amount_sum');

        $payments = $restrict($payments, 'sale_id');
        $credits = $restrict($credits, 'sp.sale_id');
        $incoming = $restrict($this->transferAggregate('replacement_sale_id', $active), 'popt.replacement_sale_id');
        $outgoing = $restrict($this->transferAggregate('source_sale_id', $active), 'popt.source_sale_id');

        $reductions = $restrict(DB::table('sale_return_adjustments')
            ->groupBy('sale_id')
            ->selectRaw('sale_id, SUM(debt_reduction) AS amount_sum'), 'sale_id');

        return $scope()
            ->leftJoinSub($payments, 'agg_pay', 'agg_pay.sale_id', '=', 'sales.id')
            ->leftJoinSub($credits, 'agg_cred', 'agg_cred.sale_id', '=', 'sales.id')
            ->leftJoinSub($incoming, 'agg_in', 'agg_in.sale_id', '=', 'sales.id')
            ->leftJoinSub($outgoing, 'agg_out', 'agg_out.sale_id', '=', 'sales.id')
            ->leftJoinSub($reductions, 'agg_red', 'agg_red.sale_id', '=', 'sales.id');
    }

    /** Eligible, non-archived Sales within the applied customer/business/date scope. */
    protected function scopedSales(
        ?int $customerId,
        array $businessIds,
        ?string $documentDateFrom,
        ?string $documentDateTo,
        ?string $dueDateFrom,
        ?string $dueDateTo
    ): Builder {
        $query = Sale::query()
            ->globalPaymentEligible()
            ->whereNull('sales.archived_at');

        if (!empty($customerId)) {
            $query->where('sales.customer_id', $customerId);
        }
        if (!empty($businessIds)) {
            $query->whereIn('sales.setting_id', $businessIds);
        }
        if (!empty($documentDateFrom)) {
            $query->where('sales.date', '>=', $documentDateFrom);
        }
        if (!empty($documentDateTo)) {
            $query->where('sales.date', '<=', $documentDateTo);
        }
        if (!empty($dueDateFrom)) {
            $query->where('sales.due_date', '>=', $dueDateFrom);
        }
        if (!empty($dueDateTo)) {
            $query->where('sales.due_date', '<=', $dueDateTo);
        }

        return $query;
    }

    protected function transferAggregate(string $saleColumn, string $active): QueryBuilder
    {
        return DB::table('pos_owner_payment_transfers as popt')
            ->leftJoin('sale_payments as sp_orig', 'popt.original_sale_payment_id', '=', 'sp_orig.id')
            ->where(function ($q) use ($active) {
                $q->whereNull('popt.original_sale_payment_id')
                    ->orWhere('sp_orig.status', $active);
            })
            ->groupBy("popt.{$saleColumn}")
            ->selectRaw("popt.{$saleColumn} AS sale_id, SUM(popt.amount) AS amount_sum");
    }

    /** Unrounded settlement total: payments + credits + incoming - outgoing. */
    protected function settledExpression(): string
    {
        return '(COALESCE(agg_pay.amount_sum, 0) + COALESCE(agg_cred.amount_sum, 0)'
            . ' + COALESCE(agg_in.amount_sum, 0) - COALESCE(agg_out.amount_sum, 0))';
    }

    /** Canonical unclamped live due (same arithmetic as Sale::canonicalLiveDueFormula()). */
    protected function rawDueExpression(): string
    {
        return 'sales.total_amount - ' . $this->settledExpression() . ' - COALESCE(agg_red.amount_sum, 0)';
    }

    /** Per-Sale value equal to Sale::live_due_amount (round + clamp per Sale). */
    protected function clampedAccessorDueExpression(): string
    {
        $paid = 'ROUND(' . $this->settledExpression() . ', 2)';
        $paidClamped = "(CASE WHEN {$paid} < 0 THEN 0 ELSE {$paid} END)";
        $due = "ROUND(sales.total_amount - {$paidClamped} - ROUND(COALESCE(agg_red.amount_sum, 0), 2), 2)";

        return "(CASE WHEN {$due} < 0 THEN 0 ELSE {$due} END)";
    }
}
