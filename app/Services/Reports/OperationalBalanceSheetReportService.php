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
        
        // 1. Calculate Cash/Bank (derived from payments and POS cross-owner payment allocations)
        // Inflows: Sale payments (adjusted for cross-owner transfers), Purchase return payments (refunds from supplier)
        $eligibleSaleStatuses = [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY, Sale::STATUS_RETURNED];

        $directSalePayments = (float) SalePayment::active()
            ->whereHas('sale', function ($q) use ($settingIds, $eligibleSaleStatuses) {
                $q->whereIn('setting_id', $settingIds)
                  ->whereIn('status', $eligibleSaleStatuses);
            })
            ->whereDate('date', '<=', $asOfDate)
            ->sum('amount');

        $incomingOwnerTransfers = (float) DB::table('pos_owner_payment_transfers as popt_in')
            ->join('sales as repl_sale', 'popt_in.replacement_sale_id', '=', 'repl_sale.id')
            ->join('sale_payments as sp_orig_in', 'popt_in.original_sale_payment_id', '=', 'sp_orig_in.id')
            ->where('sp_orig_in.status', SalePayment::STATUS_ACTIVE)
            ->whereIn('repl_sale.setting_id', $settingIds)
            ->whereIn('repl_sale.status', $eligibleSaleStatuses)
            ->whereDate('sp_orig_in.date', '<=', $asOfDate)
            ->sum('popt_in.amount');

        $outgoingOwnerTransfers = (float) DB::table('pos_owner_payment_transfers as popt_out')
            ->join('sales as src_sale', 'popt_out.source_sale_id', '=', 'src_sale.id')
            ->join('sale_payments as sp_orig_out', 'popt_out.original_sale_payment_id', '=', 'sp_orig_out.id')
            ->where('sp_orig_out.status', SalePayment::STATUS_ACTIVE)
            ->whereIn('src_sale.setting_id', $settingIds)
            ->whereIn('src_sale.status', $eligibleSaleStatuses)
            ->whereDate('sp_orig_out.date', '<=', $asOfDate)
            ->sum('popt_out.amount');

        $salePayments = round($directSalePayments + $incomingOwnerTransfers - $outgoingOwnerTransfers, 2);
            
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

        // Legacy payouts of completed returns + payouts linked to committed standard adjustments, each by its own date.
        $saleReturnPayoutQuery = SaleReturnPayment::query();
        \App\Support\SalesReturn\SaleAdjustmentSql::applyReportablePayouts($saleReturnPayoutQuery, $settingIds);
        $saleReturnPayments = $saleReturnPayoutQuery
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
        // Standard cash returns keep the sale total gross and carry their value in sale_return_adjustments
        // (committed by the date): subtract that value once, the refunded cash is already
        // inside $saleReturnPayments.
        // Only for the SAME sales that make up $salesTotal (setting, status, date, unarchived), recognized by the
        // adjustment's own commit date and independent of the return header's completion: an archived or
        // out-of-scope source can never cancel another sale's receivable.
        $adjustedValue = (float) \Illuminate\Support\Facades\DB::table('sale_return_adjustments as a')
            ->join('sales as s', 's.id', '=', 'a.sale_id')
            ->whereIn('s.setting_id', $settingIds)
            ->whereIn('s.status', [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY, Sale::STATUS_RETURNED])
            ->whereNull('s.archived_at')
            ->whereDate('s.date', '<=', $asOfDate)
            ->whereDate('a.committed_at', '<=', $asOfDate)
            ->sum('a.committed_value');
        $netReceivableCalc = $salesTotal - $adjustedValue - ($salePayments - $saleReturnPayments);
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
