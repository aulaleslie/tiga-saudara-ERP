<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\EffectiveSaleReportingDate;
use App\Services\Reports\Concerns\FulfilledTransactionEligibility;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sale\Services\SalesCostSnapshotService;

class SaleByProductReportQueryService
{
    public const ROLE_DIRECT = 'direct';
    public const ROLE_COMPONENT = 'component';

    public const ROLE_LABELS = [
        self::ROLE_DIRECT => 'Penjualan langsung / paket',
        self::ROLE_COMPONENT => 'Komponen paket',
    ];

    private const STREAM_CHUNK_SIZE = 500;

    private SaleByProductCostSnapshotEvaluator $costEvaluator;
    private SaleByProductCategoryTreeResolver $categoryTreeResolver;

    public function __construct(
        ?SaleByProductCostSnapshotEvaluator $costEvaluator = null,
        ?SaleByProductCategoryTreeResolver $categoryTreeResolver = null
    ) {
        $this->costEvaluator = $costEvaluator ?? new SaleByProductCostSnapshotEvaluator();
        $this->categoryTreeResolver = $categoryTreeResolver ?? new SaleByProductCategoryTreeResolver();
    }

    /**
     * Build the normalized, bundle-aware, cost-evaluated dataset and grand totals
     * using bounded-memory chunked streaming and sale-scoped SQL sibling pre-aggregation.
     *
     * @return array{
     *     rows: Collection<int, object>,
     *     totals: array{
     *         sold_value: float,
     *         sold_value_partial: bool,
     *         total_cost: float,
     *         total_cost_partial: bool,
     *         row_count: int
     *     }
     * }
     */
    public function getDataset(SaleByProductReportFilterData $filter): array
    {
        $scopeSettingIds = !empty($filter->scopeSettingIds)
            ? $filter->scopeSettingIds
            : [(int) session('setting_id')];

        $expandedCategoryIds = !empty($filter->categoryIds)
            ? $this->categoryTreeResolver->expandWithDescendants($filter->categoryIds)
            : [];

        $hasPosTable = DB::getSchemaBuilder()->hasTable('pos_checkout_sales');
        $hasBundleTable = DB::getSchemaBuilder()->hasTable('sale_bundle_items');

        // Stream eligible sale_details and sale_bundle_items in bounded chunks (<= 500 rows) directly
        // into product/unit/role aggregate buckets. Sibling bundle aggregates are fetched per chunk
        // for POS-linked parent IDs only, keeping both parameter counts and memory strictly bounded.
        $groups = [];

        $this->buildEligibleSaleDetailsQuery($filter, $scopeSettingIds, $expandedCategoryIds, $hasPosTable)
            ->chunkById(self::STREAM_CHUNK_SIZE, function (Collection $chunk) use (&$groups, $hasBundleTable): void {
                $posDetailIds = $hasBundleTable
                    ? $chunk->filter(fn ($d) => (bool) $d->is_pos_sale)->pluck('detail_id')->map('intval')->unique()->values()->all()
                    : [];
                $siblingsByDetailId = $this->fetchSiblingBundleAggregatesForDetailIds($posDetailIds);

                foreach ($chunk as $detail) {
                    $detailId = (int) $detail->detail_id;
                    $isPos = (bool) $detail->is_pos_sale;
                    $isTaxIncluded = (bool) $detail->is_tax_included;
                    $qty = (float) $detail->quantity;
                    $parentGrossTaxExcl = $this->taxExclusiveAmount(
                        (float) $detail->sub_total,
                        (float) ($detail->product_tax_amount ?? 0),
                        $isTaxIncluded
                    );

                    $siblingAgg = $siblingsByDetailId[$detailId] ?? null;
                    $residualRevenue = $parentGrossTaxExcl;

                    if ($isPos && $siblingAgg !== null && $siblingAgg['all_children_valid_pos']) {
                        $candidateResidual = $this->subtractDecimal($parentGrossTaxExcl, $siblingAgg['children_tax_excl_sum']);
                        if ($candidateResidual >= -0.01) {
                            $residualRevenue = abs($candidateResidual) < 0.005 ? 0.0 : $candidateResidual;
                        }
                    }

                    // Placeholder parent not physically fulfilled by group with zero quantity and zero residual
                    // contributes no phantom stock quantity or cost.
                    $source = trim((string) ($detail->cost_snapshot_source ?? ''));
                    if (
                        $source === SalesCostSnapshotService::SOURCE_NOT_FULFILLED_BY_GROUP
                        && abs($qty) < 0.000001
                        && abs($residualRevenue) < 0.005
                    ) {
                        continue;
                    }

                    $costEval = $this->costEvaluator->evaluate([
                        'quantity' => $qty,
                        'cost_unit_snapshot' => $detail->cost_unit_snapshot,
                        'cost_total_snapshot' => $detail->cost_total_snapshot,
                        'cost_snapshot_source' => $detail->cost_snapshot_source,
                        'cost_snapshot_at' => $detail->cost_snapshot_at,
                        'sale_status' => $detail->sale_status,
                    ]);

                    $this->accumulateIntoGroup($groups, [
                        'product_id' => $detail->product_id !== null ? (int) $detail->product_id : null,
                        'product_code' => (string) ($detail->product_code ?? ''),
                        'product_name' => (string) ($detail->product_name ?? ''),
                        'unit_name' => (string) ($detail->unit_name ?? '-'),
                        'sale_role' => self::ROLE_DIRECT,
                        'quantity' => $qty,
                        'revenue_status' => 'known',
                        'revenue_amount' => $residualRevenue,
                        'cost_complete' => $costEval['complete'],
                        'known_cost' => $costEval['known_cost'],
                    ]);
                }
            }, 'sale_details.id', 'detail_id');

        if ($hasBundleTable) {
            $this->buildEligibleBundleItemsQuery($filter, $scopeSettingIds, $expandedCategoryIds, $hasPosTable)
                ->chunkById(self::STREAM_CHUNK_SIZE, function (Collection $chunk) use (&$groups): void {
                    $posParentDetailIds = $chunk
                        ->filter(fn ($i) => (bool) $i->is_pos_sale && $i->sale_detail_id !== null)
                        ->pluck('sale_detail_id')
                        ->map('intval')
                        ->unique()
                        ->values()
                        ->all();
                    $siblingsByDetailId = $this->fetchSiblingBundleAggregatesForDetailIds($posParentDetailIds);

                    foreach ($chunk as $item) {
                        $isPos = (bool) $item->is_pos_sale;
                        $isTaxIncluded = (bool) $item->is_tax_included;
                        $qty = (float) $item->quantity;

                        $revClassification = $this->classifyBundleItemRevenue($item, $isPos, $isTaxIncluded, $siblingsByDetailId);

                        $costEval = $this->costEvaluator->evaluate([
                            'quantity' => $qty,
                            'cost_unit_snapshot' => $item->cost_unit_snapshot,
                            'cost_total_snapshot' => $item->cost_total_snapshot,
                            'cost_snapshot_source' => $item->cost_snapshot_source,
                            'cost_snapshot_at' => $item->cost_snapshot_at,
                            'sale_status' => $item->sale_status,
                        ]);

                        $this->accumulateIntoGroup($groups, [
                            'product_id' => $item->product_id !== null ? (int) $item->product_id : null,
                            'product_code' => (string) ($item->product_code ?? ''),
                            'product_name' => (string) ($item->product_name ?? ''),
                            'unit_name' => (string) ($item->unit_name ?? '-'),
                            'sale_role' => self::ROLE_COMPONENT,
                            'quantity' => $qty,
                            'revenue_status' => $revClassification['status'],
                            'revenue_amount' => $revClassification['amount'],
                            'cost_complete' => $costEval['complete'],
                            'known_cost' => $costEval['known_cost'],
                        ]);
                    }
                }, 'sale_bundle_items.id', 'bundle_item_id');
        }

        // 3. Finalize aggregated rows and grand totals.
        $rows = [];
        $grandSoldValue = 0.0;
        $grandSoldValuePartial = false;
        $grandTotalCost = 0.0;
        $grandTotalCostPartial = false;

        foreach ($groups as $g) {
            $qty = (float) $g['sold_quantity'];

            if ($g['has_known_revenue'] && !$g['has_included_revenue'] && !$g['has_unavailable_revenue']) {
                $soldValueStatus = 'complete';
                $soldValue = round($g['known_revenue_sum'], 2);
                $avgSalesValue = $qty > 0 ? ($g['known_revenue_sum'] / $qty) : 0.0;
                $grandSoldValue += $g['known_revenue_sum'];
            } elseif ($g['has_known_revenue']) {
                $soldValueStatus = 'partial';
                $soldValue = round($g['known_revenue_sum'], 2);
                $avgSalesValue = null;
                $grandSoldValue += $g['known_revenue_sum'];
                $grandSoldValuePartial = true;
            } elseif ($g['has_included_revenue'] && !$g['has_unavailable_revenue']) {
                $soldValueStatus = 'included';
                $soldValue = null;
                $avgSalesValue = null;
            } else {
                $soldValueStatus = 'unavailable';
                $soldValue = null;
                $avgSalesValue = null;
                $grandSoldValuePartial = true;
            }

            if (!$g['has_incomplete_cost'] && $g['has_known_cost']) {
                $costStatus = 'complete';
                $totalCost = round($g['known_cost_sum'], 2);
                $avgCost = $qty > 0 ? ($g['known_cost_sum'] / $qty) : 0.0;
                $grandTotalCost += $g['known_cost_sum'];
            } elseif ($g['has_known_cost']) {
                $costStatus = 'partial';
                $totalCost = round($g['known_cost_sum'], 2);
                $avgCost = null;
                $grandTotalCost += $g['known_cost_sum'];
                $grandTotalCostPartial = true;
            } else {
                $costStatus = 'missing';
                $totalCost = null;
                $avgCost = null;
                $grandTotalCostPartial = true;
            }

            $rows[] = (object) [
                'product_id' => $g['product_id'],
                'product_code' => $g['product_code'],
                'product_name' => $g['product_name'],
                'unit_name' => $g['unit_name'],
                'sale_role' => $g['sale_role'],
                'sale_role_label' => $g['sale_role_label'],
                'sold_quantity' => $qty,
                'sold_value' => $soldValue,
                'sold_value_status' => $soldValueStatus,
                'average_sales_value' => $avgSalesValue,
                'total_cost' => $totalCost,
                'cost_status' => $costStatus,
                'average_cost' => $avgCost,
            ];
        }

        $sortedRows = $this->sortRows(collect($rows), $filter->sortField, $filter->sortDirection);

        return [
            'rows' => $sortedRows,
            'totals' => [
                'sold_value' => round($grandSoldValue, 2),
                'sold_value_partial' => $grandSoldValuePartial,
                'total_cost' => round($grandTotalCost, 2),
                'total_cost_partial' => $grandTotalCostPartial,
                'row_count' => $sortedRows->count(),
            ],
        ];
    }

