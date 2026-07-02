<?php

namespace App\Services\Reports;

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

class OperationalCashFlowReportService
{
    public function generate(int|array $settingScope, OperationalCashFlowReportFilterData $filter): OperationalCashFlowReport
    {
        $settingIds = is_array($settingScope) ? $settingScope : [$settingScope];
        $firstSettingId = $settingIds[0] ?? session('setting_id');
        $setting = Setting::with('currency')->find($firstSettingId);
        $currencyCode = $setting && $setting->currency ? ($setting->currency->code ?? $setting->currency->currency_name ?? 'IDR') : 'IDR';
        
        $startDate = $filter->startDate;
        $endDate = $filter->endDate;
        $startDateStr = $startDate ? \Carbon\Carbon::parse($startDate)->format('d M Y') : 'Awal';
        $endDateStr = $endDate ? \Carbon\Carbon::parse($endDate)->format('d M Y') : 'Sekarang';
        $periodLabel = "{$startDateStr} - {$endDateStr}";

        // Calculate period movements (between startDate and endDate)
        $periodSalePayments = $this->getSalePayments($settingScope, $startDate, $endDate);
        $periodPurchasePayments = $this->getPurchasePayments($settingScope, $startDate, $endDate);
        $periodSaleReturnPayments = $this->getSaleReturnPayments($settingScope, $startDate, $endDate);
        $periodPurchaseReturnPayments = $this->getPurchaseReturnPayments($settingScope, $startDate, $endDate);
        $periodExpenses = $this->getExpenses($settingScope, $startDate, $endDate);

        // Operating activities section
        $operatingRows = [
            new OperationalCashFlowRow('Penerimaan dari pelanggan', $periodSalePayments),
            new OperationalCashFlowRow('Pembayaran ke pemasok', -$periodPurchasePayments),
            new OperationalCashFlowRow('Aset lancar lainnya', $periodPurchaseReturnPayments),
            new OperationalCashFlowRow('Kartu kredit dan liabilitas jangka pendek lainnya', -$periodSaleReturnPayments),
            new OperationalCashFlowRow('Pendapatan lainnya', 0.0),
            new OperationalCashFlowRow('Pengeluaran operasional', -$periodExpenses),
        ];
        $operatingActivities = new OperationalCashFlowSection('Arus Kas dari Aktivitas Operasional', $operatingRows);

        // Investing activities section (Placeholders)
        $investingRows = [
            new OperationalCashFlowRow('Perolehan/Penjualan aset', 0.0),
            new OperationalCashFlowRow('Aktivitas investasi lainnya', 0.0),
        ];
        $investingActivities = new OperationalCashFlowSection('Arus Kas dari Aktivitas Investasi', $investingRows);

        // Financing activities section (Placeholders)
        $financingRows = [
            new OperationalCashFlowRow('Pembayaran/Penerimaan pinjaman', 0.0),
            new OperationalCashFlowRow('Ekuitas/Modal', 0.0),
        ];
        $financingActivities = new OperationalCashFlowSection('Arus Kas dari Aktivitas Pendanaan', $financingRows);

        // Net cash increase
        $netCashIncreaseAmount = $operatingActivities->total + $investingActivities->total + $financingActivities->total;
        $netCashIncrease = new OperationalCashFlowSummaryRow('Kenaikan (penurunan) kas', $netCashIncreaseAmount);

        // Calculate opening cash (strictly before startDate)
        $openingSalePayments = $this->getSalePayments($settingScope, null, $startDate);
        $openingPurchasePayments = $this->getPurchasePayments($settingScope, null, $startDate);
        $openingSaleReturnPayments = $this->getSaleReturnPayments($settingScope, null, $startDate);
        $openingPurchaseReturnPayments = $this->getPurchaseReturnPayments($settingScope, null, $startDate);
        $openingExpenses = $this->getExpenses($settingScope, null, $startDate);

        $openingCashAmount = $openingSalePayments 
            + $openingPurchaseReturnPayments 
            - $openingPurchasePayments 
            - $openingSaleReturnPayments 
            - $openingExpenses;
            
        $openingCash = new OperationalCashFlowSummaryRow('Saldo kas awal', $openingCashAmount);

        // Bank revaluation (Placeholder)
        $bankRevaluation = new OperationalCashFlowSummaryRow('Total revaluasi bank', 0.0);

        // Ending cash
        $endingCashAmount = $openingCashAmount + $netCashIncreaseAmount + $bankRevaluation->amount;
        $endingCash = new OperationalCashFlowSummaryRow('Saldo kas akhir', $endingCashAmount);

        $sourceNote = '* Laporan ini dihitung dari transaksi operasional (pembayaran penjualan, pembayaran pembelian, retur, beban) menggunakan basis kas (cash-basis). Laporan ini tidak mencakup pergerakan non-kas seperti pendapatan DPP atau Harga Pokok Penjualan (HPP), dan belum mencakup jurnal akuntansi lengkap, saldo buku besar bank, penyetoran modal, atau revaluasi bank sebenarnya.';

        return new OperationalCashFlowReport(
            $currencyCode,
            $periodLabel,
            $sourceNote,
            $operatingActivities,
            $investingActivities,
            $financingActivities,
            $netCashIncrease,
            $openingCash,
            $bankRevaluation,
            $endingCash
        );
    }

