<?php

namespace App\Support\SalesReturn;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Report-side view of standard cash-return adjustments (sale_return_adjustments).
 *
 * Per sale: SUM(debt_reduction) canceled debt, SUM(cash_refund) cash given back, SUM(committed_value)
 * returned value. Reports that show receivables/balances must subtract the debt reduction (or net
 * the total by committed value and the payments by cash refunds) exactly once. $asOf limits to
 * adjustments committed on or before that date for as-of reports.
 */
class SaleAdjustmentSql
{
    public static function subquery(?string $asOf = null): Builder
    {
        return DB::table('sale_return_adjustments')
            ->select('sale_id')
            ->selectRaw('SUM(debt_reduction) as adj_debt')
            ->selectRaw('SUM(cash_refund) as adj_refund')
            ->selectRaw('SUM(committed_value) as adj_value')
            ->when($asOf, fn ($q) => $q->whereDate('committed_at', '<=', $asOf))
            ->groupBy('sale_id');
    }

    /**
     * Payouts that operational reports recognize, by their OWN date and independently of the return
     * header's eventual completion: legacy payouts of COMPLETED returns, plus every payout linked to
     * a committed standard adjustment (those are evidence of cash that already left).
     * With $eligibleSalesOnly the linked payouts must also belong to a report-eligible source sale
     * (the ledger only books sale payments of eligible, unarchived sales).
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query  SaleReturnPayment query
     * @param  array<int, int>  $settingIds
     */
    public static function applyReportablePayouts($query, array $settingIds, bool $eligibleSalesOnly = false): void
    {
        $query->where(function ($w) use ($settingIds, $eligibleSalesOnly) {
            $w->whereIn('sale_return_id', DB::table('sale_returns')
                    ->whereIn('setting_id', $settingIds)
                    ->whereIn('status', ['Completed', 'COMPLETED'])
                    ->select('id'))
              ->where(function ($legacy) use ($settingIds) {
                  $legacy->whereNull('sale_return_item_settlement_id')
                      ->orWhereNotIn('sale_return_item_settlement_id', self::linkedSettlementIds($settingIds));
              });
            $w->orWhereIn('sale_return_item_settlement_id', self::linkedSettlementIds($settingIds, $eligibleSalesOnly));
        });
    }

    /** Settlement ids that carry a committed adjustment, scoped to the settings (optionally eligible sales only). */
    public static function linkedSettlementIds(array $settingIds, bool $eligibleSalesOnly = false): Builder
    {
        $q = DB::table('sale_return_adjustments as a')
            ->join('sale_returns as r', 'r.id', '=', 'a.sale_return_id')
            ->whereIn('r.setting_id', $settingIds)
            ->select('a.sale_return_item_settlement_id');

        if ($eligibleSalesOnly) {
            $q->whereIn('a.sale_id', self::eligibleSaleIds($settingIds));
        }

        return $q;
    }

    /** Ledger-eligible source sales: same unarchived/status rule the ledger uses for sale entries. */
    public static function eligibleSaleIds(array $settingIds): Builder
    {
        return DB::table('sales as s')
            ->whereIn('s.setting_id', $settingIds)
            ->whereRaw(\App\Services\Reports\Concerns\FulfilledTransactionEligibility::saleSqlExpression('s'))
            ->select('s.id');
    }
}
