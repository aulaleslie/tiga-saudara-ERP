<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Modules\Expense\Entities\Expense;
use Modules\Purchase\Entities\Purchase;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\Setting\Entities\Setting;

class OperationalProfitLossReportService
{
    private SaleHppAggregateService $hppAggregate;

    public function __construct(?SaleHppAggregateService $hppAggregate = null)
    {
        $this->hppAggregate = $hppAggregate ?? new SaleHppAggregateService();
    }

    public function generate(array $settingIds, ?string $startDate, ?string $endDate): OperationalProfitLossReport
    {
        $normalizedSettingIds = $this->normalizeSettingIds($settingIds);

        // Use the first setting for currency determination (all should be IDR)
        $setting = Setting::with('currency')->find($normalizedSettingIds[0] ?? null);
        $currencyCode = $setting && $setting->currency ? ($setting->currency->code ?? $setting->currency->currency_name ?? 'IDR') : 'IDR';

        $periodLabel = $this->buildPeriodLabel($startDate, $endDate);

        // Sales (Completed: DISPATCHED, RETURNED_PARTIALLY, RETURNED)
        // Excludes DISPATCHED_PARTIALLY to avoid overstating revenue from incomplete shipments
        $salesAggregates = $this->hppAggregate->totals($normalizedSettingIds, $startDate, $endDate);

        $penjualan = $salesAggregates->dpp ?? 0;
        $bebanPokokPendapatan = $salesAggregates->hpp ?? 0;

        $diskonPenjualan = -Sale::whereIn('status', [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY, Sale::STATUS_RETURNED])
            ->whereIn('setting_id', $normalizedSettingIds)
            ->when($startDate, fn($q) => $q->whereDate('date', '>=', $startDate))
            ->when($endDate, fn($q) => $q->whereDate('date', '<=', $endDate))
            ->sum('discount_amount');

        // Expenses (Approved, not archived)
        $bebanOperasional = Expense::activeApproved()
            ->whereIn('setting_id', $normalizedSettingIds)
            ->when($startDate, fn($q) => $q->whereDate('date', '>=', $startDate))
            ->when($endDate, fn($q) => $q->whereDate('date', '<=', $endDate))
            ->sum('amount');

        return new OperationalProfitLossReport(
            $currencyCode,
            $periodLabel,
            (float) $penjualan,
            (float) $diskonPenjualan,
            (float) $bebanPokokPendapatan,
            (float) $bebanOperasional
        );
    }

    private function normalizeSettingIds(array $settingIds): array {
        return array_filter(
            array_map('intval', $settingIds),
            fn($id) => $id > 0
        );
    }

    private function buildPeriodLabel(?string $startDate, ?string $endDate): string
    {
        if ($startDate && $endDate) {
            return Carbon::parse($startDate)->format('d M Y') . ' - ' . Carbon::parse($endDate)->format('d M Y');
        } elseif ($startDate) {
            return 'Sejak ' . Carbon::parse($startDate)->format('d M Y');
        } elseif ($endDate) {
            return 'Hingga ' . Carbon::parse($endDate)->format('d M Y');
        }
        return 'Semua Waktu';
    }
}