    protected function applyDateFilters($query, ?string $startDate, ?string $endDate)
    {
        if ($startDate !== null && $endDate !== null) {
            $query->whereDate('date', '>=', $startDate)->whereDate('date', '<=', $endDate);
        } elseif ($startDate === null && $endDate !== null) {
            $query->whereDate('date', '<', $endDate);
        }
        return $query;
    }

    protected function getSalePayments(int|array $settingScope, ?string $startDate, ?string $endDate): float
    {
        $settingIds = is_array($settingScope) ? $settingScope : [$settingScope];
        $query = SalePayment::active()
            ->whereHas('sale', function ($q) use ($settingIds) {
                $q->whereIn('setting_id', $settingIds)
                  ->whereIn('status', [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY, Sale::STATUS_RETURNED]);
            });
            
        return (float) $this->applyDateFilters($query, $startDate, $endDate)->sum('amount');
    }

    protected function getPurchasePayments(int|array $settingScope, ?string $startDate, ?string $endDate): float
    {
        $settingIds = is_array($settingScope) ? $settingScope : [$settingScope];
        $query = PurchasePayment::active()
            ->whereHas('purchase', function ($q) use ($settingIds) {
                $q->whereIn('setting_id', $settingIds)
                  ->whereIn('status', [Purchase::STATUS_RECEIVED, Purchase::STATUS_RETURNED_PARTIALLY, Purchase::STATUS_RETURNED]);
            });
            
        return (float) ($this->applyDateFilters($query, $startDate, $endDate)->sum('amount') / 100);
    }

    protected function getSaleReturnPayments(int|array $settingScope, ?string $startDate, ?string $endDate): float
    {
        $settingIds = is_array($settingScope) ? $settingScope : [$settingScope];
        $query = SaleReturnPayment::whereHas('saleReturn', function ($q) use ($settingIds) {
            $q->whereIn('setting_id', $settingIds)
              ->whereIn('status', ['Completed', 'COMPLETED']);
        });
        
        return (float) ($this->applyDateFilters($query, $startDate, $endDate)->sum('amount') / 100);
    }

    protected function getPurchaseReturnPayments(int|array $settingScope, ?string $startDate, ?string $endDate): float
    {
        $settingIds = is_array($settingScope) ? $settingScope : [$settingScope];
        $legacyQuery = PurchaseReturnPayment::with('purchaseReturn:id,created_at')
            ->whereHas('purchaseReturn', function ($q) use ($settingIds) {
                $q->whereIn('setting_id', $settingIds)
                  ->whereIn('status', ['Completed', 'COMPLETED'])
                  ->whereDoesntHave('purchaseReturnDetails', function ($q2) {
                      $q2->whereNotNull('location_id');
                  });
            });
            
        $legacyPayments = $this->applyDateFilters($legacyQuery, $startDate, $endDate)
            ->get(['id', 'amount', 'created_at', 'updated_at', 'reference', 'purchase_return_id']);

        $legacyCentsSum = 0;
        $legacyDecimalSum = 0;

        foreach ($legacyPayments as $payment) {
            $isInitialPayment = $payment->created_at->diffInSeconds($payment->purchaseReturn->created_at) <= 2;
            $isSettlementPayment = str_starts_with($payment->reference, 'PAY-RET/');
            $isEdited = $payment->updated_at->diffInSeconds($payment->created_at) > 0;

            if ($isSettlementPayment || ($isInitialPayment && !$isEdited)) {
                $legacyCentsSum += $payment->amount;
            } else {
                $legacyDecimalSum += $payment->amount;
            }
        }
        
        $purchaseReturnPaymentsLegacyScaled = ($legacyCentsSum / 100) + $legacyDecimalSum;
            
        $livewireQuery = PurchaseReturnPayment::whereHas('purchaseReturn', function ($q) use ($settingIds) {
                $q->whereIn('setting_id', $settingIds)
                  ->whereIn('status', ['Completed', 'COMPLETED'])
                  ->whereHas('purchaseReturnDetails', function ($q2) {
                      $q2->whereNotNull('location_id');
                  });
            });
            
        $purchaseReturnPaymentsLivewire = (float) $this->applyDateFilters($livewireQuery, $startDate, $endDate)->sum('amount');
            
        return $purchaseReturnPaymentsLegacyScaled + $purchaseReturnPaymentsLivewire;
    }

    protected function getExpenses(int|array $settingScope, ?string $startDate, ?string $endDate): float
    {
        $settingIds = is_array($settingScope) ? $settingScope : [$settingScope];
        $query = Expense::activeApproved()
            ->whereIn('setting_id', $settingIds);
            
        return (float) ($this->applyDateFilters($query, $startDate, $endDate)->sum('amount') / 100);
    }
}
