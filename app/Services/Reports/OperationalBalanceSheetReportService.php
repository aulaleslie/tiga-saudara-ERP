<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;
use Modules\Expense\Entities\Expense;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnPayment;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Modules\Setting\Entities\Setting;
use Modules\Product\Entities\Product;

class OperationalBalanceSheetReportService
{
    public function generate(int|array $settingScope, ?string $asOfDate = null): OperationalBalanceSheetReport
    {
        $settingIds = is_array($settingScope) ? $settingScope : [$settingScope];
        $firstSettingId = $settingIds[0] ?? session('setting_id');
        $setting = Setting::with('currency')->find($firstSettingId);
        $currencyCode = $setting && $setting->currency ? ($setting->currency->code ?? $setting->currency->currency_name ?? 'IDR') : 'IDR';
        
        $asOfDate = $asOfDate ?? now()->format('Y-m-d');
        
        // 1. Calculate Cash/Bank (derived from payments)
        // Inflows: Sale payments, Purchase return payments (refunds from supplier)
        $salePayments = SalePayment::active()
            ->whereHas('sale', function ($q) use ($settingIds) {
                $q->whereIn('setting_id', $settingIds)
                  ->whereIn('status', [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY, Sale::STATUS_RETURNED]);
            })
            ->whereDate('date', '<=', $asOfDate)
            ->sum('amount');
            
        $purchaseReturnPayments = PurchaseReturnPayment::whereHas('purchaseReturn', function ($q) use ($settingIds) {
                $q->whereIn('setting_id', $settingIds)
                  ->whereIn('status', ['Completed', 'COMPLETED']);
            })
            ->whereDate('date', '<=', $asOfDate)
            ->sum('amount');
            
        // Outflows: Purchase payments, Sale return payments (refunds to customer), Expenses
        $purchasePayments = PurchasePayment::active()
            ->whereHas('purchase', function ($q) use ($settingIds) {
                $q->whereIn('setting_id', $settingIds)
                  ->whereIn('status', [Purchase::STATUS_RECEIVED, Purchase::STATUS_RETURNED_PARTIALLY, Purchase::STATUS_RETURNED]);
            })
            ->whereDate('date', '<=', $asOfDate)
            ->sum('amount');

        $saleReturnPayments = SaleReturnPayment::whereHas('saleReturn', function ($q) use ($settingIds) {
                $q->whereIn('setting_id', $settingIds)
                  ->whereIn('status', ['Completed', 'COMPLETED']);
            })
            ->whereDate('date', '<=', $asOfDate)
            ->sum('amount');

        $expensesTotal = Expense::activeApproved()
            ->whereIn('setting_id', $settingIds)
            ->whereDate('date', '<=', $asOfDate)
            ->sum('amount');
        
        $cashAndBank = $salePayments + $purchaseReturnPayments - $purchasePayments - $saleReturnPayments - $expensesTotal;

        // 2. Calculate Receivables
        $salesTotal = Sale::whereIn('setting_id', $settingIds)
            ->whereIn('status', [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY, Sale::STATUS_RETURNED])
            ->whereDate('date', '<=', $asOfDate)
            ->sum('total_amount');
            
        // Note: paid_amount on sale might include future payments, so we calculate paid up to asOfDate
        // As per new operational reporting rules, we do not subtract sale returns from Receivables here
        // to avoid double-reducing when the source sale document is already corrected to its final amount.
        $netReceivableCalc = $salesTotal - ($salePayments - $saleReturnPayments);
        $receivables = max(0, $netReceivableCalc);
        $customerRefundLiability = max(0, -$netReceivableCalc);
        
        // 3. Calculate Inventory Value
        $inventoryValue = $this->calculateInventoryValue($settingScope, $asOfDate);
        
        // 4. Calculate Payables
        $purchasesTotal = Purchase::whereIn('setting_id', $settingIds)
            ->whereIn('status', [Purchase::STATUS_RECEIVED, Purchase::STATUS_RETURNED_PARTIALLY, Purchase::STATUS_RETURNED])
            ->whereDate('date', '<=', $asOfDate)
            ->sum('total_amount');
            
        $completedPurchaseReturns = PurchaseReturn::whereIn('setting_id', $settingIds)
            ->whereIn('status', ['Completed', 'COMPLETED'])
            ->whereDate('date', '<=', $asOfDate)
            ->sum('total_amount');
            
        $taxPayable = Sale::whereIn('setting_id', $settingIds)
            ->whereIn('status', [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY, Sale::STATUS_RETURNED])
            ->whereDate('date', '<=', $asOfDate)
            ->sum('tax_amount');

        $taxReceivable = Purchase::whereIn('setting_id', $settingIds)
            ->whereIn('status', [Purchase::STATUS_RECEIVED, Purchase::STATUS_RETURNED_PARTIALLY, Purchase::STATUS_RETURNED])
            ->whereDate('date', '<=', $asOfDate)
            ->sum('tax_amount');

        $payables = max(0, $purchasesTotal - $completedPurchaseReturns - $purchasePayments);
        
        // 5. Setup Report Rows & Sections
        $assetRows = [
            new OperationalBalanceSheetRow('Kas & Bank dari Transaksi', (float) $cashAndBank),
            new OperationalBalanceSheetRow('Piutang Usaha', (float) $receivables),
            new OperationalBalanceSheetRow('Persediaan Barang', (float) $inventoryValue),
        ];
        
        if ($taxReceivable > 0) {
            $assetRows[] = new OperationalBalanceSheetRow('PPN Masukan', (float) $taxReceivable);
        }
        
        $assetsSection = new OperationalBalanceSheetSection('Aset', $assetRows);
        
        $liabilityRows = [
            new OperationalBalanceSheetRow('Hutang Usaha', (float) $payables),
        ];

        if ($taxPayable > 0) {
            $liabilityRows[] = new OperationalBalanceSheetRow('PPN Keluaran', (float) $taxPayable);
        }

        if ($customerRefundLiability > 0) {
            $liabilityRows[] = new OperationalBalanceSheetRow('Hutang Retur Pelanggan', (float) $customerRefundLiability);
        }

        $liabilitiesSection = new OperationalBalanceSheetSection('Liabilitas', $liabilityRows);
        
        // 6. Calculate Earnings for Equity
        $profitService = app(\App\Services\Reports\OperationalProfitLossReportService::class);
        $asOfDateCarbon = \Carbon\Carbon::parse($asOfDate);
        
        $endOfLastYear = $asOfDateCarbon->copy()->subYear()->endOfYear()->format('Y-m-d');
        $lastYearReport = $profitService->generate($settingIds, null, $endOfLastYear);
        $pendapatanTahunLalu = $lastYearReport->labaRugi;
        
        $startOfThisYear = $asOfDateCarbon->copy()->startOfYear()->format('Y-m-d');
        $thisYearReport = $profitService->generate($settingIds, $startOfThisYear, $asOfDate);
        $pendapatanPeriodeIni = $thisYearReport->labaRugi;
        
        $equityTotal = $assetsSection->total - $liabilitiesSection->total;
        $modalEkuitas = $equityTotal - $pendapatanTahunLalu - $pendapatanPeriodeIni;
        
        $equityRows = [
            new OperationalBalanceSheetRow('Modal / Ekuitas', (float) $modalEkuitas),
            new OperationalBalanceSheetRow('Pendapatan sampai Tahun lalu', (float) $pendapatanTahunLalu),
            new OperationalBalanceSheetRow('Pendapatan Periode ini', (float) $pendapatanPeriodeIni),
        ];
        $equitySection = new OperationalBalanceSheetSection('Modal', $equityRows);
        
        $sourceNote = '* Laporan ini dihitung dari nilai dokumen operasional (penjualan, pembelian, pembayaran) dan tidak menggunakan pencatatan jurnal akuntansi ganda. Perlu diketahui bahwa nilai persediaan barang dihitung dari reka ulang transaksi stok historis dengan menggunakan rata-rata harga pokok saat ini (average-cost transaction replay), bukan penilaian akuntansi penuh pada tanggal as-of.';
        
        return new OperationalBalanceSheetReport(
            $currencyCode,
            $asOfDate,
            $sourceNote,
            $assetsSection,
            $liabilitiesSection,
            $equitySection
        );
    }
    
    protected function calculateInventoryValue(int|array $settingScope, string $asOfDate): float
    {
        $valuationService = app(\App\Services\Reports\WarehouseStockValuationReportQueryService::class);
        
        return $valuationService->sumInventoryValueAsOf($settingScope, $asOfDate);
    }
}
