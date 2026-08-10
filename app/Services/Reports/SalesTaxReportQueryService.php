<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\EffectivePurchaseReportingDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sale\Entities\Sale;
use Modules\Purchase\Entities\Purchase;

class SalesTaxReportQueryService
{
    /**
     * @return Collection of normalized tax rows: [
     *   'tax_id' => int,
     *   'tax_name' => string,
     *   'tax_rate' => float,
     *   'transaction_type' => 'Penjualan' | 'Pembelian',
     *   'dpp' => float,
     *   'total_tax' => float
     * ]
     */
    public function build(SalesTaxReportFilterData $filter): Collection
    {
        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id');

        $salesRows = $this->getSalesTaxRows($filter, $scopeSettingId);
        $purchaseRows = $this->getPurchaseTaxRows($filter, $scopeSettingId);

        return collect()->concat($salesRows)->concat($purchaseRows);
    }

    private function getSalesTaxRows(SalesTaxReportFilterData $filter, int $scopeSettingId): Collection
    {
        return DB::table('sale_details')
            ->join('sales', 'sales.id', '=', 'sale_details.sale_id')
            ->join('taxes', 'taxes.id', '=', 'sale_details.tax_id')
            ->where('sales.setting_id', $scopeSettingId)
            ->where('sales.date', '>=', $filter->startDate)
            ->where('sales.date', '<=', $filter->endDate)
            ->whereIn('sales.status', [
                Sale::STATUS_APPROVED,
                Sale::STATUS_DISPATCHED_PARTIALLY,
                Sale::STATUS_DISPATCHED,
                Sale::STATUS_RETURNED_PARTIALLY,
                Sale::STATUS_RETURNED,
            ])
            ->whereNull('sales.archived_at')
            ->whereNotNull('sale_details.tax_id')
            ->where('sale_details.product_tax_amount', '!=', 0)
            ->select(
                'taxes.id as tax_id',
                'taxes.name as tax_name',
                'taxes.value as tax_rate',
                DB::raw("'Penjualan' as transaction_type"),
                DB::raw('SUM(CASE WHEN (COALESCE(sale_details.sub_total, 0) - COALESCE(sale_details.product_tax_amount, 0)) > 0 THEN (COALESCE(sale_details.sub_total, 0) - COALESCE(sale_details.product_tax_amount, 0)) ELSE 0 END) as dpp'),
                DB::raw('SUM(COALESCE(sale_details.product_tax_amount, 0)) as total_tax')
            )
            ->groupBy('taxes.id', 'taxes.name', 'taxes.value')
            ->get();
    }

    private function getPurchaseTaxRows(SalesTaxReportFilterData $filter, int $scopeSettingId): Collection
    {
        return DB::table('purchase_details')
            ->join('purchases', 'purchases.id', '=', 'purchase_details.purchase_id')
            ->join('taxes', 'taxes.id', '=', 'purchase_details.tax_id')
            ->where('purchases.setting_id', $scopeSettingId)
            ->whereRaw(EffectivePurchaseReportingDate::sqlExpression() . ' >= ?', [$filter->startDate])
            ->whereRaw(EffectivePurchaseReportingDate::sqlExpression() . ' <= ?', [$filter->endDate])
            ->whereIn('purchases.status', [
                Purchase::STATUS_APPROVED,
                Purchase::STATUS_RECEIVED_PARTIALLY,
                Purchase::STATUS_RECEIVED,
                Purchase::STATUS_RETURNED_PARTIALLY,
                Purchase::STATUS_RETURNED,
            ])
            ->whereNull('purchases.archived_at')
            ->whereNotNull('purchase_details.tax_id')
            ->where('purchase_details.product_tax_amount', '!=', 0)
            ->select(
                'taxes.id as tax_id',
                'taxes.name as tax_name',
                'taxes.value as tax_rate',
                DB::raw("'Pembelian' as transaction_type"),
                DB::raw('SUM(CASE WHEN (COALESCE(purchase_details.sub_total, 0) - COALESCE(purchase_details.product_tax_amount, 0)) > 0 THEN (COALESCE(purchase_details.sub_total, 0) - COALESCE(purchase_details.product_tax_amount, 0)) ELSE 0 END) as dpp'),
                DB::raw('SUM(COALESCE(purchase_details.product_tax_amount, 0)) as total_tax')
            )
            ->groupBy('taxes.id', 'taxes.name', 'taxes.value')
            ->get();
    }
}
