<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use App\Services\Reports\Concerns\EffectiveSaleReportingDate;

class SaleByProductReportQueryService
{
    public function build(SaleByProductReportFilterData $filter): Builder
    {
        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id');

        // 1. Sold Aggregate
        $soldQuery = DB::table('sale_details')
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
                DB::raw('0 as return_quantity'),
                DB::raw('SUM(CASE WHEN sales.is_tax_included = 1 THEN sale_details.sub_total - COALESCE(sale_details.product_tax_amount, 0) ELSE sale_details.sub_total END) as sold_value'),
                DB::raw('0 as return_value')
            )
            ->where('sales.setting_id', $scopeSettingId)
            ->whereRaw(EffectiveSaleReportingDate::sqlExpression() . ' >= ?', [$filter->startDate])
            ->whereRaw(EffectiveSaleReportingDate::sqlExpression() . ' <= ?', [$filter->endDate]);

        $this->applyFiltersToSold($soldQuery, $filter);

        $soldQuery->groupBy('sale_details.product_id', 'sale_details.product_code', 'sale_details.product_name', 'unit_name');

        // 2. Return Aggregate
        $returnQuery = DB::table('sale_return_details')
            ->join('sale_returns', 'sale_return_details.sale_return_id', '=', 'sale_returns.id')
            ->leftJoin('sales', 'sale_returns.sale_id', '=', 'sales.id')
            ->leftJoin('products', 'sale_return_details.product_id', '=', 'products.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->leftJoin('units as base_units', 'products.base_unit_id', '=', 'base_units.id')
            ->select(
                'sale_return_details.product_id',
                DB::raw("COALESCE(products.product_code, sale_return_details.product_code, '') as product_code"),
                DB::raw("COALESCE(products.product_name, sale_return_details.product_name, '') as product_name"),
                DB::raw("COALESCE(units.short_name, base_units.short_name, products.product_unit, '-') as unit_name"),
                DB::raw('0 as sold_quantity'),
                DB::raw('SUM(sale_return_details.quantity) as return_quantity'),
                DB::raw('0 as sold_value'),
                DB::raw('SUM(CASE WHEN sales.is_tax_included = 1 THEN sale_return_details.sub_total - COALESCE(sale_return_details.product_tax_amount, 0) ELSE sale_return_details.sub_total END) as return_value')
            )
            ->where('sale_returns.setting_id', $scopeSettingId)
            ->whereDate('sale_returns.date', '>=', $filter->startDate)
            ->whereDate('sale_returns.date', '<=', $filter->endDate)
            ->whereIn(DB::raw('LOWER(sale_returns.status)'), ['awaiting settlement', 'completed']);

        $this->applyFiltersToReturn($returnQuery, $filter);

        $returnQuery->groupBy('sale_return_details.product_id', 'sale_return_details.product_code', 'sale_return_details.product_name', 'unit_name');

        $combinedQuery = DB::table(DB::raw("({$soldQuery->toSql()} UNION ALL {$returnQuery->toSql()}) as combined"))
            ->mergeBindings($soldQuery)
            ->mergeBindings($returnQuery);

        $query = DB::query()->fromSub($combinedQuery, 'merged')
            ->select(
                'merged.product_id',
                'merged.product_code',
                'merged.product_name',
                'merged.unit_name',
                DB::raw('SUM(merged.sold_quantity) as sold_quantity'),
                DB::raw('SUM(merged.return_quantity) as return_quantity'),
                DB::raw('SUM(merged.sold_value) as sold_value'),
                DB::raw('SUM(merged.return_value) as return_value'),
                DB::raw('CASE WHEN SUM(merged.sold_quantity) > 0 THEN SUM(merged.sold_value) / SUM(merged.sold_quantity) ELSE 0 END as average_sales_value')
            );
        
        $query->groupBy(
            'merged.product_id',
            'merged.product_code',
            'merged.product_name',
            'merged.unit_name'
        );

        return $query;
    }

