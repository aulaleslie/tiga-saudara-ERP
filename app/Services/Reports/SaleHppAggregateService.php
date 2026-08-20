<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;
use Modules\Sale\Entities\Sale;

/**
 * Shared net Sale HPP aggregate used by profit/loss and operational movement
 * reporting, per openspec/changes/harden-product-bundle-hpp design.md decisions #5/#6:
 *
 *   gross parent HPP + gross component HPP - effective returned HPP
 *     + effective same-owner replacement outgoing HPP = net recognized HPP
 *
 * Parent, component, return-reversal, and replacement-dispatch HPP are each
 * aggregated independently (grouped by sale_id first) before being combined,
 * so joining multiple bundle-component or replacement-dispatch rows against
 * one parent Sale can never multiply another contribution's own total.
 *
 * Same-owner replacement dispatches persist their outgoing snapshot directly
 * on dispatch_details (no new commercial SaleDetails/Sale row exists for that
 * leg), so they are included here explicitly. Cross-owner replacements create
 * a brand-new Sale/SaleDetails row instead — that row's own parent-HPP
 * contribution already covers it, and its DispatchDetail is never stamped
 * with replacement_cost_snapshot_at, so it is naturally excluded from this
 * dispatch-based aggregation and never double-counted.
 *
 * POS cash returns and cross-owner replacements both proportionally reduce
 * the underlying sale_details/sale_bundle_items quantity and sub_total at
 * execution time (see PosReturnLifecycleService::applyCashReturnSaleDetailCorrections),
 * so the live parent/component HPP terms above are already net of those
 * returns. Only rows where commercial_quantity_also_reduced is NOT true
 * (same-owner replacement corrections, and standard non-POS Sales Returns,
 * which never mutate sale_details quantity at all) are subtracted here —
 * subtracting an already-reflected reduction a second time would understate
 * net HPP.
 */
class SaleHppAggregateService
{
    /**
     * Scalar dpp/hpp totals across all matching Sales in scope, e.g. for
     * profit/loss and opening-balance calculations.
     *
     * @param  array<int, int>  $settingIds
     * @return object{dpp: float, hpp: float}
     */
    public function totals(array $settingIds, ?string $startDate = null, ?string $endDate = null, string $dateComparator = 'between'): object
    {
        $perSale = $this->perSaleSubquery($settingIds, $startDate, $endDate, $dateComparator);

        $result = DB::query()
            ->fromSub($perSale, 'net')
            ->selectRaw('COALESCE(SUM(net.dpp), 0) as dpp, COALESCE(SUM(net.net_hpp), 0) as hpp')
            ->first();

        return (object) [
            'dpp' => (float) ($result->dpp ?? 0),
            'hpp' => (float) ($result->hpp ?? 0),
        ];
    }

    /**
     * Per-sale dpp/net_hpp rows for period-movement event emission, keyed by sale_id.
     *
     * @param  array<int, int>  $settingIds
     * @return \Illuminate\Support\Collection<int, object{sale_id: int, dpp: float, net_hpp: float}>
     */
    public function perSale(array $settingIds, ?string $startDate = null, ?string $endDate = null, string $dateComparator = 'between'): \Illuminate\Support\Collection
    {
        return $this->perSaleSubquery($settingIds, $startDate, $endDate, $dateComparator)
            ->get()
            ->keyBy('sale_id');
    }

    /**
     * @param  array<int, int>  $settingIds
     */
    protected function perSaleSubquery(array $settingIds, ?string $startDate, ?string $endDate, string $dateComparator): \Illuminate\Database\Query\Builder
    {
        $statuses = [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY, Sale::STATUS_RETURNED];

        $salesScope = DB::table('sales')
            ->whereIn('setting_id', $settingIds)
            ->whereIn('status', $statuses)
            ->when($startDate && $dateComparator === 'between', fn ($q) => $q->whereDate('date', '>=', $startDate))
            ->when($endDate && $dateComparator === 'between', fn ($q) => $q->whereDate('date', '<=', $endDate))
            ->when($startDate && $dateComparator === 'before', fn ($q) => $q->whereDate('date', '<', $startDate))
            ->select('id');

        $parentAgg = DB::table('sale_details')
            ->selectRaw('sale_id, SUM(sub_total - COALESCE(product_tax_amount, 0)) as dpp, SUM(COALESCE(cost_unit_snapshot, 0) * quantity) as parent_hpp')
            ->groupBy('sale_id');

        $componentAgg = DB::table('sale_bundle_items')
            ->selectRaw('sale_id, SUM(COALESCE(cost_unit_snapshot, 0) * quantity) as component_hpp')
            ->groupBy('sale_id');

        $returnAgg = DB::table('sale_return_details')
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_details.sale_return_id')
            ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->whereNotNull('sale_return_details.cost_effective_at')
            ->where(function ($q) {
                $q->whereNull('sale_return_details.commercial_quantity_also_reduced')
                    ->orWhere('sale_return_details.commercial_quantity_also_reduced', false);
            })
            ->selectRaw('sales.id as sale_id, SUM(COALESCE(sale_return_details.cost_total_snapshot, 0)) as returned_hpp')
            ->groupBy('sales.id');

        $replacementAgg = DB::table('dispatch_details')
            ->whereNotNull('replacement_of_dispatch_detail_id')
            ->whereNotNull('replacement_cost_snapshot_at')
            ->selectRaw('sale_id, SUM(COALESCE(replacement_cost_total_snapshot, 0)) as replacement_hpp')
            ->groupBy('sale_id');

        return DB::query()
            ->fromSub($salesScope, 'scoped_sales')
            ->leftJoinSub($parentAgg, 'parent', 'parent.sale_id', '=', 'scoped_sales.id')
            ->leftJoinSub($componentAgg, 'component', 'component.sale_id', '=', 'scoped_sales.id')
            ->leftJoinSub($returnAgg, 'returns', 'returns.sale_id', '=', 'scoped_sales.id')
            ->leftJoinSub($replacementAgg, 'replacement', 'replacement.sale_id', '=', 'scoped_sales.id')
            ->selectRaw('
                scoped_sales.id as sale_id,
                COALESCE(parent.dpp, 0) as dpp,
                (COALESCE(parent.parent_hpp, 0) + COALESCE(component.component_hpp, 0)
                    - COALESCE(returns.returned_hpp, 0) + COALESCE(replacement.replacement_hpp, 0)) as net_hpp
            ');
    }
}
