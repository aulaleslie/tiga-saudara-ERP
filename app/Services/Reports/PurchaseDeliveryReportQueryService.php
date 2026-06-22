<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PurchaseDeliveryReportQueryService
{
    public function build(PurchaseDeliveryReportFilterData $filter): Builder
    {
        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id');

        $query = DB::table('received_note_details')
            ->join('received_notes', 'received_note_details.received_note_id', '=', 'received_notes.id')
            ->join('purchase_details', 'received_note_details.po_detail_id', '=', 'purchase_details.id')
            ->join('purchases', 'purchase_details.purchase_id', '=', 'purchases.id')
            ->join('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
            ->leftJoin('products', 'purchase_details.product_id', '=', 'products.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->leftJoin('units as base_units', 'products.base_unit_id', '=', 'base_units.id')
            ->select(
                'purchases.supplier_id',
                'suppliers.supplier_name',
                'purchase_details.product_id',
                DB::raw('MAX(received_notes.date) as last_delivery_date'),
                DB::raw('SUM(received_note_details.quantity_received) as delivered_quantity'),
                DB::raw('MAX(COALESCE(purchase_details.product_name, products.product_name)) as product_name'),
                DB::raw('MAX(COALESCE(purchase_details.product_code, products.product_code)) as product_code'),
                DB::raw('MAX(COALESCE(units.short_name, base_units.short_name, products.product_unit, \'-\')) as unit_name'),
                DB::raw('SUM(received_note_details.quantity_received * (CASE WHEN purchase_details.quantity > 0 THEN purchase_details.sub_total / purchase_details.quantity ELSE 0 END)) as delivered_amount')
            )
            ->where('purchases.setting_id', $scopeSettingId)
            ->where('received_notes.status', 'APPROVED')
            ->where('received_notes.date', '>=', $filter->startDate)
            ->where('received_notes.date', '<=', $filter->endDate)
            ->groupBy(
                'purchases.supplier_id',
                'suppliers.supplier_name',
                'purchase_details.product_id'
            );

        if (!empty($filter->supplierIds)) {
            $query->whereIn('purchases.supplier_id', $filter->supplierIds);
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
            if ($filter->categoryLogic === 'Mencakup semua') {
                foreach ($filter->categoryIds as $categoryId) {
                    $query->whereExists(function ($q) use ($categoryId) {
                        $q->select(DB::raw(1))
                          ->from('purchase_details as pd_cat')
                          ->join('products as prod_cat', 'pd_cat.product_id', '=', 'prod_cat.id')
                          ->whereColumn('pd_cat.purchase_id', 'purchases.id')
                          ->where('prod_cat.category_id', $categoryId);
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

        if ($sortField === 'supplier_name') {
            $query->orderBy('supplier_name', $direction)
                  ->orderBy('supplier_id', 'asc')
                  ->orderBy('last_delivery_date', 'desc')
                  ->orderBy('product_name', 'asc');
        } elseif ($sortField === 'product_name') {
            $aggFunc = 'MAX';
            
            $baseQuery = clone $query;
            $baseQuery->columns = [];
            $baseQuery->orders = [];
            $baseQuery->groups = ['purchases.supplier_id'];
            
            $supplierDates = $baseQuery
                ->select('purchases.supplier_id', DB::raw("{$aggFunc}(received_notes.date) as group_date"))
                ->groupBy('purchases.supplier_id');

            $query->joinSub($supplierDates, 'sd', 'sd.supplier_id', '=', 'purchases.supplier_id')
                  ->orderBy('sd.group_date', 'desc')
                  ->orderBy('supplier_id', 'asc') // Tie-breaker
                  ->orderBy('product_name', $direction)
                  ->orderBy('last_delivery_date', 'desc');
        } else {
            // Sort by date while keeping suppliers grouped together based on their max/min date
            $aggFunc = $direction === 'asc' ? 'MIN' : 'MAX';
            
            $baseQuery = clone $query;
            $baseQuery->columns = [];
            $baseQuery->orders = [];
            $baseQuery->groups = ['purchases.supplier_id'];
            
            $supplierDates = $baseQuery
                ->select('purchases.supplier_id', DB::raw("{$aggFunc}(received_notes.date) as group_date"))
                ->groupBy('purchases.supplier_id');

            $query->joinSub($supplierDates, 'sd', 'sd.supplier_id', '=', 'purchases.supplier_id')
                  ->orderBy('sd.group_date', $direction)
                  ->orderBy('supplier_id', 'asc') // Tie-breaker
                  ->orderBy('last_delivery_date', $direction)
                  ->orderBy('product_name', 'asc');
        }
    }

    public function calculateGrandTotal(PurchaseDeliveryReportFilterData $filter): float
    {
        $query = $this->build($filter);
        $query->columns = [];
        $query->orders = [];
        $query->groups = [];
        
        // Use a subquery to correctly calculate the total, since we use aggregations in the main query
        $subquery = $this->build($filter);
        
        return (float) DB::query()->fromSub($subquery, 'sq')->sum('delivered_amount');
    }
}
