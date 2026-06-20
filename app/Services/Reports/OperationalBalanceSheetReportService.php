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
    public function generate(int $settingId, ?string $asOfDate = null): OperationalBalanceSheetReport
    {
        $setting = Setting::with('currency')->find($settingId);
        $currencyCode = $setting && $setting->currency ? ($setting->currency->code ?? $setting->currency->currency_name ?? 'IDR') : 'IDR';
        
        $asOfDate = $asOfDate ?? now()->format('Y-m-d');
        
        // 1. Calculate Cash/Bank (derived from payments)
        // Inflows: Sale payments, Purchase return payments (refunds from supplier)
        $salePayments = SalePayment::active()
            ->whereHas('sale', function ($q) use ($settingId) {
                $q->where('setting_id', $settingId)
                  ->whereIn('status', [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY, Sale::STATUS_RETURNED]);
            })
            ->whereDate('date', '<=', $asOfDate)
            ->sum('amount');
            
        $purchaseReturnPayments = PurchaseReturnPayment::whereHas('purchaseReturn', function ($q) use ($settingId) {
                $q->where('setting_id', $settingId)
                  ->whereIn('status', ['Completed', 'COMPLETED']);
            })
            ->whereDate('date', '<=', $asOfDate)
            ->sum('amount');
            
        // Outflows: Purchase payments, Sale return payments (refunds to customer), Expenses
        $purchasePayments = PurchasePayment::active()
            ->whereHas('purchase', function ($q) use ($settingId) {
                $q->where('setting_id', $settingId)
                  ->whereIn('status', [Purchase::STATUS_RECEIVED, Purchase::STATUS_RETURNED_PARTIALLY, Purchase::STATUS_RETURNED]);
            })
            ->whereDate('date', '<=', $asOfDate)
            ->sum('amount');
            
        $saleReturnPayments = SaleReturnPayment::whereHas('saleReturn', function ($q) use ($settingId) {
                $q->where('setting_id', $settingId)
                  ->whereIn('status', ['Completed', 'COMPLETED']);
            })
            ->whereDate('date', '<=', $asOfDate)
            ->sum('amount');
            
        $expensesCentsTotal = Expense::activeApproved()
            ->where('setting_id', $settingId)
            ->whereDate('date', '<=', $asOfDate)
            ->sum('amount');
        $expensesTotal = $expensesCentsTotal / 100;
        
        $cashAndBank = $salePayments + $purchaseReturnPayments - $purchasePayments - $saleReturnPayments - $expensesTotal;

        // 2. Calculate Receivables
        $salesTotal = Sale::where('setting_id', $settingId)
            ->whereIn('status', [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY, Sale::STATUS_RETURNED])
            ->whereDate('date', '<=', $asOfDate)
            ->sum('total_amount');
            
        // Note: paid_amount on sale might include future payments, so we calculate paid up to asOfDate
        $receivables = max(0, $salesTotal - $salePayments);
        
        // 3. Calculate Inventory Value
        $inventoryValue = $this->calculateInventoryValue($settingId, $asOfDate);
        
        // 4. Calculate Payables
        $purchasesTotal = Purchase::where('setting_id', $settingId)
            ->whereIn('status', [Purchase::STATUS_RECEIVED, Purchase::STATUS_RETURNED_PARTIALLY, Purchase::STATUS_RETURNED])
            ->whereDate('date', '<=', $asOfDate)
            ->sum('total_amount');
            
        $payables = max(0, $purchasesTotal - $purchasePayments);
        
        // 5. Setup Report Rows & Sections
        $assetRows = [
            new OperationalBalanceSheetRow('Kas & Bank dari Transaksi', (float) $cashAndBank),
            new OperationalBalanceSheetRow('Piutang Usaha', (float) $receivables),
            new OperationalBalanceSheetRow('Persediaan Barang', (float) $inventoryValue),
        ];
        $assetsSection = new OperationalBalanceSheetSection('Aset', $assetRows);
        
        $liabilityRows = [
            new OperationalBalanceSheetRow('Hutang Usaha', (float) $payables),
        ];
        $liabilitiesSection = new OperationalBalanceSheetSection('Liabilitas', $liabilityRows);
        
        $equityTotal = $assetsSection->total - $liabilitiesSection->total;
        $equityRows = [
            new OperationalBalanceSheetRow('Modal / Ekuitas', (float) $equityTotal),
        ];
        $equitySection = new OperationalBalanceSheetSection('Modal', $equityRows);
        
        $sourceNote = '* Laporan ini dihitung dari nilai dokumen operasional (penjualan, pembelian, pembayaran) dan tidak menggunakan pencatatan jurnal akuntansi ganda.';
        
        return new OperationalBalanceSheetReport(
            $currencyCode,
            $asOfDate,
            $sourceNote,
            $assetsSection,
            $liabilitiesSection,
            $equitySection
        );
    }
    
    protected function calculateInventoryValue(int $settingId, string $asOfDate): float
    {
        // First-version inventory valuation: encapsulates the calculation.
        // We use current product_quantity * product_cost to approximate.
        // Can be hardened later with historical valuation.
        
        $valueCents = Product::where('setting_id', $settingId)
            ->where('stock_managed', true)
            ->sum(DB::raw('product_quantity * product_cost'));
            
        return (float) ($valueCents / 100);
    }
}
