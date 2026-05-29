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

        if ($filter->paymentStatus) {
            $upperStatus = strtoupper($filter->paymentStatus);
            $titleStatus = ucfirst(strtolower($filter->paymentStatus));
            $query->whereIn('payment_status', [$upperStatus, $titleStatus]);
        }

        return $query;
    }
}
