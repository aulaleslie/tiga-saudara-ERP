<?php

namespace App\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Modules\Purchase\Entities\Purchase;

class PurchaseReportQueryService
{
    public function build(PurchaseReportFilterData $filter): Builder
    {
        $query = Purchase::with(['supplier', 'tags']);

        // Scope enforcement
        if (!$filter->isGlobal) {
            $query->where('purchases.setting_id', $filter->scopeSettingId ?: session('setting_id'));
        }

        // Basic filters
        $dateColumn = $filter->dateBasis === 'due_date' ? 'purchases.due_date' : 'purchases.date';
        $query->where($dateColumn, '>=', $filter->startDate)
            ->where($dateColumn, '<=', $filter->endDate);

        if (!empty($filter->supplierIds)) {
            $query->whereIn('purchases.supplier_id', $filter->supplierIds);
        }

        if ($filter->withTax !== null && $filter->withTax !== '') {
            $query->where('purchases.is_tax_included', $filter->withTax);
        }

        if (!empty($filter->tagIds)) {
            $query->whereHas('tags', fn($q) => $q->whereIn('tags.id', $filter->tagIds));
        }

        if ($filter->deliveryStatus) {
            $query->where('purchases.status', $filter->deliveryStatus);
        }

        if ($filter->paymentStatus) {
            $upperStatus = strtoupper($filter->paymentStatus);
            $titleStatus = ucfirst(strtolower($filter->paymentStatus));
            $query->whereIn('purchases.payment_status', [$upperStatus, $titleStatus]);
        }

        return $query;
    }
}
