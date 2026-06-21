<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SaleDeliveryReportQueryService
{
    public function build(SaleDeliveryReportFilterData $filter): Builder
    {
        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id');

        // 1. Commercial aggregate from sale_details (bundle_id = 0)
        $standardCommercial = DB::table('sale_details')
            ->join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->select(
                'sale_details.sale_id',
                'sale_details.product_id',
                DB::raw('COALESCE(sale_details.tax_id, 0) as tax_id'),
                DB::raw('0 as bundle_id'),
                DB::raw('SUM(sale_details.quantity) as ordered_quantity'),
                DB::raw('SUM(sale_details.sub_total) as commercial_line_amount'),
                DB::raw('MAX(sale_details.product_name) as product_name'),
                DB::raw('MAX(sale_details.product_code) as product_code')
            )
            ->where('sales.setting_id', $scopeSettingId)
            ->groupBy('sale_details.sale_id', 'sale_details.product_id', DB::raw('COALESCE(sale_details.tax_id, 0)'));

        // 2. Commercial aggregate from sale_bundle_items (bundle_id > 0)
        $bundleCommercial = DB::table('sale_bundle_items')
            ->join('sales', 'sale_bundle_items.sale_id', '=', 'sales.id')
            ->leftJoin('sale_details', 'sale_bundle_items.sale_detail_id', '=', 'sale_details.id')
            ->select(
                'sale_bundle_items.sale_id',
                'sale_bundle_items.product_id',
                DB::raw('COALESCE(sale_details.tax_id, sale_bundle_items.tax_id, 0) as tax_id'),
                DB::raw('COALESCE(sale_bundle_items.bundle_id, 0) as bundle_id'),
                DB::raw('SUM(sale_bundle_items.quantity) as ordered_quantity'),
                DB::raw('SUM(sale_bundle_items.sub_total) as commercial_line_amount'),
                DB::raw('MAX(sale_bundle_items.name) as product_name'),
                DB::raw('NULL as product_code')
            )
            ->where('sales.setting_id', $scopeSettingId)
            ->groupBy('sale_bundle_items.sale_id', 'sale_bundle_items.product_id', DB::raw('COALESCE(sale_details.tax_id, sale_bundle_items.tax_id, 0)'), DB::raw('COALESCE(sale_bundle_items.bundle_id, 0)'));

        // Combine commercial aggregates
        $commercialAggregate = DB::table(DB::raw("({$standardCommercial->toSql()} UNION ALL {$bundleCommercial->toSql()}) as commercial_agg"))
            ->mergeBindings($standardCommercial)
            ->mergeBindings($bundleCommercial);

        // 3. Delivery aggregate
        $deliveryAggregate = DB::table('dispatch_details')
            ->join('dispatches', 'dispatch_details.dispatch_id', '=', 'dispatches.id')
            ->join('sales', 'dispatches.sale_id', '=', 'sales.id')
            ->join('customers', 'sales.customer_id', '=', 'customers.id')
            ->select(
                'sales.customer_id',
                'customers.customer_name',
                'dispatches.dispatch_date',
                'sales.reference as dispatch_reference',
                'dispatch_details.sale_id',
                'dispatch_details.product_id',
                DB::raw('COALESCE(dispatch_details.tax_id, 0) as tax_id'),
                DB::raw('COALESCE(dispatch_details.bundle_id, 0) as bundle_id'),
                DB::raw('SUM(dispatch_details.dispatched_quantity) as delivered_quantity')
            )
            ->where('sales.setting_id', $scopeSettingId)
            ->where('dispatches.status', 'APPROVED')
            ->where('dispatches.dispatch_date', '>=', $filter->startDate)
            ->where('dispatches.dispatch_date', '<=', $filter->endDate)
            ->groupBy(
                'sales.customer_id',
                'customers.customer_name',
                'dispatches.dispatch_date',
                'sales.reference',
                'dispatch_details.sale_id',
                'dispatch_details.product_id',
                DB::raw('COALESCE(dispatch_details.tax_id, 0)'),
                DB::raw('COALESCE(dispatch_details.bundle_id, 0)')
            );

        // Filter deliveries by customer
        if (!empty($filter->customerIds)) {
            $deliveryAggregate->whereIn('sales.customer_id', $filter->customerIds);
        }

        // Apply filters on Sales if needed (tags, category logic goes through products/sales)
        if (!empty($filter->tagIds) || !empty($filter->categoryIds)) {
            $deliveryAggregate->whereExists(function ($q) use ($filter) {
                $q->select(DB::raw(1))
                  ->from('sales')
                  ->whereColumn('sales.id', 'dispatch_details.sale_id');
                
                // Note: full filter application requires joining sales/products.
                // For simplicity, we can do it after joining delivery and commercial.
            });
        }

        // 4. Join and calculate
        $query = DB::query()->fromSub($deliveryAggregate, 'delivery')
            ->leftJoinSub($commercialAggregate, 'commercial', function ($join) {
                $join->on('delivery.sale_id', '=', 'commercial.sale_id')
                     ->on('delivery.product_id', '=', 'commercial.product_id')
                     ->on('delivery.tax_id', '=', 'commercial.tax_id')
                     ->on('delivery.bundle_id', '=', 'commercial.bundle_id');
            })
            ->leftJoin('products', 'delivery.product_id', '=', 'products.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->leftJoin('units as base_units', 'products.base_unit_id', '=', 'base_units.id')
            ->select(
                'delivery.customer_id',
                'delivery.customer_name',
                'delivery.dispatch_date',
                'delivery.dispatch_reference',
                'delivery.sale_id',
                'delivery.product_id',
                'delivery.tax_id',
                'delivery.bundle_id',
                'delivery.delivered_quantity',
                DB::raw('COALESCE(commercial.product_name, products.product_name) as product_name'),
                DB::raw('COALESCE(commercial.product_code, products.product_code) as product_code'),
                DB::raw('COALESCE(units.short_name, base_units.short_name, products.product_unit, \'-\') as unit_name'),
                'commercial.ordered_quantity',
                'commercial.commercial_line_amount',
                DB::raw('CASE WHEN commercial.ordered_quantity > 0 THEN commercial.commercial_line_amount / commercial.ordered_quantity ELSE 0 END as unit_amount'),
                DB::raw('delivery.delivered_quantity * CASE WHEN commercial.ordered_quantity > 0 THEN commercial.commercial_line_amount / commercial.ordered_quantity ELSE 0 END as delivered_amount')
            );

        // Tags filter
        if (!empty($filter->tagIds)) {
            if ($filter->tagLogic === 'Mencakup semua') {
                foreach ($filter->tagIds as $tagId) {
                    $query->whereExists(function ($q) use ($tagId) {
                        $q->select(DB::raw(1))
                          ->from('taggables')
                          ->whereColumn('taggables.taggable_id', 'delivery.sale_id')
                          ->where('taggables.taggable_type', 'Modules\Sale\Entities\Sale')
                          ->where('taggables.tag_id', $tagId);
                    });
                }
            } else {
                $query->whereExists(function ($q) use ($filter) {
                    $q->select(DB::raw(1))
                      ->from('taggables')
                      ->whereColumn('taggables.taggable_id', 'delivery.sale_id')
                      ->where('taggables.taggable_type', 'Modules\Sale\Entities\Sale')
                      ->whereIn('taggables.tag_id', $filter->tagIds);
                });
            }
        }

        // Category filter
        if (!empty($filter->categoryIds)) {
            if ($filter->categoryLogic === 'Mencakup semua') {
                foreach ($filter->categoryIds as $categoryId) {
                    $query->where(function ($sub) use ($categoryId) {
                        $sub->whereExists(function ($q) use ($categoryId) {
                            $q->select(DB::raw(1))
                              ->from('sale_details')
                              ->join('products as sd_products', 'sale_details.product_id', '=', 'sd_products.id')
                              ->whereColumn('sale_details.sale_id', 'delivery.sale_id')
                              ->where('sd_products.category_id', $categoryId);
                        })->orWhereExists(function ($q) use ($categoryId) {
                            $q->select(DB::raw(1))
                              ->from('sale_bundle_items')
                              ->join('products as sbi_products', 'sale_bundle_items.product_id', '=', 'sbi_products.id')
                              ->whereColumn('sale_bundle_items.sale_id', 'delivery.sale_id')
                              ->where('sbi_products.category_id', $categoryId);
                        });
                    });
                }
            }
            $query->whereIn('products.category_id', $filter->categoryIds);
        }

        return $query;
    }

    public function applySort(Builder $query, string $sortField, string $sortDirection): void
    {
        $direction = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        if ($sortField === 'customer_name') {
            $query->orderBy('delivery.customer_name', $direction)
                  ->orderBy('delivery.customer_id', 'asc')
                  ->orderBy('delivery.dispatch_date', 'desc');
        } elseif ($sortField === 'product_name') {
            $aggFunc = 'MAX';
            
            $baseQuery = clone $query;
            $baseQuery->columns = [];
            $baseQuery->orders = [];
            
            $customerDates = $baseQuery
                ->select('delivery.customer_id', DB::raw("{$aggFunc}(delivery.dispatch_date) as group_date"))
                ->groupBy('delivery.customer_id');

            $query->leftJoinSub($customerDates, 'cd', 'cd.customer_id', '=', 'delivery.customer_id')
                  ->orderBy('cd.group_date', 'desc')
                  ->orderBy('delivery.customer_id', 'asc') // Tie-breaker
                  ->orderBy('product_name', $direction)
                  ->orderBy('delivery.dispatch_date', 'desc');
        } else {
            // Sort by date while keeping customers grouped together based on their max/min date
            $aggFunc = $direction === 'asc' ? 'MIN' : 'MAX';
            
            $baseQuery = clone $query;
            $baseQuery->columns = [];
            $baseQuery->orders = [];
            
            $customerDates = $baseQuery
                ->select('delivery.customer_id', DB::raw("{$aggFunc}(delivery.dispatch_date) as group_date"))
                ->groupBy('delivery.customer_id');

            $query->leftJoinSub($customerDates, 'cd', 'cd.customer_id', '=', 'delivery.customer_id')
                  ->orderBy('cd.group_date', $direction)
                  ->orderBy('delivery.customer_id', 'asc') // Tie-breaker
                  ->orderBy('delivery.dispatch_date', $direction);
        }
        
        $query->orderBy('delivery.sale_id', 'asc');
    }

    public function calculateGrandTotal(SaleDeliveryReportFilterData $filter): float
    {
        $query = $this->build($filter);
        $query->columns = [];
        $query->orders = [];
        
        return (float) $query->sum(DB::raw('delivery.delivered_quantity * CASE WHEN commercial.ordered_quantity > 0 THEN commercial.commercial_line_amount / commercial.ordered_quantity ELSE 0 END'));
    }
}
