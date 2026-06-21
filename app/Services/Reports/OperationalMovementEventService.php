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
use Carbon\Carbon;

class OperationalMovementEventService
{
    /**
     * Get normalized operational movement events for a setting up to an end date.
     * Events use OperationalGeneralLedgerBucketConfig keys for buckets.
     *
     * @return array<array{bucket: string, dt: string, sourceType: string, reference: string, description: string, debit: float, credit: float, tag: ?string}>
     */
    public function getMovementEvents(int $settingId, string $endDate): array
    {
        $events = [];

        // 1. Sales -> Revenue (Cr) & AR (Dr)
        $sales = Sale::where('setting_id', $settingId)
            ->whereIn('status', [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY, Sale::STATUS_RETURNED])
            ->whereDate('date', '<=', $endDate)
            ->get(['date', 'reference', 'total_amount', 'customer_name', 'created_at']);
        
        foreach ($sales as $sale) {
            $amount = (float) $sale->total_amount;
            $date = Carbon::parse($sale->getRawOriginal('date'))->format('Y-m-d');
            $time = $sale->created_at->format('H:i:s');
            $dt = $date . ' ' . $time;
            
            // Debit AR
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE, $dt, 'Penjualan', $sale->reference, 'Faktur Penjualan', $amount, 0, $sale->customer_name);
            // Credit Revenue
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::OPERATIONAL_REVENUE, $dt, 'Penjualan', $sale->reference, 'Pendapatan Penjualan', 0, $amount, $sale->customer_name);
        }

        // 2. Sale Payments -> Cash (Dr) & AR (Cr)
        $salePayments = SalePayment::active()
            ->whereDate('date', '<=', $endDate)
            ->whereHas('sale', function ($q) use ($settingId) {
                $q->where('setting_id', $settingId)
                  ->whereIn('status', [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY, Sale::STATUS_RETURNED]);
            })
            ->with('sale:id,customer_name')
            ->get(['date', 'reference', 'amount', 'sale_id', 'created_at', 'payment_method']);
            
        foreach ($salePayments as $payment) {
            $amount = (float) $payment->amount;
            $date = Carbon::parse($payment->getRawOriginal('date'))->format('Y-m-d');
            $time = $payment->created_at->format('H:i:s');
            $dt = $date . ' ' . $time;
            $tag = $payment->sale->customer_name ?? null;
            $desc = 'Pembayaran Penjualan' . ($payment->payment_method ? ' - ' . $payment->payment_method : '');
            
            // Debit Cash
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::CASH_BANK, $dt, 'Pembayaran Penjualan', $payment->reference, $desc, $amount, 0, $tag);
            // Credit AR
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE, $dt, 'Pembayaran Penjualan', $payment->reference, 'Pembayaran Piutang', 0, $amount, $tag);
        }

        // 3. Sale Returns -> AR (Cr) & Returns (Dr)
        $saleReturns = SaleReturn::where('setting_id', $settingId)
            ->whereIn('status', ['Completed', 'COMPLETED'])
            ->whereDate('date', '<=', $endDate)
            ->get(['date', 'reference', 'total_amount', 'customer_name', 'created_at']);
            
        foreach ($saleReturns as $sr) {
            $amount = (float) $sr->total_amount;
            $date = Carbon::parse($sr->getRawOriginal('date'))->format('Y-m-d');
            $time = $sr->created_at->format('H:i:s');
            $dt = $date . ' ' . $time;
            
            // Debit Returns
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::RETURNS_AND_ADJUSTMENTS, $dt, 'Retur Penjualan', $sr->reference, 'Retur Penjualan', $amount, 0, $sr->customer_name);
            // Credit AR
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE, $dt, 'Retur Penjualan', $sr->reference, 'Pengurangan Piutang', 0, $amount, $sr->customer_name);
        }

        // 4. Sale Return Payments -> Cash (Cr) & AR (Dr)
        $saleReturnPayments = SaleReturnPayment::whereHas('saleReturn', function ($q) use ($settingId) {
                $q->where('setting_id', $settingId)
                  ->whereIn('status', ['Completed', 'COMPLETED']);
            })
            ->whereDate('date', '<=', $endDate)
            ->with('saleReturn:id,customer_name')
            ->get(['date', 'reference', 'amount', 'sale_return_id', 'created_at', 'payment_method']);
            
        foreach ($saleReturnPayments as $srp) {
            $amount = (float) $srp->amount; // Accessor returns decimal
            $date = Carbon::parse($srp->getRawOriginal('date'))->format('Y-m-d');
            $time = $srp->created_at->format('H:i:s');
            $dt = $date . ' ' . $time;
            $tag = $srp->saleReturn->customer_name ?? null;
            $desc = 'Pengembalian Dana Retur' . ($srp->payment_method ? ' - ' . $srp->payment_method : '');
            
            // Credit Cash
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::CASH_BANK, $dt, 'Pengembalian Dana', $srp->reference, $desc, 0, $amount, $tag);
            // Debit AR
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE, $dt, 'Pengembalian Dana', $srp->reference, 'Pengembalian Dana Retur', $amount, 0, $tag);
        }

        // 5. Purchases -> AP (Cr) & Cost (Dr)
        $purchases = Purchase::where('setting_id', $settingId)
            ->whereIn('status', [Purchase::STATUS_RECEIVED, Purchase::STATUS_RETURNED_PARTIALLY, Purchase::STATUS_RETURNED])
            ->whereDate('date', '<=', $endDate)
            ->with('supplier:id,supplier_name')
            ->get(['date', 'reference', 'total_amount', 'supplier_id', 'created_at']);
            
        foreach ($purchases as $purchase) {
            $amount = (float) $purchase->total_amount;
            $date = Carbon::parse($purchase->getRawOriginal('date'))->format('Y-m-d');
            $time = $purchase->created_at->format('H:i:s');
            $dt = $date . ' ' . $time;
            $tag = $purchase->supplier->supplier_name ?? null;
            
            // Debit Cost
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::OPERATIONAL_COST, $dt, 'Pembelian', $purchase->reference, 'Faktur Pembelian', $amount, 0, $tag);
            // Credit AP
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::ACCOUNTS_PAYABLE, $dt, 'Pembelian', $purchase->reference, 'Hutang Pembelian', 0, $amount, $tag);
        }

        // 6. Purchase Payments -> Cash (Cr) & AP (Dr)
        $purchasePayments = PurchasePayment::active()
            ->whereDate('date', '<=', $endDate)
            ->whereHas('purchase', function ($q) use ($settingId) {
                $q->where('setting_id', $settingId)
                  ->whereIn('status', [Purchase::STATUS_RECEIVED, Purchase::STATUS_RETURNED_PARTIALLY, Purchase::STATUS_RETURNED]);
            })
            ->with(['purchase:id,supplier_id', 'purchase.supplier:id,supplier_name'])
            ->get(['date', 'reference', 'amount', 'purchase_id', 'created_at', 'payment_method']);
            
        foreach ($purchasePayments as $pp) {
            $amount = (float) $pp->amount; // Accessor returns decimal
            $date = Carbon::parse($pp->getRawOriginal('date'))->format('Y-m-d');
            $time = $pp->created_at->format('H:i:s');
            $dt = $date . ' ' . $time;
            $tag = $pp->purchase->supplier->supplier_name ?? null;
            $desc = 'Pembayaran Pembelian' . ($pp->payment_method ? ' - ' . $pp->payment_method : '');
            
            // Credit Cash
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::CASH_BANK, $dt, 'Pembayaran Pembelian', $pp->reference, $desc, 0, $amount, $tag);
            // Debit AP
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::ACCOUNTS_PAYABLE, $dt, 'Pembayaran Pembelian', $pp->reference, 'Pembayaran Hutang', $amount, 0, $tag);
        }

        // 7. Purchase Returns -> AP (Dr) & Returns (Cr)
        $purchaseReturns = PurchaseReturn::where('setting_id', $settingId)
            ->whereIn('status', ['Completed', 'COMPLETED'])
            ->whereDate('date', '<=', $endDate)
            ->withExists(['purchaseReturnDetails as is_livewire' => function ($q) {
                $q->whereNotNull('location_id');
            }])
            ->get(['id', 'date', 'reference', 'total_amount', 'supplier_name', 'created_at']);
            
        foreach ($purchaseReturns as $pr) {
            $isLegacy = ! $pr->is_livewire;
            $amount = $isLegacy ? ((float) $pr->total_amount / 100) : (float) $pr->total_amount;
            $date = Carbon::parse($pr->getRawOriginal('date'))->format('Y-m-d');
            $time = $pr->created_at->format('H:i:s');
            $dt = $date . ' ' . $time;
            
            // Debit AP
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::ACCOUNTS_PAYABLE, $dt, 'Retur Pembelian', $pr->reference, 'Pengurangan Hutang', $amount, 0, $pr->supplier_name);
            // Credit Returns
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::RETURNS_AND_ADJUSTMENTS, $dt, 'Retur Pembelian', $pr->reference, 'Retur Pembelian', 0, $amount, $pr->supplier_name);
        }

        // 8. Purchase Return Payments -> Cash (Dr) & AP (Cr)
        $legacyPayments = PurchaseReturnPayment::with('purchaseReturn:id,supplier_name,created_at')
            ->whereHas('purchaseReturn', function ($q) use ($settingId) {
                $q->where('setting_id', $settingId)
                  ->whereIn('status', ['Completed', 'COMPLETED'])
                  ->whereDoesntHave('purchaseReturnDetails', function ($q2) {
                      $q2->whereNotNull('location_id');
                  });
            })
            ->whereDate('date', '<=', $endDate)
            ->get(['id', 'amount', 'date', 'created_at', 'updated_at', 'reference', 'purchase_return_id', 'payment_method']);

        foreach ($legacyPayments as $payment) {
            $isInitialPayment = $payment->created_at->diffInSeconds($payment->purchaseReturn->created_at) <= 2;
            $isSettlementPayment = str_starts_with($payment->reference, 'PAY-RET/');
            $isEdited = $payment->updated_at->diffInSeconds($payment->created_at) > 0;

            if ($isSettlementPayment || ($isInitialPayment && !$isEdited)) {
                $amount = (float) $payment->amount / 100;
            } else {
                $amount = (float) $payment->amount;
            }
            
            $date = Carbon::parse($payment->getRawOriginal('date'))->format('Y-m-d');
            $time = $payment->created_at->format('H:i:s');
            $dt = $date . ' ' . $time;
            $tag = $payment->purchaseReturn->supplier_name ?? null;
            $desc = 'Penerimaan Dana Retur' . ($payment->payment_method ? ' - ' . $payment->payment_method : '');
            
            // Debit Cash
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::CASH_BANK, $dt, 'Penerimaan Dana', $payment->reference, $desc, $amount, 0, $tag);
            // Credit AP
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::ACCOUNTS_PAYABLE, $dt, 'Penerimaan Dana', $payment->reference, 'Penerimaan Dana Retur', 0, $amount, $tag);
        }

        $livewirePayments = PurchaseReturnPayment::whereHas('purchaseReturn', function ($q) use ($settingId) {
                $q->where('setting_id', $settingId)
                  ->whereIn('status', ['Completed', 'COMPLETED'])
                  ->whereHas('purchaseReturnDetails', function ($q2) {
                      $q2->whereNotNull('location_id');
                  });
            })
            ->whereDate('date', '<=', $endDate)
            ->with('purchaseReturn:id,supplier_name')
            ->get(['id', 'amount', 'date', 'created_at', 'reference', 'purchase_return_id', 'payment_method']);
            
        foreach ($livewirePayments as $payment) {
            $amount = (float) $payment->amount;
            $date = Carbon::parse($payment->getRawOriginal('date'))->format('Y-m-d');
            $time = $payment->created_at->format('H:i:s');
            $dt = $date . ' ' . $time;
            $tag = $payment->purchaseReturn->supplier_name ?? null;
            $desc = 'Penerimaan Dana Retur' . ($payment->payment_method ? ' - ' . $payment->payment_method : '');
            
            // Debit Cash
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::CASH_BANK, $dt, 'Penerimaan Dana', $payment->reference, $desc, $amount, 0, $tag);
            // Credit AP
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::ACCOUNTS_PAYABLE, $dt, 'Penerimaan Dana', $payment->reference, 'Penerimaan Dana Retur', 0, $amount, $tag);
        }

        // 9. Expenses -> Cost (Dr) & Cash (Cr)
        $expenses = Expense::activeApproved()
            ->where('setting_id', $settingId)
            ->whereDate('date', '<=', $endDate)
            ->with('category:id,category_name')
            ->get(['date', 'reference', 'amount', 'details', 'created_at', 'category_id']);
            
        foreach ($expenses as $expense) {
            $amount = (float) $expense->amount; // Accessor returns decimal
            $date = Carbon::parse($expense->getRawOriginal('date'))->format('Y-m-d');
            $time = $expense->created_at->format('H:i:s');
            $dt = $date . ' ' . $time;
            $tag = $expense->category->category_name ?? null;
            
            // Debit Cost
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::OPERATIONAL_COST, $dt, 'Pengeluaran', $expense->reference, $expense->details ?: 'Biaya Operasional', $amount, 0, $tag);
            // Credit Cash
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::CASH_BANK, $dt, 'Pengeluaran', $expense->reference, 'Pembayaran Biaya', 0, $amount, $tag);
        }

        return $events;
    }
    
    private function makeEvent(string $bucket, string $dt, string $sourceType, string $reference, string $description, float $debit, float $credit, ?string $tag): array
    {
        return [
            'bucket' => $bucket,
            'dt' => $dt,
            'sourceType' => $sourceType,
            'reference' => $reference,
            'description' => $description,
            'debit' => $debit,
            'credit' => $credit,
            'tag' => $tag,
        ];
    }
}