    private function applyFiltersToSold(Builder $query, SaleByProductReportFilterData $filter): void
    {
        if (!empty($filter->customerIds)) {
            $query->whereIn('sales.customer_id', $filter->customerIds);
        }

        if (!empty($filter->productIds)) {
            $query->whereIn('sale_details.product_id', $filter->productIds);
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

        if (!empty($filter->categoryIds)) {
            $query->whereExists(function ($q) use ($filter) {
                $q->select(DB::raw(1))
                  ->from('products')
                  ->whereColumn('products.id', 'sale_details.product_id');

                if ($filter->categoryLogic === 'Mencakup semua') {
                    // Category logic mencakup semua doesn't make sense for a single product unless it has multiple categories. 
                    // Products only have one category_id in this system.
                    // We'll treat 'Mencakup semua' same as 'Salah satu' for product categories since a product belongs to exactly 1 category.
                    $q->whereIn('products.category_id', $filter->categoryIds);
                } else {
                    $q->whereIn('products.category_id', $filter->categoryIds);
                }
            });
        }
    }

    private function applyFiltersToReturn(Builder $query, SaleByProductReportFilterData $filter): void
    {
        if (!empty($filter->customerIds)) {
            $query->whereIn('sale_returns.customer_id', $filter->customerIds);
        }

        if (!empty($filter->productIds)) {
            $query->whereIn('sale_return_details.product_id', $filter->productIds);
        }

        // Sale returns typically don't have tags directly applied in the same way as sales,
        // but if we need to filter by tags, we can join back to the sale, or assume tags are on sale_returns.
        // Assuming tags are not on sale_returns, we check the sale associated with the return.
        if (!empty($filter->tagIds)) {
            if ($filter->tagLogic === 'Mencakup semua') {
                foreach ($filter->tagIds as $tagId) {
                    $query->whereExists(function ($q) use ($tagId) {
                        $q->select(DB::raw(1))
                          ->from('taggables')
                          ->whereColumn('taggables.taggable_id', 'sale_returns.sale_id')
                          ->where('taggables.taggable_type', 'Modules\Sale\Entities\Sale')
                          ->where('taggables.tag_id', $tagId);
                    });
                }
            } else {
                $query->whereExists(function ($q) use ($filter) {
                    $q->select(DB::raw(1))
                      ->from('taggables')
                      ->whereColumn('taggables.taggable_id', 'sale_returns.sale_id')
                      ->where('taggables.taggable_type', 'Modules\Sale\Entities\Sale')
                      ->whereIn('taggables.tag_id', $filter->tagIds);
                });
            }
        }

        if (!empty($filter->categoryIds)) {
            $query->whereExists(function ($q) use ($filter) {
                $q->select(DB::raw(1))
                  ->from('products')
                  ->whereColumn('products.id', 'sale_return_details.product_id')
                  ->whereIn('products.category_id', $filter->categoryIds);
            });
        }
    }

    public function applySort(Builder $query, string $sortField, string $sortDirection): void
    {
        $direction = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        $sortMap = [
            'product_name' => DB::raw('merged.product_name'),
            'product_code' => DB::raw('merged.product_code'),
            'sold_quantity' => DB::raw('SUM(merged.sold_quantity)'),
            'return_quantity' => DB::raw('SUM(merged.return_quantity)'),
            'sold_value' => DB::raw('SUM(merged.sold_value)'),
            'average_sales_value' => DB::raw('CASE WHEN SUM(merged.sold_quantity) > 0 THEN SUM(merged.sold_value) / SUM(merged.sold_quantity) ELSE 0 END')
        ];

        if (isset($sortMap[$sortField])) {
            $query->orderBy($sortMap[$sortField], $direction);
        } else {
            $query->orderBy($sortMap['product_name'], $direction);
        }

        $query->orderBy('merged.product_id', 'asc');
    }

    public function calculateGrandTotal(SaleByProductReportFilterData $filter): array
    {
        $query = clone $this->build($filter);
        $query->orders = [];
        
        $result = DB::query()->fromSub($query, 'grand_total')
            ->select(
                DB::raw('SUM(sold_value) as total_sold_value'),
                DB::raw('SUM(return_value) as total_return_value')
            )->first();
            
        return [
            'sold_value' => (float) ($result->total_sold_value ?? 0),
            'return_value' => (float) ($result->total_return_value ?? 0),
        ];
    }
}
