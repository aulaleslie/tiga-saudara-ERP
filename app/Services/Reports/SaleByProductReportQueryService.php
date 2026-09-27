<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use App\Services\Reports\Concerns\EffectiveSaleReportingDate;
use App\Services\Reports\Concerns\FulfilledTransactionEligibility;

class SaleByProductReportQueryService
{
    public function build(SaleByProductReportFilterData $filter): Builder
    {
        $scopeSettingIds = !empty($filter->scopeSettingIds)
            ? $filter->scopeSettingIds
            : [(int) session('setting_id')];

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

        $this->applyFiltersToSold($query, $filter);

        $query->groupBy('sale_details.product_id', 'sale_details.product_code', 'sale_details.product_name', 'unit_name');

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
                  ->whereColumn('products.id', 'sale_details.product_id')
                  ->whereIn('products.category_id', $filter->categoryIds);
            });
        }
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

    public function calculateGrandTotal(SaleByProductReportFilterData $filter): array
    {
        $query = clone $this->build($filter);
        $query->orders = [];

        $result = DB::query()->fromSub($query, 'grand_total')
            ->select(
                DB::raw('SUM(sold_value) as total_sold_value')
            )->first();

        return [
            'sold_value' => (float) ($result->total_sold_value ?? 0),
        ];
    }
}
