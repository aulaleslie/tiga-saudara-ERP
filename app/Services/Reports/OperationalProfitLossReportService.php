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
    public function generate(array $settingIds, ?string $startDate, ?string $endDate): OperationalProfitLossReport
    {
        $normalizedSettingIds = $this->normalizeSettingIds($settingIds);

        // Use the first setting for currency determination (all should be IDR)
        $setting = Setting::with('currency')->find($normalizedSettingIds[0] ?? null);
        $currencyCode = $setting && $setting->currency ? ($setting->currency->code ?? $setting->currency->currency_name ?? 'IDR') : 'IDR';

        $periodLabel = $this->buildPeriodLabel($startDate, $endDate);

        // Sales (Completed: DISPATCHED, RETURNED_PARTIALLY, RETURNED)
        // Excludes DISPATCHED_PARTIALLY to avoid overstating revenue from incomplete shipments
        $salesTotal = Sale::whereIn('status', [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY, Sale::STATUS_RETURNED])
            ->whereIn('setting_id', $normalizedSettingIds)
            ->when($startDate, fn($q) => $q->whereDate('date', '>=', $startDate))
            ->when($endDate, fn($q) => $q->whereDate('date', '<=', $endDate))
            ->sum('total_amount');

        // Sale Returns (Completed)
        $saleReturnsTotal = SaleReturn::whereIn('status', ['Completed', 'COMPLETED'])
            ->whereIn('setting_id', $normalizedSettingIds)
            ->when($startDate, fn($q) => $q->whereDate('date', '>=', $startDate))
            ->when($endDate, fn($q) => $q->whereDate('date', '<=', $endDate))
            ->sum('total_amount');

        // Purchases (Completed: RECEIVED, RETURNED_PARTIALLY, RETURNED)
        // Excludes RECEIVED_PARTIALLY to avoid overstating cost from incomplete receipts
        $purchasesTotal = Purchase::whereIn('status', [Purchase::STATUS_RECEIVED, Purchase::STATUS_RETURNED_PARTIALLY, Purchase::STATUS_RETURNED])
            ->whereIn('setting_id', $normalizedSettingIds)
            ->when($startDate, fn($q) => $q->whereDate('date', '>=', $startDate))
            ->when($endDate, fn($q) => $q->whereDate('date', '<=', $endDate))
            ->sum('total_amount');

        // Purchase Returns (Completed)
        $purchaseReturnsTotal = PurchaseReturn::whereIn('status', ['Completed', 'COMPLETED'])
            ->whereIn('setting_id', $normalizedSettingIds)
            ->when($startDate, fn($q) => $q->whereDate('date', '>=', $startDate))
            ->when($endDate, fn($q) => $q->whereDate('date', '<=', $endDate))
            ->sum('total_amount');

        // Expenses (Approved, not archived)
        // Note: amount is stored in cents, so sum('amount') returns cents, must divide by 100
        $expensesCentsTotal = Expense::activeApproved()
            ->whereIn('setting_id', $normalizedSettingIds)
            ->when($startDate, fn($q) => $q->whereDate('date', '>=', $startDate))
            ->when($endDate, fn($q) => $q->whereDate('date', '<=', $endDate))
            ->sum('amount');
        $expensesTotal = $expensesCentsTotal / 100;

        return new OperationalProfitLossReport(
            $currencyCode,
            $periodLabel,
            (float) $salesTotal,
            (float) $saleReturnsTotal,
            (float) $purchasesTotal,
            (float) $purchaseReturnsTotal,
            (float) $expensesTotal
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
