<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PurchaseByProductReportQueryService
{
    public function build(PurchaseByProductReportFilterData $filter): Builder
    {
        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id');

        // 1. Purchase Aggregate
        $purchaseQuery = DB::table('purchase_details')
            ->join('purchases', 'purchase_details.purchase_id', '=', 'purchases.id')
            ->leftJoin('products', 'purchase_details.product_id', '=', 'products.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->leftJoin('units as base_units', 'products.base_unit_id', '=', 'base_units.id')
            ->select(
                'purchase_details.product_id',
                DB::raw("COALESCE(products.product_code, purchase_details.product_code, '') as product_code"),
                DB::raw("COALESCE(products.product_name, purchase_details.product_name, '') as product_name"),
                DB::raw("COALESCE(units.short_name, base_units.short_name, products.product_unit, '-') as unit_name"),
                DB::raw('SUM(purchase_details.quantity) as purchase_quantity'),
                DB::raw('0 as return_quantity'),
                DB::raw('SUM(CASE WHEN purchases.is_tax_included = 1 THEN purchase_details.sub_total - COALESCE(purchase_details.product_tax_amount, 0) ELSE purchase_details.sub_total END) as purchase_value'),
                DB::raw('0 as return_value')
            )
            ->where('purchases.setting_id', $scopeSettingId)
            ->whereDate('purchases.date', '>=', $filter->startDate)
            ->whereDate('purchases.date', '<=', $filter->endDate);

        $this->applyFiltersToPurchase($purchaseQuery, $filter);

        $purchaseQuery->groupBy('purchase_details.product_id', 'purchase_details.product_code', 'purchase_details.product_name', 'unit_name');

        // 2. Return Aggregate
        // The instructions: "Calculate purchase value as tax-exclusive line value, subtracting `purchase_details.product_tax_amount` when `purchases.is_tax_included` is true."
        // For returns, we will assume a similar logic or just use sub_total since return_details sub_total might already account for tax depending on how it's created. We'll join purchase if it has a direct link, but purchase_returns doesn't reliably link to purchases. 
        // Let's use purchase_return_details.sub_total. If we need to exclude tax, we can just subtract product_tax_amount if we assume it's included, but the requirement only specified "when `purchases.is_tax_included` is true" for purchases. 
        // Let's use `purchase_return_details.sub_total` - `purchase_return_details.product_tax_amount` as a safe fallback if we don't have is_tax_included, or just `sub_total`. The spec doesn't explicitly mention tax for returns. I will use sub_total for return value, or maybe sub_total - tax. Let's do sub_total - tax if tax is > 0, to be safe, or just sub_total. Actually, let's just use `purchase_return_details.sub_total` as return value unless there's an is_tax_included. We'll use sub_total.
        $returnQuery = DB::table('purchase_return_details')
            ->join('purchase_returns', 'purchase_return_details.purchase_return_id', '=', 'purchase_returns.id')
            ->leftJoin('purchases', 'purchase_return_details.po_id', '=', 'purchases.id')
            ->leftJoin('products', 'purchase_return_details.product_id', '=', 'products.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->leftJoin('units as base_units', 'products.base_unit_id', '=', 'base_units.id')
            ->select(
                'purchase_return_details.product_id',
                DB::raw("COALESCE(products.product_code, purchase_return_details.product_code, '') as product_code"),
                DB::raw("COALESCE(products.product_name, purchase_return_details.product_name, '') as product_name"),
                DB::raw("COALESCE(units.short_name, base_units.short_name, products.product_unit, '-') as unit_name"),
                DB::raw('0 as purchase_quantity'),
                DB::raw('SUM(purchase_return_details.quantity) as return_quantity'),
                DB::raw('0 as purchase_value'),
                DB::raw('SUM(CASE WHEN purchases.is_tax_included = 1 THEN purchase_return_details.sub_total - COALESCE(purchase_return_details.product_tax_amount, 0) ELSE purchase_return_details.sub_total END) as return_value')
            )
            ->where('purchase_returns.setting_id', $scopeSettingId)
            ->whereDate('purchase_returns.date', '>=', $filter->startDate)
            ->whereDate('purchase_returns.date', '<=', $filter->endDate)
            ->where(DB::raw('LOWER(purchase_returns.approval_status)'), 'approved')
            ->where(DB::raw('LOWER(purchase_returns.return_dispatch_status)'), 'dispatched');

        $this->applyFiltersToReturn($returnQuery, $filter);

        $returnQuery->groupBy('purchase_return_details.product_id', 'purchase_return_details.product_code', 'purchase_return_details.product_name', 'unit_name');

        $combinedQuery = DB::table(DB::raw("({$purchaseQuery->toSql()} UNION ALL {$returnQuery->toSql()}) as combined"))
            ->mergeBindings($purchaseQuery)
            ->mergeBindings($returnQuery);

        $query = DB::query()->fromSub($combinedQuery, 'merged')
            ->select(
                'merged.product_id',
                'merged.product_code',
                'merged.product_name',
                'merged.unit_name',
                DB::raw('SUM(merged.purchase_quantity) as purchase_quantity'),
                DB::raw('SUM(merged.return_quantity) as return_quantity'),
                DB::raw('SUM(merged.purchase_value) as purchase_value'),
                DB::raw('SUM(merged.return_value) as return_value'),
                DB::raw('CASE WHEN SUM(merged.purchase_quantity) > 0 THEN SUM(merged.purchase_value) / SUM(merged.purchase_quantity) ELSE 0 END as average_purchase_value')
            );
        
        $query->groupBy(
            'merged.product_id',
            'merged.product_code',
            'merged.product_name',
            'merged.unit_name'
        );

        return $query;
    }

    private function applyFiltersToPurchase(Builder $query, PurchaseByProductReportFilterData $filter): void
    {
        if (!empty($filter->supplierIds)) {
            $query->whereIn('purchases.supplier_id', $filter->supplierIds);
        }

        if (!empty($filter->productIds)) {
            $query->whereIn('purchase_details.product_id', $filter->productIds);
        }

        if (!empty($filter->tagIds)) {
            if ($filter->tagLogic === 'Mencakup semua') {
                foreach ($filter->tagIds as $tagId) {
                    $query->whereExists(function ($q) use ($tagId) {
                        $q->select(DB::raw(1))
                          ->from('taggables')
                          ->whereColumn('taggables.taggable_id', 'purchases.id')
                          ->where('taggables.taggable_type', 'Modules\Purchase\Entities\Purchase')
                          ->where('taggables.tag_id', $tagId);
                    });
                }
            } else {
                $query->whereExists(function ($q) use ($filter) {
                    $q->select(DB::raw(1))
                      ->from('taggables')
                      ->whereColumn('taggables.taggable_id', 'purchases.id')
                      ->where('taggables.taggable_type', 'Modules\Purchase\Entities\Purchase')
                      ->whereIn('taggables.tag_id', $filter->tagIds);
                });
            }
        }

        if (!empty($filter->categoryIds)) {
            $query->whereExists(function ($q) use ($filter) {
                $q->select(DB::raw(1))
                  ->from('products')
                  ->whereColumn('products.id', 'purchase_details.product_id');

                $q->whereIn('products.category_id', $filter->categoryIds);
            });
        }
    }

    private function applyFiltersToReturn(Builder $query, PurchaseByProductReportFilterData $filter): void
    {
        if (!empty($filter->supplierIds)) {
            $query->whereIn('purchase_returns.supplier_id', $filter->supplierIds);
        }

        if (!empty($filter->productIds)) {
            $query->whereIn('purchase_return_details.product_id', $filter->productIds);
        }

        if (!empty($filter->tagIds)) {
            if ($filter->tagLogic === 'Mencakup semua') {
                foreach ($filter->tagIds as $tagId) {
                    $query->whereExists(function ($q) use ($tagId) {
                        $q->select(DB::raw(1))
                          ->from('taggables')
                          ->whereColumn('taggables.taggable_id', 'purchase_return_details.po_id')
                          ->where('taggables.taggable_type', 'Modules\Purchase\Entities\Purchase')
                          ->where('taggables.tag_id', $tagId);
                    });
                }
            } else {
                $query->whereExists(function ($q) use ($filter) {
                    $q->select(DB::raw(1))
                      ->from('taggables')
                      ->whereColumn('taggables.taggable_id', 'purchase_return_details.po_id')
                      ->where('taggables.taggable_type', 'Modules\Purchase\Entities\Purchase')
                      ->whereIn('taggables.tag_id', $filter->tagIds);
                });
            }
        }
        if (!empty($filter->categoryIds)) {
            $query->whereExists(function ($q) use ($filter) {
                $q->select(DB::raw(1))
                  ->from('products')
                  ->whereColumn('products.id', 'purchase_return_details.product_id')
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
            'purchase_quantity' => DB::raw('SUM(merged.purchase_quantity)'),
            'return_quantity' => DB::raw('SUM(merged.return_quantity)'),
            'purchase_value' => DB::raw('SUM(merged.purchase_value)'),
            'average_purchase_value' => DB::raw('CASE WHEN SUM(merged.purchase_quantity) > 0 THEN SUM(merged.purchase_value) / SUM(merged.purchase_quantity) ELSE 0 END')
        ];

        if (isset($sortMap[$sortField])) {
            $query->orderBy($sortMap[$sortField], $direction);
        } else {
            $query->orderBy($sortMap['product_name'], $direction);
        }

        $query->orderBy('merged.product_id', 'asc');
    }

    public function calculateGrandTotal(PurchaseByProductReportFilterData $filter): array
    {
        $query = clone $this->build($filter);
        $query->orders = [];
        
        $result = DB::query()->fromSub($query, 'grand_total')
            ->select(
                DB::raw('SUM(purchase_value) as total_purchase_value'),
                DB::raw('SUM(return_value) as total_return_value')
            )->first();
            
        return [
            'purchase_value' => (float) ($result->total_purchase_value ?? 0),
            'return_value' => (float) ($result->total_return_value ?? 0),
        ];
    }
}
