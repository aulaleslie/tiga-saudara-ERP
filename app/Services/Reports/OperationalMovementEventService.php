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
    public function getOpeningBalances(int|array $settingScope, string $startDate): array
    {
        $settingIds = $this->normalizeSettingIds($settingScope);
        $balances = [];

        $add = function (string $bucket, float $debit, float $credit) use (&$balances) {
            if (!isset($balances[$bucket])) {
                $balances[$bucket] = ['debit' => 0.0, 'credit' => 0.0];
            }
            $balances[$bucket]['debit'] += $debit;
            $balances[$bucket]['credit'] += $credit;
        };

        // 1. Sales
        $salesTotals = DB::table('sales')
            ->whereIn('setting_id', $settingIds)
            ->whereIn('status', [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY, Sale::STATUS_RETURNED])
            ->whereDate('date', '<', $startDate)
            ->selectRaw('SUM(total_amount) as amount, SUM(discount_amount) as discount, SUM(tax_amount) as tax, SUM(shipping_amount) as shipping')
            ->first();

        $salesDppHpp = DB::table('sale_details')
            ->join('sales', 'sales.id', '=', 'sale_details.sale_id')
            ->whereIn('sales.setting_id', $settingIds)
            ->whereIn('sales.status', [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY, Sale::STATUS_RETURNED])
            ->whereDate('sales.date', '<', $startDate)
            ->selectRaw('
                SUM(sale_details.sub_total - sale_details.product_tax_amount) as dpp,
                SUM(COALESCE(sale_details.cost_unit_snapshot, 0) * sale_details.quantity) as hpp
            ')
            ->first();

        if ($salesTotals && $salesTotals->amount > 0) {
            $add(OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE, (float)$salesTotals->amount, 0);
            if ($salesTotals->discount > 0) {
                $add(OperationalGeneralLedgerBucketConfig::OPERATIONAL_REVENUE, (float)$salesTotals->discount, 0);
            }
            if ($salesTotals->tax > 0) {
                $add(OperationalGeneralLedgerBucketConfig::TAX_PAYABLE, 0, (float)$salesTotals->tax);
            }
            if ($salesTotals->shipping > 0) {
                $add(OperationalGeneralLedgerBucketConfig::SHIPPING_REVENUE, 0, (float)$salesTotals->shipping);
            }
        }
        if ($salesDppHpp && $salesDppHpp->dpp > 0) {
            $add(OperationalGeneralLedgerBucketConfig::OPERATIONAL_REVENUE, 0, (float)$salesDppHpp->dpp);
        }
        if ($salesDppHpp && $salesDppHpp->hpp > 0) {
            $add(OperationalGeneralLedgerBucketConfig::OPERATIONAL_COST, (float)$salesDppHpp->hpp, 0);
            $add(OperationalGeneralLedgerBucketConfig::INVENTORY, 0, (float)$salesDppHpp->hpp);
        }

        // 2. Sale Payments
        $salePayments = DB::table('sale_payments')
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sale_payments.status', 'ACTIVE')
            ->whereIn('sales.setting_id', $settingIds)
            ->whereIn('sales.status', [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY, Sale::STATUS_RETURNED])
            ->whereDate('sale_payments.date', '<', $startDate)
            ->sum('sale_payments.amount');

        if ($salePayments > 0) {
            $add(OperationalGeneralLedgerBucketConfig::CASH_BANK, (float)$salePayments, 0);
            $add(OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE, 0, (float)$salePayments);
        }

        // 3. Sale Returns (No GL impact as per Laba Rugi logic)

        // 4. Sale Return Payments
        $saleReturnPayments = DB::table('sale_return_payments')
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_payments.sale_return_id')
            ->whereIn('sale_returns.setting_id', $settingIds)
            ->whereIn('sale_returns.status', ['Completed', 'COMPLETED'])
            ->whereDate('sale_return_payments.date', '<', $startDate)
            ->sum('sale_return_payments.amount');

        if ($saleReturnPayments > 0) {
            $add(OperationalGeneralLedgerBucketConfig::CASH_BANK, 0, (float)$saleReturnPayments);
            $add(OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE, (float)$saleReturnPayments, 0);
        }

        // 5. Purchases
        $purchases = DB::table('purchases')
            ->whereIn('setting_id', $settingIds)
            ->whereIn('status', [Purchase::STATUS_RECEIVED, Purchase::STATUS_RETURNED_PARTIALLY, Purchase::STATUS_RETURNED])
            ->whereDate('date', '<', $startDate)
            ->sum('total_amount');

        if ($purchases > 0) {
            $add(OperationalGeneralLedgerBucketConfig::INVENTORY, (float)$purchases, 0);
            $add(OperationalGeneralLedgerBucketConfig::ACCOUNTS_PAYABLE, 0, (float)$purchases);
        }

        // 6. Purchase Payments
        $purchasePayments = DB::table('purchase_payments')
            ->join('purchases', 'purchases.id', '=', 'purchase_payments.purchase_id')
            ->where('purchase_payments.status', 'ACTIVE')
            ->whereIn('purchases.setting_id', $settingIds)
            ->whereIn('purchases.status', [Purchase::STATUS_RECEIVED, Purchase::STATUS_RETURNED_PARTIALLY, Purchase::STATUS_RETURNED])
            ->whereDate('purchase_payments.date', '<', $startDate)
            ->sum('purchase_payments.amount');

        if ($purchasePayments > 0) {
            $add(OperationalGeneralLedgerBucketConfig::CASH_BANK, 0, (float)$purchasePayments);
            $add(OperationalGeneralLedgerBucketConfig::ACCOUNTS_PAYABLE, (float)$purchasePayments, 0);
        }

        // 7. Purchase Returns
        $purchaseReturns = PurchaseReturn::whereIn('setting_id', $settingIds)
            ->whereIn('status', ['Completed', 'COMPLETED'])
            ->whereDate('date', '<', $startDate)
            ->get(['id', 'total_amount']);

        foreach ($purchaseReturns as $pr) {
            $amount = (float) $pr->total_amount;
            if ($amount > 0) {
                $add(OperationalGeneralLedgerBucketConfig::ACCOUNTS_PAYABLE, $amount, 0);
                $add(OperationalGeneralLedgerBucketConfig::INVENTORY, 0, $amount);
            }
        }

        // 8. Purchase Return Payments
        $purchaseReturnPayments = PurchaseReturnPayment::whereHas('purchaseReturn', function ($q) use ($settingIds) {
                $q->whereIn('setting_id', $settingIds)
                  ->whereIn('status', ['Completed', 'COMPLETED']);
            })
            ->whereDate('date', '<', $startDate)
            ->get(['id', 'amount']);

        foreach ($purchaseReturnPayments as $payment) {
            $amount = (float) $payment->amount;
            if ($amount > 0) {
                $add(OperationalGeneralLedgerBucketConfig::CASH_BANK, $amount, 0);
                $add(OperationalGeneralLedgerBucketConfig::ACCOUNTS_PAYABLE, 0, $amount);
            }
        }

        // 9. Expenses
        $expenses = DB::table('expenses')
            ->whereIn('setting_id', $settingIds)
            ->whereNull('archived_at')
            ->where('status', Expense::STATUS_APPROVED)
            ->whereDate('date', '<', $startDate)
            ->sum('amount');

        if ($expenses > 0) {
            $add(OperationalGeneralLedgerBucketConfig::OPERATIONAL_COST, (float)$expenses, 0);
            $add(OperationalGeneralLedgerBucketConfig::CASH_BANK, 0, (float)$expenses);
        }

        return $balances;
    }

    public function getPeriodMovements(int|array $settingScope, string $startDate, string $endDate): array
    {
        $settingIds = $this->normalizeSettingIds($settingScope);
        $events = [];
        $endDateEndOfDay = $endDate . ' 23:59:59';

        // 1. Sales
        $sales = Sale::leftJoinSub(
                DB::table('sale_details')
                    ->selectRaw('sale_id, SUM(sub_total - product_tax_amount) as dpp, SUM(COALESCE(cost_unit_snapshot, 0) * quantity) as hpp')
                    ->groupBy('sale_id'),
                'details',
                'details.sale_id',
                '=',
                'sales.id'
            )
            ->whereIn('sales.setting_id', $settingIds)
            ->whereIn('sales.status', [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY, Sale::STATUS_RETURNED])
            ->whereBetween('sales.date', [$startDate, $endDateEndOfDay])
            ->with('customer:id,customer_name')
            ->select('sales.id', 'sales.date', 'sales.reference', 'sales.total_amount', 'sales.tax_amount', 'sales.discount_amount', 'sales.shipping_amount', 'sales.customer_id', 'sales.created_at', 'details.dpp', 'details.hpp')
            ->get();

        foreach ($sales as $sale) {
            $date = Carbon::parse($sale->getRawOriginal('date'))->format('Y-m-d');
            $time = $sale->created_at->format('H:i:s');
            $dt = $date . ' ' . $time;
            $tag = $sale->customer->customer_name ?? null;

            $dpp = (float) $sale->dpp;
            $hpp = (float) $sale->hpp;
            $taxAmount = (float) $sale->tax_amount;
            $discount = (float) $sale->discount_amount;
            $shipping = (float) $sale->shipping_amount;
            $total = (float) $sale->total_amount;

            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE, $dt, 'Penjualan', $sale->reference, 'Faktur Penjualan', $total, 0, $tag);
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::OPERATIONAL_REVENUE, $dt, 'Penjualan', $sale->reference, 'Pendapatan Penjualan', 0, $dpp, $tag);
            
            if ($taxAmount > 0) {
                $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::TAX_PAYABLE, $dt, 'Penjualan', $sale->reference, 'Pajak Penjualan', 0, $taxAmount, $tag);
            }
            if ($discount > 0) {
                $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::OPERATIONAL_REVENUE, $dt, 'Penjualan', $sale->reference, 'Diskon Penjualan', $discount, 0, $tag);
            }
            if ($shipping > 0) {
                $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::SHIPPING_REVENUE, $dt, 'Penjualan', $sale->reference, 'Pendapatan Pengiriman', 0, $shipping, $tag);
            }
            if ($hpp > 0) {
                $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::OPERATIONAL_COST, $dt, 'Penjualan', $sale->reference, 'HPP', $hpp, 0, $tag);
                $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::INVENTORY, $dt, 'Penjualan', $sale->reference, 'Pengurangan Persediaan', 0, $hpp, $tag);
            }
        }

        // 2. Sale Payments
        $salePayments = SalePayment::active()
            ->whereBetween('date', [$startDate, $endDateEndOfDay])
            ->whereHas('sale', function ($q) use ($settingIds) {
                $q->whereIn('setting_id', $settingIds)
                  ->whereIn('status', [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY, Sale::STATUS_RETURNED]);
            })
            ->with(['sale:id,customer_id', 'sale.customer:id,customer_name'])
            ->get(['date', 'reference', 'amount', 'sale_id', 'created_at', 'payment_method']);

        foreach ($salePayments as $payment) {
            $amount = (float) $payment->amount;
            $date = Carbon::parse($payment->getRawOriginal('date'))->format('Y-m-d');
            $time = $payment->created_at->format('H:i:s');
            $dt = $date . ' ' . $time;
            $tag = $payment->sale->customer->customer_name ?? null;
            $desc = 'Pembayaran Penjualan' . ($payment->payment_method ? ' - ' . $payment->payment_method : '');

            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::CASH_BANK, $dt, 'Pembayaran Penjualan', $payment->reference, $desc, $amount, 0, $tag);
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE, $dt, 'Pembayaran Penjualan', $payment->reference, 'Pembayaran Piutang', 0, $amount, $tag);
        }

        // 3. Sale Returns (Removed logic as per Laba Rugi)

        // 4. Sale Return Payments
        $saleReturnPayments = SaleReturnPayment::whereHas('saleReturn', function ($q) use ($settingIds) {
                $q->whereIn('setting_id', $settingIds)
                  ->whereIn('status', ['Completed', 'COMPLETED']);
            })
            ->whereBetween('date', [$startDate, $endDateEndOfDay])
            ->with('saleReturn:id,customer_name')
            ->get(['date', 'reference', 'amount', 'sale_return_id', 'created_at', 'payment_method']);

        foreach ($saleReturnPayments as $srp) {
            $amount = (float) $srp->amount;
            $date = Carbon::parse($srp->getRawOriginal('date'))->format('Y-m-d');
            $time = $srp->created_at->format('H:i:s');
            $dt = $date . ' ' . $time;
            $tag = $srp->saleReturn->customer_name ?? null;
            $desc = 'Pengembalian Dana Retur' . ($srp->payment_method ? ' - ' . $srp->payment_method : '');

            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::CASH_BANK, $dt, 'Pengembalian Dana', $srp->reference, $desc, 0, $amount, $tag);
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE, $dt, 'Pengembalian Dana', $srp->reference, 'Pengembalian Dana Retur', $amount, 0, $tag);
        }

        // 5. Purchases
        $purchases = Purchase::whereIn('setting_id', $settingIds)
            ->whereIn('status', [Purchase::STATUS_RECEIVED, Purchase::STATUS_RETURNED_PARTIALLY, Purchase::STATUS_RETURNED])
            ->whereBetween('date', [$startDate, $endDateEndOfDay])
            ->with('supplier:id,supplier_name')
            ->get(['date', 'reference', 'total_amount', 'supplier_id', 'created_at']);

        foreach ($purchases as $purchase) {
            $amount = (float) $purchase->total_amount;
            $date = Carbon::parse($purchase->getRawOriginal('date'))->format('Y-m-d');
            $time = $purchase->created_at->format('H:i:s');
            $dt = $date . ' ' . $time;
            $tag = $purchase->supplier->supplier_name ?? null;

            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::INVENTORY, $dt, 'Pembelian', $purchase->reference, 'Faktur Pembelian', $amount, 0, $tag);
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::ACCOUNTS_PAYABLE, $dt, 'Pembelian', $purchase->reference, 'Hutang Pembelian', 0, $amount, $tag);
        }

        // 6. Purchase Payments
        $purchasePayments = PurchasePayment::active()
            ->whereBetween('date', [$startDate, $endDateEndOfDay])
            ->whereHas('purchase', function ($q) use ($settingIds) {
                $q->whereIn('setting_id', $settingIds)
                  ->whereIn('status', [Purchase::STATUS_RECEIVED, Purchase::STATUS_RETURNED_PARTIALLY, Purchase::STATUS_RETURNED]);
            })
            ->with(['purchase:id,supplier_id', 'purchase.supplier:id,supplier_name'])
            ->get(['date', 'reference', 'amount', 'purchase_id', 'created_at', 'payment_method']);

        foreach ($purchasePayments as $pp) {
            $amount = (float) $pp->amount;
            $date = Carbon::parse($pp->getRawOriginal('date'))->format('Y-m-d');
            $time = $pp->created_at->format('H:i:s');
            $dt = $date . ' ' . $time;
            $tag = $pp->purchase->supplier->supplier_name ?? null;
            $desc = 'Pembayaran Pembelian' . ($pp->payment_method ? ' - ' . $pp->payment_method : '');

            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::CASH_BANK, $dt, 'Pembayaran Pembelian', $pp->reference, $desc, 0, $amount, $tag);
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::ACCOUNTS_PAYABLE, $dt, 'Pembayaran Pembelian', $pp->reference, 'Pembayaran Hutang', $amount, 0, $tag);
        }

        // 7. Purchase Returns
        $purchaseReturns = PurchaseReturn::whereIn('setting_id', $settingIds)
            ->whereIn('status', ['Completed', 'COMPLETED'])
            ->whereBetween('date', [$startDate, $endDateEndOfDay])
            ->get(['id', 'date', 'reference', 'total_amount', 'supplier_name', 'created_at']);

        foreach ($purchaseReturns as $pr) {
            $amount = (float) $pr->total_amount;
            $date = Carbon::parse($pr->getRawOriginal('date'))->format('Y-m-d');
            $time = $pr->created_at->format('H:i:s');
            $dt = $date . ' ' . $time;

            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::ACCOUNTS_PAYABLE, $dt, 'Retur Pembelian', $pr->reference, 'Pengurangan Hutang', $amount, 0, $pr->supplier_name);
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::INVENTORY, $dt, 'Retur Pembelian', $pr->reference, 'Retur Pembelian', 0, $amount, $pr->supplier_name);
        }

        // 8. Purchase Return Payments
        $purchaseReturnPayments = PurchaseReturnPayment::with('purchaseReturn:id,supplier_name,created_at')
            ->whereHas('purchaseReturn', function ($q) use ($settingIds) {
                $q->whereIn('setting_id', $settingIds)
                  ->whereIn('status', ['Completed', 'COMPLETED']);
            })
            ->whereBetween('date', [$startDate, $endDateEndOfDay])
            ->get(['id', 'amount', 'date', 'created_at', 'reference', 'purchase_return_id', 'payment_method']);

        foreach ($purchaseReturnPayments as $payment) {
            $amount = (float) $payment->amount;
            $date = Carbon::parse($payment->getRawOriginal('date'))->format('Y-m-d');
            $time = $payment->created_at->format('H:i:s');
            $dt = $date . ' ' . $time;
            $tag = $payment->purchaseReturn->supplier_name ?? null;
            $desc = 'Penerimaan Dana Retur' . ($payment->payment_method ? ' - ' . $payment->payment_method : '');

            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::CASH_BANK, $dt, 'Penerimaan Dana', $payment->reference, $desc, $amount, 0, $tag);
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::ACCOUNTS_PAYABLE, $dt, 'Penerimaan Dana', $payment->reference, 'Penerimaan Dana Retur', 0, $amount, $tag);
        }

        // 9. Expenses
        $expenses = Expense::activeApproved()
            ->whereIn('setting_id', $settingIds)
            ->whereBetween('date', [$startDate, $endDateEndOfDay])
            ->with('category:id,category_name')
            ->get(['date', 'reference', 'amount', 'details', 'created_at', 'category_id']);

        foreach ($expenses as $expense) {
            $amount = (float) $expense->amount;
            $date = Carbon::parse($expense->getRawOriginal('date'))->format('Y-m-d');
            $time = $expense->created_at->format('H:i:s');
            $dt = $date . ' ' . $time;
            $tag = $expense->category->category_name ?? null;

            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::OPERATIONAL_COST, $dt, 'Pengeluaran', $expense->reference, $expense->details ?: 'Biaya Operasional', $amount, 0, $tag);
            $events[] = $this->makeEvent(OperationalGeneralLedgerBucketConfig::CASH_BANK, $dt, 'Pengeluaran', $expense->reference, 'Pembayaran Biaya', 0, $amount, $tag);
        }

        return $events;
    }
    
    // Kept for backward compatibility if any other calls still use it temporarily
    public function getMovementEvents(int|array $settingScope, string $endDate): array
    {
        return $this->getPeriodMovements($settingScope, '1970-01-01', $endDate);
    }

    private function normalizeSettingIds(int|array $settingScope): array
    {
        return is_array($settingScope) ? $settingScope : [$settingScope];
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