    /**
     * Paginate the normalized report dataset (reusing a precomputed dataset when available).
     */
    public function paginate(
        SaleByProductReportFilterData $filter,
        int $perPage = 15,
        ?int $page = null,
        ?array $precomputedDataset = null
    ): array {
        $dataset = $precomputedDataset ?? $this->getDataset($filter);
        $allRows = $dataset['rows'];
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
            'totals' => $dataset['totals'],
        ];
    }

    /**
     * Calculate grand totals for the given filter.
     */
    public function calculateGrandTotal(SaleByProductReportFilterData $filter): array
    {
        return $this->getDataset($filter)['totals'];
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

    private function accumulateIntoGroup(array &$groups, array $line): void
    {
        $key = implode('|', [
            $line['product_id'] ?? 'null',
            $line['product_code'],
            $line['product_name'],
            $line['unit_name'],
            $line['sale_role'],
        ]);

        if (!isset($groups[$key])) {
            $groups[$key] = [
                'product_id' => $line['product_id'],
                'product_code' => $line['product_code'],
                'product_name' => $line['product_name'],
                'unit_name' => $line['unit_name'],
                'sale_role' => $line['sale_role'],
                'sale_role_label' => self::ROLE_LABELS[$line['sale_role']] ?? $line['sale_role'],
                'sold_quantity' => 0.0,
                'known_revenue_sum' => 0.0,
                'has_known_revenue' => false,
                'has_included_revenue' => false,
                'has_unavailable_revenue' => false,
                'known_cost_sum' => 0.0,
                'has_known_cost' => false,
                'has_incomplete_cost' => false,
            ];
        }

        $g = &$groups[$key];
        $g['sold_quantity'] += $line['quantity'];

        if ($line['revenue_status'] === 'known') {
            $g['has_known_revenue'] = true;
            $g['known_revenue_sum'] += (float) $line['revenue_amount'];
        } elseif ($line['revenue_status'] === 'included') {
            $g['has_included_revenue'] = true;
        } else {
            $g['has_unavailable_revenue'] = true;
        }

        if ($line['cost_complete']) {
            $g['has_known_cost'] = true;
            $g['known_cost_sum'] += (float) $line['known_cost'];
        } else {
            $g['has_incomplete_cost'] = true;
        }
    }

    private function buildEligibleSaleDetailsQuery(
        SaleByProductReportFilterData $filter,
        array $scopeSettingIds,
        array $expandedCategoryIds,
        bool $hasPosTable
    ): Builder {
        $posExpr = $hasPosTable
            ? 'EXISTS (SELECT 1 FROM pos_checkout_sales WHERE pos_checkout_sales.sale_id = sales.id)'
            : '0';

        $query = DB::table('sale_details')
            ->join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->leftJoin('products', 'sale_details.product_id', '=', 'products.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->leftJoin('units as base_units', 'products.base_unit_id', '=', 'base_units.id')
            ->select(
                'sale_details.id as detail_id',
                'sale_details.sale_id',
                'sale_details.product_id',
                DB::raw("COALESCE(products.product_code, sale_details.product_code, '') as product_code"),
                DB::raw("COALESCE(products.product_name, sale_details.product_name, '') as product_name"),
                DB::raw("COALESCE(units.short_name, base_units.short_name, products.product_unit, '-') as unit_name"),
                'sale_details.quantity',
                'sale_details.sub_total',
                'sale_details.product_tax_amount',
                'sale_details.cost_unit_snapshot',
                'sale_details.cost_total_snapshot',
                'sale_details.cost_snapshot_source',
                'sale_details.cost_snapshot_at',
                'sales.is_tax_included',
                'sales.status as sale_status',
                DB::raw("{$posExpr} as is_pos_sale")
            )
            ->whereIn('sales.setting_id', $scopeSettingIds)
            ->whereRaw(EffectiveSaleReportingDate::sqlExpression() . ' >= ?', [$filter->startDate])
            ->whereRaw(EffectiveSaleReportingDate::sqlExpression() . ' <= ?', [$filter->endDate]);

        FulfilledTransactionEligibility::applyToSaleQuery($query, 'sales');
        $this->applySaleLevelFilters($query, $filter);
        $this->applyCatalogFilters($query, $filter, $expandedCategoryIds, 'sale_details.product_id');

        return $query;
    }

    private function buildEligibleBundleItemsQuery(
        SaleByProductReportFilterData $filter,
        array $scopeSettingIds,
        array $expandedCategoryIds,
        bool $hasPosTable
    ): Builder {
        $posExpr = $hasPosTable
            ? 'EXISTS (SELECT 1 FROM pos_checkout_sales WHERE pos_checkout_sales.sale_id = sales.id)'
            : '0';

        $query = DB::table('sale_bundle_items')
            ->join('sales', 'sale_bundle_items.sale_id', '=', 'sales.id')
            ->leftJoin('sale_details as parent_detail', 'sale_bundle_items.sale_detail_id', '=', 'parent_detail.id')
            ->leftJoin('products', 'sale_bundle_items.product_id', '=', 'products.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->leftJoin('units as base_units', 'products.base_unit_id', '=', 'base_units.id')
            ->select(
                'sale_bundle_items.id as bundle_item_id',
                'sale_bundle_items.sale_id',
                'sale_bundle_items.sale_detail_id',
                'sale_bundle_items.line_group_key',
                'sale_bundle_items.product_id',
                DB::raw("COALESCE(products.product_code, '') as product_code"),
                DB::raw("COALESCE(products.product_name, sale_bundle_items.name, '') as product_name"),
                DB::raw("COALESCE(units.short_name, base_units.short_name, products.product_unit, '-') as unit_name"),
                'sale_bundle_items.quantity',
                'sale_bundle_items.price',
                'sale_bundle_items.informational_item_price',
                'sale_bundle_items.sub_total',
                'sale_bundle_items.tax_amount',
                'sale_bundle_items.cost_unit_snapshot',
                'sale_bundle_items.cost_total_snapshot',
                'sale_bundle_items.cost_snapshot_source',
                'sale_bundle_items.cost_snapshot_at',
                'parent_detail.sub_total as parent_sub_total',
                'parent_detail.product_tax_amount as parent_tax_amount',
                'sales.is_tax_included',
                'sales.status as sale_status',
                DB::raw("{$posExpr} as is_pos_sale")
            )
            ->whereIn('sales.setting_id', $scopeSettingIds)
            ->whereRaw(EffectiveSaleReportingDate::sqlExpression() . ' >= ?', [$filter->startDate])
            ->whereRaw(EffectiveSaleReportingDate::sqlExpression() . ' <= ?', [$filter->endDate]);

        FulfilledTransactionEligibility::applyToSaleQuery($query, 'sales');
        $this->applySaleLevelFilters($query, $filter);
        $this->applyCatalogFilters($query, $filter, $expandedCategoryIds, 'sale_bundle_items.product_id');

        return $query;
    }

    /**
     * Pre-aggregate ALL sibling bundle items per sale_detail_id in SQL for a bounded chunk
     * of parent detail IDs (<= STREAM_CHUNK_SIZE), ignoring catalog filters so sibling
     * decomposition never shifts with catalog visibility.
     *
     * @param  array<int>  $detailIds
     * @return array<int, array{children_tax_excl_sum: float, all_children_valid_pos: bool, parent_gross_tax_excl: float}>
     */
    private function fetchSiblingBundleAggregatesForDetailIds(array $detailIds): array
    {
        if (empty($detailIds)) {
            return [];
        }

        $rows = DB::table('sale_bundle_items')
            ->join('sales', 'sale_bundle_items.sale_id', '=', 'sales.id')
            ->join('sale_details', 'sale_bundle_items.sale_detail_id', '=', 'sale_details.id')
            ->whereIn('sale_bundle_items.sale_detail_id', $detailIds)
            ->select(
                'sale_bundle_items.sale_detail_id',
                DB::raw('SUM(CASE WHEN sales.is_tax_included = 1 THEN COALESCE(sale_bundle_items.sub_total, 0) - COALESCE(sale_bundle_items.tax_amount, 0) ELSE COALESCE(sale_bundle_items.sub_total, 0) END) as children_tax_excl_sum'),
                DB::raw('MAX(CASE WHEN sale_bundle_items.sub_total IS NULL OR sale_bundle_items.sub_total < 0 OR (sales.is_tax_included = 1 AND COALESCE(sale_bundle_items.tax_amount, 0) > sale_bundle_items.sub_total + 0.01) THEN 1 ELSE 0 END) as has_invalid_child'),
                DB::raw('MAX(CASE WHEN sales.is_tax_included = 1 THEN sale_details.sub_total - COALESCE(sale_details.product_tax_amount, 0) ELSE sale_details.sub_total END) as parent_gross_tax_excl')
            )
            ->groupBy('sale_bundle_items.sale_detail_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $dId = (int) $row->sale_detail_id;
            $result[$dId] = [
                'children_tax_excl_sum' => (float) ($row->children_tax_excl_sum ?? 0),
                'all_children_valid_pos' => ((int) ($row->has_invalid_child ?? 0)) === 0,
                'parent_gross_tax_excl' => (float) ($row->parent_gross_tax_excl ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Classify a bundle item's revenue attribution.
     *
     * @return array{status: 'known'|'included'|'unavailable', amount: ?float}
     */
    private function classifyBundleItemRevenue(object $item, bool $isPos, bool $isTaxIncluded, array $siblingsByDetailId): array
    {
        if ($item->sale_detail_id === null) {
            if ($item->sub_total === null || (float) $item->sub_total < 0) {
                return ['status' => 'unavailable', 'amount' => null];
            }

            $subTotal = (float) $item->sub_total;
            $tax = (float) ($item->tax_amount ?? 0);

            if ($isTaxIncluded && $tax > $subTotal + 0.01) {
                return ['status' => 'unavailable', 'amount' => null];
            }

            return [
                'status' => 'known',
                'amount' => $this->taxExclusiveAmount($subTotal, $tax, $isTaxIncluded),
            ];
        }

        if ($isPos) {
            $parentId = (int) $item->sale_detail_id;
            $siblingAgg = $siblingsByDetailId[$parentId] ?? null;

            if ($siblingAgg === null || !$siblingAgg['all_children_valid_pos']) {
                return ['status' => 'unavailable', 'amount' => null];
            }

            $residual = $this->subtractDecimal($siblingAgg['parent_gross_tax_excl'], $siblingAgg['children_tax_excl_sum']);
            if ($residual < -0.01) {
                return ['status' => 'unavailable', 'amount' => null];
            }

            $subTotal = (float) $item->sub_total;
            $tax = (float) ($item->tax_amount ?? 0);

            return [
                'status' => 'known',
                'amount' => $this->taxExclusiveAmount($subTotal, $tax, $isTaxIncluded),
            ];
        }

        $subTotal = $item->sub_total !== null ? (float) $item->sub_total : 0.0;
        $price = $item->price !== null ? (float) $item->price : 0.0;

        if (abs($subTotal) < 0.005 && abs($price) < 0.005) {
            return ['status' => 'included', 'amount' => null];
        }

        return ['status' => 'unavailable', 'amount' => null];
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

    private function sortRows(Collection $rows, string $sortField, string $sortDirection): Collection
    {
        $asc = strtolower($sortDirection) === 'asc';
        $allowedFields = ['product_name', 'product_code', 'sold_quantity', 'sold_value', 'average_sales_value'];
        $field = in_array($sortField, $allowedFields, true) ? $sortField : 'product_name';

        return $rows->sort(function (object $a, object $b) use ($field, $asc) {
            $valA = $a->{$field};
            $valB = $b->{$field};

            if ($valA === null && $valB !== null) {
                return 1;
            }
            if ($valA !== null && $valB === null) {
                return -1;
            }

            if (in_array($field, ['product_name', 'product_code'], true)) {
                $cmp = strcasecmp((string) $valA, (string) $valB);
            } else {
                $cmp = ((float) $valA) <=> ((float) $valB);
            }

            if ($cmp !== 0) {
                return $asc ? $cmp : -$cmp;
            }

            $idCmp = ($a->product_id ?? 0) <=> ($b->product_id ?? 0);
            if ($idCmp !== 0) {
                return $idCmp;
            }

            $roleCmp = ($a->sale_role === self::ROLE_DIRECT ? 0 : 1) <=> ($b->sale_role === self::ROLE_DIRECT ? 0 : 1);
            if ($roleCmp !== 0) {
                return $roleCmp;
            }

            return strcmp((string) $a->unit_name, (string) $b->unit_name);
        })->values();
    }

    private function taxExclusiveAmount(float $subTotal, float $taxAmount, bool $isTaxIncluded): float
    {
        if (!$isTaxIncluded) {
            return $subTotal;
        }

        return $this->subtractDecimal($subTotal, $taxAmount);
    }

    private function subtractDecimal(float $a, float $b): float
    {
        if (function_exists('bcsub')) {
            return (float) bcsub(number_format($a, 6, '.', ''), number_format($b, 6, '.', ''), 6);
        }

        return $a - $b;
    }
}
