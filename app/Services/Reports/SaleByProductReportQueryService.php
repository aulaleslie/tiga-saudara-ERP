<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\EffectiveSaleReportingDate;
use App\Services\Reports\Concerns\FulfilledTransactionEligibility;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SaleByProductReportQueryService
{
    private SaleByProductCategoryTreeResolver $categoryTreeResolver;
    private ?bool $hasPosTableCache = null;
    private ?bool $hasBundleTableCache = null;

    public function __construct(
        ?SaleByProductCategoryTreeResolver $categoryTreeResolver = null
    ) {
        $this->categoryTreeResolver = $categoryTreeResolver ?? new SaleByProductCategoryTreeResolver();
    }

    /**
     * Build the SQL-aggregated, bundle-aware, sales-only dataset and grand totals.
     *
     * @return array{
     *     rows: Collection<int, object>,
     *     totals: array{
     *         sold_value: float,
     *         row_count: int
     *     }
     * }
     */
    public function getDataset(SaleByProductReportFilterData $filter): array
    {
        $query = $this->buildAggregatedQuery($filter);
        $this->applyAggregatedSort($query, $filter->sortField, $filter->sortDirection);

        $rawRows = $query->get();

        $grandSoldValue = 0.0;
        $rows = $rawRows->map(function (object $row) use (&$grandSoldValue): object {
            $rawVal = (float) ($row->raw_sold_value ?? $row->sold_value ?? 0);
            $grandSoldValue += $rawVal;

            return $this->normalizeRow($row);
        });

        return [
            'rows' => $rows,
            'totals' => [
                'sold_value' => round($grandSoldValue, 2),
                'row_count' => $rows->count(),
            ],
        ];
    }

    /**
     * Paginate grouped product rows directly in SQL and compute grand totals across
     * the complete filtered result in SQL.
     *
     * @return array{
     *     paginator: LengthAwarePaginator,
     *     totals: array{
     *         sold_value: float,
     *         row_count: int
     *     }
     * }
     */
    public function paginate(
        SaleByProductReportFilterData $filter,
        int $perPage = 15,
        ?int $page = null,
        ?array $precomputedDataset = null
    ): array {
        if ($precomputedDataset !== null) {
            $allRows = $precomputedDataset['rows'];
            $currentPage = $page ?? LengthAwarePaginator::resolveCurrentPage();
            $slice = $allRows->forPage($currentPage, $perPage)->values();

            $paginator = new LengthAwarePaginator(
                $slice,
                $allRows->count(),
                $perPage,
                $currentPage,
                ['path' => LengthAwarePaginator::resolveCurrentPath()]
            );

            return [
                'paginator' => $paginator,
                'totals' => $precomputedDataset['totals'],
            ];
        }

        $currentPage = max(1, (int) ($page ?? LengthAwarePaginator::resolveCurrentPage()));
        $totals = $this->calculateGrandTotal($filter);

        $slice = collect();
        if ($totals['row_count'] > 0) {
            $pageQuery = $this->buildAggregatedQuery($filter);
            $this->applyAggregatedSort($pageQuery, $filter->sortField, $filter->sortDirection);
            $slice = $pageQuery
                ->forPage($currentPage, $perPage)
                ->get()
                ->map(fn (object $row) => $this->normalizeRow($row));
        }

        $paginator = new LengthAwarePaginator(
            $slice,
            $totals['row_count'],
            $perPage,
            $currentPage,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );

        return [
            'paginator' => $paginator,
            'totals' => $totals,
        ];
    }

    /**
     * Calculate grand totals (row_count and sold_value) across the complete filtered result in SQL.
     *
     * @return array{sold_value: float, row_count: int}
     */
    public function calculateGrandTotal(SaleByProductReportFilterData $filter): array
    {
        $aggQuery = $this->buildAggregatedQuery($filter);

        $result = DB::query()
            ->fromSub($aggQuery, 'agg')
            ->selectRaw('COUNT(*) as row_count, ROUND(COALESCE(SUM(agg.raw_sold_value), 0), 2) as sold_value')
            ->first();

        return [
            'sold_value' => round((float) ($result->sold_value ?? 0), 2),
            'row_count' => (int) ($result->row_count ?? 0),
        ];
    }

    /**
     * Build the SQL-aggregated query (product/unit rows with combined direct + component totals).
     */
    public function buildAggregatedQuery(SaleByProductReportFilterData $filter): Builder
    {
        $scopeSettingIds = !empty($filter->scopeSettingIds)
            ? $filter->scopeSettingIds
            : [(int) session('setting_id')];

        $expandedCategoryIds = !empty($filter->categoryIds)
            ? $this->categoryTreeResolver->expandWithDescendants($filter->categoryIds)
            : [];

        $hasPosTable = $this->hasPosTableCache ??= DB::getSchemaBuilder()->hasTable('pos_checkout_sales');
        $hasBundleTable = $this->hasBundleTableCache ??= DB::getSchemaBuilder()->hasTable('sale_bundle_items');

        $detailsQuery = $this->buildDetailsProjectionQuery(
            $filter,
            $scopeSettingIds,
            $expandedCategoryIds,
            $hasPosTable,
            $hasBundleTable
        );

        if ($hasBundleTable) {
            $bundleQuery = $this->buildBundleItemsProjectionQuery(
                $filter,
                $scopeSettingIds,
                $expandedCategoryIds,
                $hasPosTable
            );
            $unionQuery = $detailsQuery->unionAll($bundleQuery);
        } else {
            $unionQuery = $detailsQuery;
        }

        return DB::query()
            ->fromSub($unionQuery, 'u')
            ->selectRaw("
                u.product_id,
                MAX(u.product_code) as product_code,
                MAX(u.product_name) as product_name,
                u.unit_name,
                SUM(u.line_qty) as sold_quantity,
                SUM(u.line_revenue) as raw_sold_value,
                ROUND(SUM(u.line_revenue), 2) as sold_value,
                CASE WHEN SUM(u.line_qty) > 0 THEN (SUM(u.line_revenue) * 1.0) / SUM(u.line_qty) ELSE 0 END as average_sales_value
            ")
            ->groupByRaw("
                u.product_id,
                u.unit_name,
                CASE WHEN u.product_id IS NULL THEN u.product_code ELSE '' END,
                CASE WHEN u.product_id IS NULL THEN u.product_name ELSE '' END
            ");
    }

    /**
     * Legacy SQL builder kept for backward compatibility with direct SQL callers.
     */
    public function build(SaleByProductReportFilterData $filter): Builder
    {
        $scopeSettingIds = !empty($filter->scopeSettingIds)
            ? $filter->scopeSettingIds
            : [(int) session('setting_id')];

        $expandedCategoryIds = !empty($filter->categoryIds)
            ? $this->categoryTreeResolver->expandWithDescendants($filter->categoryIds)
            : [];

        $query = DB::table('sale_details')
            ->join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->leftJoin('products', 'sale_details.product_id', '=', 'products.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->leftJoin('units as base_units', 'products.base_unit_id', '=', 'base_units.id')
            ->select(
                'sale_details.product_id',
                DB::raw("COALESCE(products.product_code, sale_details.product_code, '') as product_code"),
                DB::raw("COALESCE(products.product_name, sale_details.product_name, '') as product_name"),
                DB::raw("COALESCE(units.short_name, base_units.short_name, products.product_unit, '-') as unit_name"),
                DB::raw('SUM(sale_details.quantity) as sold_quantity'),
                DB::raw('SUM(CASE WHEN sales.is_tax_included = 1 THEN sale_details.sub_total - COALESCE(sale_details.product_tax_amount, 0) ELSE sale_details.sub_total END) as sold_value'),
                DB::raw('CASE WHEN SUM(sale_details.quantity) > 0 THEN SUM(CASE WHEN sales.is_tax_included = 1 THEN sale_details.sub_total - COALESCE(sale_details.product_tax_amount, 0) ELSE sale_details.sub_total END) / SUM(sale_details.quantity) ELSE 0 END as average_sales_value')
            )
            ->whereIn('sales.setting_id', $scopeSettingIds)
            ->whereRaw(EffectiveSaleReportingDate::sqlExpression() . ' >= ?', [$filter->startDate])
            ->whereRaw(EffectiveSaleReportingDate::sqlExpression() . ' <= ?', [$filter->endDate]);

        FulfilledTransactionEligibility::applyToSaleQuery($query, 'sales');

        $this->applySaleLevelFilters($query, $filter);
        $this->applyCatalogFilters($query, $filter, $expandedCategoryIds, 'sale_details.product_id');

        $query->groupBy('sale_details.product_id', 'sale_details.product_code', 'sale_details.product_name', 'unit_name');

        return $query;
    }

    public function applySort(Builder $query, string $sortField, string $sortDirection): void
    {
        $direction = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        $sortMap = [
            'product_name' => 'product_name',
            'product_code' => 'product_code',
            'sold_quantity' => 'sold_quantity',
            'sold_value' => 'sold_value',
            'average_sales_value' => 'average_sales_value',
        ];

        $query->orderBy($sortMap[$sortField] ?? $sortMap['product_name'], $direction);
        $query->orderBy('sale_details.product_id', 'asc');
    }

    private function applyAggregatedSort(Builder $query, string $sortField, string $sortDirection): void
    {
        $direction = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';
        $allowedFields = ['product_name', 'product_code', 'sold_quantity', 'sold_value', 'average_sales_value'];
        $field = in_array($sortField, $allowedFields, true) ? $sortField : 'product_name';

        if (in_array($field, ['product_name', 'product_code'], true)) {
            $query->orderByRaw("LOWER(MAX(u.{$field})) {$direction}");
        } else {
            $query->orderBy($field, $direction);
        }

        $query->orderByRaw('COALESCE(u.product_id, 0) asc');
        $query->orderBy('u.unit_name', 'asc');
    }

    /**
     * Pre-aggregate ALL sibling bundle items per sale_detail_id for eligible sales in the
     * selected scope/period, ignoring catalog filters so parent residual revenue is
     * completely independent of catalog filter visibility.
     */
    private function buildSiblingBundleSubquery(
        SaleByProductReportFilterData $filter,
        array $scopeSettingIds
    ): Builder {
        $query = DB::table('sale_bundle_items as sbi')
            ->join('sales as s', 'sbi.sale_id', '=', 's.id')
            ->whereNotNull('sbi.sale_detail_id')
            ->whereIn('s.setting_id', $scopeSettingIds)
            ->whereRaw(EffectiveSaleReportingDate::sqlExpression('s') . ' >= ?', [$filter->startDate])
            ->whereRaw(EffectiveSaleReportingDate::sqlExpression('s') . ' <= ?', [$filter->endDate])
            ->select(
                'sbi.sale_detail_id',
                DB::raw('SUM(CASE WHEN s.is_tax_included = 1 THEN COALESCE(sbi.sub_total, 0) - COALESCE(sbi.tax_amount, 0) ELSE COALESCE(sbi.sub_total, 0) END) as children_tax_excl_sum'),
                DB::raw('MAX(CASE WHEN sbi.sub_total IS NULL OR sbi.sub_total < 0 OR (s.is_tax_included = 1 AND COALESCE(sbi.tax_amount, 0) > sbi.sub_total + 0.01) THEN 1 ELSE 0 END) as has_invalid_child')
            )
            ->groupBy('sbi.sale_detail_id');

        FulfilledTransactionEligibility::applyToSaleQuery($query, 's');

        return $query;
    }

    private function buildDetailsProjectionQuery(
        SaleByProductReportFilterData $filter,
        array $scopeSettingIds,
        array $expandedCategoryIds,
        bool $hasPosTable,
        bool $hasBundleTable
    ): Builder {
        $parentGrossExpr = 'CASE WHEN sales.is_tax_included = 1 THEN sale_details.sub_total - COALESCE(sale_details.product_tax_amount, 0) ELSE sale_details.sub_total END';

        $query = DB::table('sale_details')
            ->join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->leftJoin('products', 'sale_details.product_id', '=', 'products.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->leftJoin('units as base_units', 'products.base_unit_id', '=', 'base_units.id');

        if ($hasBundleTable && $hasPosTable) {
            $siblingSub = $this->buildSiblingBundleSubquery($filter, $scopeSettingIds);
            $query->leftJoinSub($siblingSub, 'sibling_agg', 'sibling_agg.sale_detail_id', '=', 'sale_details.id');

            $isPosExpr = 'EXISTS (SELECT 1 FROM pos_checkout_sales WHERE pos_checkout_sales.sale_id = sales.id)';
            $residualCandidateExpr = "(({$parentGrossExpr}) - COALESCE(sibling_agg.children_tax_excl_sum, 0))";
            $lineRevenueExpr = "CASE
                WHEN ({$isPosExpr})
                     AND sibling_agg.sale_detail_id IS NOT NULL
                     AND sibling_agg.has_invalid_child = 0
                     AND {$residualCandidateExpr} >= -0.01
                THEN CASE WHEN ABS({$residualCandidateExpr}) < 0.005 THEN 0.0 ELSE {$residualCandidateExpr} END
                ELSE ({$parentGrossExpr})
            END";

            // Exclude component-only POS placeholder parents (zero quantity and zero residual revenue).
            $query->whereRaw("NOT (({$isPosExpr}) AND sibling_agg.sale_detail_id IS NOT NULL AND ABS(sale_details.quantity) < 0.000001 AND ABS({$lineRevenueExpr}) < 0.005)");
        } else {
            $lineRevenueExpr = "({$parentGrossExpr})";
        }

        $query->select(
            'sale_details.product_id',
            DB::raw("COALESCE(products.product_code, sale_details.product_code, '') as product_code"),
            DB::raw("COALESCE(products.product_name, sale_details.product_name, '') as product_name"),
            DB::raw("COALESCE(units.short_name, base_units.short_name, products.product_unit, '-') as unit_name"),
            DB::raw('sale_details.quantity as line_qty'),
            DB::raw("{$lineRevenueExpr} as line_revenue")
        )
            ->whereIn('sales.setting_id', $scopeSettingIds)
            ->whereRaw(EffectiveSaleReportingDate::sqlExpression() . ' >= ?', [$filter->startDate])
            ->whereRaw(EffectiveSaleReportingDate::sqlExpression() . ' <= ?', [$filter->endDate]);

        FulfilledTransactionEligibility::applyToSaleQuery($query, 'sales');
        $this->applySaleLevelFilters($query, $filter);
        $this->applyCatalogFilters($query, $filter, $expandedCategoryIds, 'sale_details.product_id');

        return $query;
    }

    private function buildBundleItemsProjectionQuery(
        SaleByProductReportFilterData $filter,
        array $scopeSettingIds,
        array $expandedCategoryIds,
        bool $hasPosTable
    ): Builder {
        $siblingSub = $this->buildSiblingBundleSubquery($filter, $scopeSettingIds);
        $isPosExpr = $hasPosTable
            ? 'EXISTS (SELECT 1 FROM pos_checkout_sales WHERE pos_checkout_sales.sale_id = sales.id)'
            : '0';

        $itemTaxExclExpr = 'CASE WHEN sales.is_tax_included = 1 THEN COALESCE(sale_bundle_items.sub_total, 0) - COALESCE(sale_bundle_items.tax_amount, 0) ELSE COALESCE(sale_bundle_items.sub_total, 0) END';
        $parentGrossExpr = 'CASE WHEN sales.is_tax_included = 1 THEN COALESCE(parent_detail.sub_total, 0) - COALESCE(parent_detail.product_tax_amount, 0) ELSE COALESCE(parent_detail.sub_total, 0) END';

        $lineRevenueExpr = "CASE
            WHEN sale_bundle_items.sale_detail_id IS NULL THEN
                CASE
                    WHEN sale_bundle_items.sub_total IS NULL
                         OR sale_bundle_items.sub_total < 0
                         OR (sales.is_tax_included = 1 AND COALESCE(sale_bundle_items.tax_amount, 0) > sale_bundle_items.sub_total + 0.01)
                    THEN 0.0
                    ELSE ({$itemTaxExclExpr})
                END
            WHEN ({$isPosExpr})
                 AND sibling_agg.sale_detail_id IS NOT NULL
                 AND sibling_agg.has_invalid_child = 0
                 AND (({$parentGrossExpr}) - COALESCE(sibling_agg.children_tax_excl_sum, 0)) >= -0.01
            THEN ({$itemTaxExclExpr})
            ELSE 0.0
        END";

        $query = DB::table('sale_bundle_items')
            ->join('sales', 'sale_bundle_items.sale_id', '=', 'sales.id')
            ->leftJoin('sale_details as parent_detail', 'sale_bundle_items.sale_detail_id', '=', 'parent_detail.id')
            ->leftJoinSub($siblingSub, 'sibling_agg', 'sibling_agg.sale_detail_id', '=', 'sale_bundle_items.sale_detail_id')
            ->leftJoin('products', 'sale_bundle_items.product_id', '=', 'products.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->leftJoin('units as base_units', 'products.base_unit_id', '=', 'base_units.id')
            ->select(
                'sale_bundle_items.product_id',
                DB::raw("COALESCE(products.product_code, '') as product_code"),
                DB::raw("COALESCE(products.product_name, sale_bundle_items.name, '') as product_name"),
                DB::raw("COALESCE(units.short_name, base_units.short_name, products.product_unit, '-') as unit_name"),
                DB::raw('sale_bundle_items.quantity as line_qty'),
                DB::raw("{$lineRevenueExpr} as line_revenue")
            )
            ->whereIn('sales.setting_id', $scopeSettingIds)
            ->whereRaw(EffectiveSaleReportingDate::sqlExpression() . ' >= ?', [$filter->startDate])
            ->whereRaw(EffectiveSaleReportingDate::sqlExpression() . ' <= ?', [$filter->endDate]);

        FulfilledTransactionEligibility::applyToSaleQuery($query, 'sales');
        $this->applySaleLevelFilters($query, $filter);
        $this->applyCatalogFilters($query, $filter, $expandedCategoryIds, 'sale_bundle_items.product_id');

        return $query;
    }

    private function applySaleLevelFilters(Builder $query, SaleByProductReportFilterData $filter): void
    {
        if (!empty($filter->customerIds)) {
            $query->whereIn('sales.customer_id', $filter->customerIds);
        }

        if (!empty($filter->tagIds)) {
            if ($filter->tagLogic === 'Mencakup semua') {
                foreach ($filter->tagIds as $tagId) {
                    $query->whereExists(function ($q) use ($tagId) {
                        $q->select(DB::raw(1))
                            ->from('taggables')
                            ->whereColumn('taggables.taggable_id', 'sales.id')
                            ->where('taggables.taggable_type', 'Modules\Sale\Entities\Sale')
                            ->where('taggables.tag_id', $tagId);
                    });
                }
            } else {
                $query->whereExists(function ($q) use ($filter) {
                    $q->select(DB::raw(1))
                        ->from('taggables')
                        ->whereColumn('taggables.taggable_id', 'sales.id')
                        ->where('taggables.taggable_type', 'Modules\Sale\Entities\Sale')
                        ->whereIn('taggables.tag_id', $filter->tagIds);
                });
            }
        }
    }

    private function applyCatalogFilters(
        Builder $query,
        SaleByProductReportFilterData $filter,
        array $expandedCategoryIds,
        string $productIdColumn
    ): void {
        if (!empty($filter->productIds)) {
            $query->whereIn($productIdColumn, $filter->productIds);
        }

        if (!empty($expandedCategoryIds)) {
            $query->whereIn('products.category_id', $expandedCategoryIds);
        }

        if (!empty($filter->brandIds)) {
            $query->whereIn('products.brand_id', $filter->brandIds);
        }
    }

    private function normalizeRow(object $row): object
    {
        return (object) [
            'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
            'product_code' => (string) ($row->product_code ?? ''),
            'product_name' => (string) ($row->product_name ?? ''),
            'unit_name' => (string) ($row->unit_name ?? '-'),
            'sold_quantity' => (float) ($row->sold_quantity ?? 0),
            'sold_value' => round((float) ($row->sold_value ?? 0), 2),
            'average_sales_value' => (float) ($row->average_sales_value ?? 0),
        ];
    }
}
