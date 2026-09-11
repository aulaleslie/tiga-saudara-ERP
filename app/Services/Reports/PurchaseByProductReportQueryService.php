<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\EffectivePurchaseReportingDate;
use App\Services\Reports\Concerns\FulfilledTransactionEligibility;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PurchaseByProductReportQueryService
{
    public function build(PurchaseByProductReportFilterData $filter): Builder
    {
        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id');

        $query = DB::table('purchase_details')
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
                DB::raw('SUM(CASE WHEN purchases.is_tax_included = 1 THEN purchase_details.sub_total - COALESCE(purchase_details.product_tax_amount, 0) ELSE purchase_details.sub_total END) as purchase_value'),
                DB::raw('CASE WHEN SUM(purchase_details.quantity) > 0 THEN SUM(CASE WHEN purchases.is_tax_included = 1 THEN purchase_details.sub_total - COALESCE(purchase_details.product_tax_amount, 0) ELSE purchase_details.sub_total END) / SUM(purchase_details.quantity) ELSE 0 END as average_purchase_value')
            )
            ->where('purchases.setting_id', $scopeSettingId)
            ->whereRaw(EffectivePurchaseReportingDate::sqlExpression() . ' >= ?', [$filter->startDate])
            ->whereRaw(EffectivePurchaseReportingDate::sqlExpression() . ' <= ?', [$filter->endDate]);

        FulfilledTransactionEligibility::applyToPurchaseQuery($query, 'purchases');

        $this->applyFiltersToPurchase($query, $filter);

        $query->groupBy('purchase_details.product_id', 'purchase_details.product_code', 'purchase_details.product_name', 'unit_name');

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
                  ->whereColumn('products.id', 'purchase_details.product_id')
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
            'purchase_quantity' => 'purchase_quantity',
            'purchase_value' => 'purchase_value',
            'average_purchase_value' => 'average_purchase_value',
        ];

        $query->orderBy($sortMap[$sortField] ?? $sortMap['product_name'], $direction);
        $query->orderBy('purchase_details.product_id', 'asc');
    }

    public function calculateGrandTotal(PurchaseByProductReportFilterData $filter): array
    {
        $query = clone $this->build($filter);
        $query->orders = [];

        $result = DB::query()->fromSub($query, 'grand_total')
            ->select(
                DB::raw('SUM(purchase_value) as total_purchase_value')
            )->first();

        return [
            'purchase_value' => (float) ($result->total_purchase_value ?? 0),
        ];
    }
}
