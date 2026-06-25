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
        $salesIds = Sale::whereIn('status', [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY, Sale::STATUS_RETURNED])
            ->whereIn('setting_id', $normalizedSettingIds)
            ->when($startDate, fn($q) => $q->whereDate('date', '>=', $startDate))
            ->when($endDate, fn($q) => $q->whereDate('date', '<=', $endDate))
            ->pluck('id');

        $salesTotal = Sale::whereIn('id', $salesIds)->sum('total_amount');

        // Sale Returns (Completed)
        $saleReturnsIds = SaleReturn::whereIn('status', ['Completed', 'COMPLETED'])
            ->whereIn('setting_id', $normalizedSettingIds)
            ->when($startDate, fn($q) => $q->whereDate('date', '>=', $startDate))
            ->when($endDate, fn($q) => $q->whereDate('date', '<=', $endDate))
            ->pluck('id');

        $saleReturnsTotal = SaleReturn::whereIn('id', $saleReturnsIds)->sum('total_amount');

        // Calculate Cost of Goods Sold (COGS) from Sales
        // For finalized sales, use snapshots only (treat null as zero for stability, not fallback to current average)
        $salesCostTotal = 0;
        $saleDetails = \Modules\Sale\Entities\SaleDetails::whereIn('sale_id', $salesIds)->get();
        foreach ($saleDetails as $detail) {
            if ($detail->cost_total_snapshot !== null) {
                $salesCostTotal += $detail->cost_total_snapshot;
            } else {
                // Null snapshot on finalized sale is treated as zero (not fallback to current average)
                // This preserves historical stability and signals potential missing snapshot
                $salesCostTotal += 0;
            }
        }

        // Calculate COGS Return from Sale Returns
        // For finalized sale returns, use original sale snapshots only (treat null as zero for stability)
        $saleReturnCostTotal = 0;
        $saleReturnDetails = \Modules\SalesReturn\Entities\SaleReturnDetail::whereIn('sale_return_id', $saleReturnsIds)
            ->with(['saleDetail', 'product', 'saleReturn'])
            ->get();
        foreach ($saleReturnDetails as $detail) {
            if ($detail->saleDetail && $detail->saleDetail->cost_unit_snapshot !== null) {
                $saleReturnCostTotal += $detail->saleDetail->cost_unit_snapshot * $detail->quantity;
            } else {
                // Null snapshot on returned sale is treated as zero (not fallback to current average)
                // This preserves historical stability of returned items
                $saleReturnCostTotal += 0;
            }
        }

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
            (float) $salesCostTotal,
            (float) $saleReturnCostTotal,
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
