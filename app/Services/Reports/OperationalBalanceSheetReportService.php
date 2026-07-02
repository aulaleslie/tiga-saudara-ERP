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
            
        $legacyPayments = PurchaseReturnPayment::with('purchaseReturn:id,created_at')
            ->whereHas('purchaseReturn', function ($q) use ($settingIds) {
                $q->whereIn('setting_id', $settingIds)
                  ->whereIn('status', ['Completed', 'COMPLETED'])
                  ->whereDoesntHave('purchaseReturnDetails', function ($q2) {
                      $q2->whereNotNull('location_id');
                  });
            })
            ->whereDate('date', '<=', $asOfDate)
            ->get(['id', 'amount', 'created_at', 'updated_at', 'reference', 'purchase_return_id']);

        $legacyCentsSum = 0;
        $legacyDecimalSum = 0;

        foreach ($legacyPayments as $payment) {
            // Initial payments (cents) are created in the same transaction as the parent return.
            $isInitialPayment = $payment->created_at->diffInSeconds($payment->purchaseReturn->created_at) <= 2;
            // Settlement payments (cents) always have 'PAY-RET/' reference.
            $isSettlementPayment = str_starts_with($payment->reference, 'PAY-RET/');

            // If a cents-scaled payment was later edited through the UI, the controller stores
            // the new amount in true decimal format, updating the updated_at timestamp.
            $isEdited = $payment->updated_at->diffInSeconds($payment->created_at) > 0;

            // Settlement payments (PAY-RET/) are always cents, even if incremented (edited) later.
            // Initial payments are cents only if they haven't been manually edited to decimal via UI.
            if ($isSettlementPayment || ($isInitialPayment && !$isEdited)) {
                $legacyCentsSum += $payment->amount;
            } else {
                // Manual payments (decimals) are added later by a user and don't have the settlement prefix.
                // Edited initial cents payments also become decimals.
                $legacyDecimalSum += $payment->amount;
            }
        }
        
        $purchaseReturnPaymentsLegacyScaled = ($legacyCentsSum / 100) + $legacyDecimalSum;
            
        $purchaseReturnPaymentsLivewire = PurchaseReturnPayment::whereHas('purchaseReturn', function ($q) use ($settingIds) {
                $q->whereIn('setting_id', $settingIds)
                  ->whereIn('status', ['Completed', 'COMPLETED'])
                  ->whereHas('purchaseReturnDetails', function ($q2) {
                      $q2->whereNotNull('location_id');
                  });
            })
            ->whereDate('date', '<=', $asOfDate)
            ->sum('amount');
            
        $purchaseReturnPayments = $purchaseReturnPaymentsLegacyScaled + $purchaseReturnPaymentsLivewire;
            
        // Outflows: Purchase payments, Sale return payments (refunds to customer), Expenses
        $purchasePaymentsCents = PurchasePayment::active()
            ->whereHas('purchase', function ($q) use ($settingIds) {
                $q->whereIn('setting_id', $settingIds)
                  ->whereIn('status', [Purchase::STATUS_RECEIVED, Purchase::STATUS_RETURNED_PARTIALLY, Purchase::STATUS_RETURNED]);
            })
            ->whereDate('date', '<=', $asOfDate)
            ->sum('amount');
        $purchasePayments = $purchasePaymentsCents / 100;
            
        $saleReturnPaymentsCents = SaleReturnPayment::whereHas('saleReturn', function ($q) use ($settingIds) {
                $q->whereIn('setting_id', $settingIds)
                  ->whereIn('status', ['Completed', 'COMPLETED']);
            })
            ->whereDate('date', '<=', $asOfDate)
            ->sum('amount');
        $saleReturnPayments = $saleReturnPaymentsCents / 100;
            
        $expensesCentsTotal = Expense::activeApproved()
            ->whereIn('setting_id', $settingIds)
            ->whereDate('date', '<=', $asOfDate)
            ->sum('amount');
        $expensesTotal = $expensesCentsTotal / 100;
        
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
            
        $completedPurchaseReturnsRecords = PurchaseReturn::whereIn('setting_id', $settingIds)
            ->whereIn('status', ['Completed', 'COMPLETED'])
            ->whereDate('date', '<=', $asOfDate)
            ->withExists(['purchaseReturnDetails as is_livewire' => function ($q) {
                $q->whereNotNull('location_id');
            }])
            ->get(['id', 'total_amount', 'return_type', 'payment_method']);
            
        $completedPurchaseReturns = $completedPurchaseReturnsRecords->sum(function ($pr) {
            // Legacy records stored total_amount as * 100.
            // The Livewire flow requires location_id on details, while the Legacy controller never maps it.
            // Even if a legacy return later gets settlement items, its original amounts remain * 100.
            // We use the presence of location_id on details as a durable structural discriminator.
            $isLegacy = ! $pr->is_livewire;
            if ($isLegacy) {
                return (float) $pr->total_amount / 100;
            }
            return (float) $pr->total_amount;
        });
            
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
        $settingIds = is_array($settingScope) ? $settingScope : [$settingScope];
        
        $totalValue = 0.0;
        $valuationService = app(\App\Services\Reports\WarehouseStockValuationReportQueryService::class);
        
        foreach ($settingIds as $settingId) {
            $filter = new \App\Services\Reports\WarehouseStockValuationReportFilterData(
                asOfDate: $asOfDate,
                scopeSettingId: $settingId
            );
            $results = $valuationService->build($filter);
            $totalValue += $results->sum('stock_value');
        }
            
        return $totalValue;
    }
}
