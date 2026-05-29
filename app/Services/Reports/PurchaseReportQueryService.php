<?php

namespace App\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Modules\Purchase\Entities\Purchase;
use Illuminate\Support\Facades\DB;

class PurchaseReportQueryService
{
    public function build(PurchaseReportFilterData $filter): Builder
    {
        $query = Purchase::with(['supplier', 'tags']);

        // Scope enforcement
        if (!$filter->isGlobal) {
            $query->where('setting_id', $filter->scopeSettingId ?: session('setting_id'));
        }

        // Basic filters
        $dateColumn = $filter->dateBasis === 'due_date' ? 'due_date' : 'date';
        $query->where($dateColumn, '>=', $filter->startDate)
            ->where($dateColumn, '<=', $filter->endDate);

        if (!empty($filter->supplierIds)) {
            $query->whereIn('supplier_id', $filter->supplierIds);
        }

        if ($filter->withTax !== null && $filter->withTax !== '') {
            $query->where('is_tax_included', $filter->withTax);
        }

        if (!empty($filter->tagIds)) {
            $query->whereHas('tags', fn($q) => $q->whereIn('tags.id', $filter->tagIds));
        }

        if ($filter->deliveryStatus) {
            $query->where('status', $filter->deliveryStatus);
        }

        // Active payment status derivation (FR-014, FR-015)
        // We need to filter based on derived payment status if provided
        if ($filter->paymentStatus) {
            $query->where(function ($q) use ($filter) {
                $subquery = DB::table('purchase_payments')
                    ->select(DB::raw('SUM(amount)'))
                    ->whereColumn('purchase_id', 'purchases.id')
                    ->where('status', 'ACTIVE');

                if ($filter->paymentStatus === 'PAID') {
                    $q->whereRaw('total_amount <= (' . $subquery->toSql() . ')', $subquery->getBindings());
                } elseif ($filter->paymentStatus === 'PARTIAL') {
                    $q->whereRaw('0 < (' . $subquery->toSql() . ')', $subquery->getBindings())
                      ->whereRaw('total_amount > (' . $subquery->toSql() . ')', $subquery->getBindings());
                } elseif ($filter->paymentStatus === 'UNPAID') {
                    $q->where(function($sq) use ($subquery) {
                        $sq->whereRaw('(' . $subquery->toSql() . ') IS NULL', $subquery->getBindings())
                          ->orWhereRaw('(' . $subquery->toSql() . ') = 0', $subquery->getBindings());
                    });
                }
            });
        }

        return $query;
    }
}
