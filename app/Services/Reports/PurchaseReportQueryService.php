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
        $query->where('date', '>=', $filter->startDate)
            ->where('date', '<=', $filter->endDate);

        if ($filter->supplierId) {
            $query->where('supplier_id', $filter->supplierId);
        }

        if ($filter->withTax !== null && $filter->withTax !== '') {
            $query->where('is_tax_included', $filter->withTax);
        }

        if ($filter->selectedTag) {
            $query->whereHas('tags', fn($q) => $q->where('tags.id', $filter->selectedTag));
        }

        if ($filter->status) {
            $query->where('status', $filter->status);
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
