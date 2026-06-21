<?php

namespace App\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;

class SalesOrderCompletionReportQueryService
{
    public function build(SalesOrderCompletionReportFilterData $filter): Builder
    {
        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id') ?: 0;
        $activePaymentSub = $this->activePaymentSubquery();
        $saleDeliveryAmountSub = $this->saleDeliveryAmountSubquery($scopeSettingId);

        $effectivePaidExpression = $this->effectivePaidExpression();
        $invoiceAmountExpression = $this->invoiceAmountExpression();

        $query = Sale::query()->with([
                'customer',
                'tags',
            ])
            ->leftJoin('customers', 'sales.customer_id', '=', 'customers.id')
            ->leftJoinSub($activePaymentSub, 'ap', 'ap.sale_id', '=', 'sales.id')
            ->leftJoinSub($saleDeliveryAmountSub, 'da', 'da.sale_id', '=', 'sales.id')
            ->select(
                'sales.*',
                DB::raw($effectivePaidExpression . ' as derived_active_paid'),
                DB::raw($invoiceAmountExpression . ' as derived_invoice_amount'),
                DB::raw('COALESCE(da.delivery_amount, 0) as derived_delivery_amount')
            );

        return $this->applyCommonFilters($query, $filter, $scopeSettingId);
    }

    private function activePaymentSubquery()
    {
        return DB::table('sale_payments')
            ->select('sale_id')
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN amount ELSE 0 END) as active_paid',
                [SalePayment::STATUS_ACTIVE]
            )
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as active_payment_count',
                [SalePayment::STATUS_ACTIVE]
            )
            ->groupBy('sale_id');
    }

    private function saleDeliveryAmountSubquery(int $scopeSettingId)
    {
        // 1. Commercial aggregate from sale_details (bundle_id = 0)
        $standardCommercial = DB::table('sale_details')
            ->join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->select(
                'sale_details.sale_id',
                'sale_details.product_id',
                DB::raw('COALESCE(sale_details.tax_id, 0) as tax_id'),
                DB::raw('0 as bundle_id'),
                DB::raw('SUM(sale_details.quantity) as ordered_quantity'),
                DB::raw('SUM(sale_details.sub_total) as commercial_line_amount')
            )
            ->where('sales.setting_id', $scopeSettingId)
            ->groupBy('sale_details.sale_id', 'sale_details.product_id', DB::raw('COALESCE(sale_details.tax_id, 0)'));

        // 2. Commercial aggregate from sale_bundle_items (bundle_id > 0)
        $bundleCommercial = DB::table('sale_bundle_items')
            ->join('sales', 'sale_bundle_items.sale_id', '=', 'sales.id')
            ->leftJoin('sale_details', 'sale_bundle_items.sale_detail_id', '=', 'sale_details.id')
            ->select(
                'sale_bundle_items.sale_id',
                'sale_bundle_items.product_id',
                DB::raw('COALESCE(sale_details.tax_id, sale_bundle_items.tax_id, 0) as tax_id'),
                DB::raw('COALESCE(sale_bundle_items.bundle_id, 0) as bundle_id'),
                DB::raw('SUM(sale_bundle_items.quantity) as ordered_quantity'),
                DB::raw('SUM(sale_bundle_items.sub_total) as commercial_line_amount')
            )
            ->where('sales.setting_id', $scopeSettingId)
            ->groupBy('sale_bundle_items.sale_id', 'sale_bundle_items.product_id', DB::raw('COALESCE(sale_details.tax_id, sale_bundle_items.tax_id, 0)'), DB::raw('COALESCE(sale_bundle_items.bundle_id, 0)'));

        // Combine commercial aggregates
        $commercialAggregate = DB::table(DB::raw("({$standardCommercial->toSql()} UNION ALL {$bundleCommercial->toSql()}) as commercial_agg"))
            ->mergeBindings($standardCommercial)
            ->mergeBindings($bundleCommercial);

        // 3. Delivery aggregate
        $deliveryAggregate = DB::table('dispatch_details')
            ->join('dispatches', 'dispatch_details.dispatch_id', '=', 'dispatches.id')
            ->join('sales', 'dispatches.sale_id', '=', 'sales.id')
            ->select(
                'dispatch_details.sale_id',
                'dispatch_details.product_id',
                DB::raw('COALESCE(dispatch_details.tax_id, 0) as tax_id'),
                DB::raw('COALESCE(dispatch_details.bundle_id, 0) as bundle_id'),
                DB::raw('SUM(dispatch_details.dispatched_quantity) as delivered_quantity')
            )
            ->where('sales.setting_id', $scopeSettingId)
            ->where('dispatches.status', 'APPROVED')
            ->groupBy(
                'dispatch_details.sale_id',
                'dispatch_details.product_id',
                DB::raw('COALESCE(dispatch_details.tax_id, 0)'),
                DB::raw('COALESCE(dispatch_details.bundle_id, 0)')
            );

        return DB::query()->fromSub($deliveryAggregate, 'delivery')
            ->leftJoinSub($commercialAggregate, 'commercial', function ($join) {
                $join->on('delivery.sale_id', '=', 'commercial.sale_id')
                     ->on('delivery.product_id', '=', 'commercial.product_id')
                     ->on('delivery.tax_id', '=', 'commercial.tax_id')
                     ->on('delivery.bundle_id', '=', 'commercial.bundle_id');
            })
            ->select(
                'delivery.sale_id',
                DB::raw('SUM(delivery.delivered_quantity * CASE WHEN commercial.ordered_quantity > 0 THEN commercial.commercial_line_amount / commercial.ordered_quantity ELSE 0 END) as delivery_amount')
            )
            ->groupBy('delivery.sale_id');
    }

    private function effectivePaidExpression(): string
    {
        return <<<'SQL'
CASE
    WHEN COALESCE(ap.active_payment_count, 0) > 0 THEN COALESCE(ap.active_paid, 0)
    WHEN COALESCE(sales.paid_amount, 0) > 0 THEN COALESCE(sales.paid_amount, 0)
    WHEN COALESCE(sales.total_amount, 0) - COALESCE(sales.due_amount, 0) > 0
        THEN COALESCE(sales.total_amount, 0) - COALESCE(sales.due_amount, 0)
    ELSE 0
END
SQL;
    }

    private function invoiceAmountExpression(): string
    {
        return <<<'SQL'
CASE
    WHEN sales.status IN ('WAITING_APPROVAL', 'DRAFTED') THEN 0
    ELSE COALESCE(sales.total_amount, 0)
END
SQL;
    }

    private function applyCommonFilters(
        Builder $query,
        SalesOrderCompletionReportFilterData $filter,
        int $scopeSettingId
    ): Builder {
        $query
            ->when(!$filter->isGlobal, fn($builder) => $builder->where('sales.setting_id', $scopeSettingId))
            ->where('sales.date', '>=', $filter->startDate)
            ->where('sales.date', '<=', $filter->endDate);

        if (!empty($filter->customerIds)) {
            $query->whereIn('sales.customer_id', $filter->customerIds);
        }

        if (!empty($filter->tagIds)) {
            if ($filter->tagLogic === 'all') {
                foreach ($filter->tagIds as $tagId) {
                    $query->whereHas('tags', fn($builder) => $builder->where('tags.id', $tagId));
                }
            } else {
                $query->whereHas('tags', fn($builder) => $builder->whereIn('tags.id', $filter->tagIds));
            }
        }

        $statuses = [];
        if ($filter->sourceStage === 'Penawaran') {
            $statuses = ['DRAFTED'];
        } elseif ($filter->sourceStage === 'Pemesanan') {
            $statuses = ['WAITING_APPROVAL', 'APPROVED', 'DISPATCHED PARTIALLY', 'DISPATCHED', 'RETURNED PARTIALLY', 'RETURNED'];
        }

        if (!empty($statuses)) {
            $query->whereIn('sales.status', $statuses);
        } else {
            // If somehow invalid source stages were passed, yield nothing
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public function applySort(Builder $query, string $sortField, string $sortDirection): void
    {
        $direction = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        match ($sortField) {
            'date'                     => $query->orderBy('sales.date', $direction),
            'reference'                => $query->orderBy('sales.reference', $direction),
            'customer_name'            => $query->orderBy('customers.customer_name', $direction),
            'total_amount'             => $query->orderBy('sales.total_amount', $direction),
            default                    => null,
        };

        $query->orderBy('sales.id', 'desc');
    }

    public static function headings(): array
    {
        return [
            'Tanggal Pemesanan',
            'No. Pemesanan',
            'Jumlah Pesanan',
            'Status Pesanan',
            'Jumlah Pengiriman',
            'Jumlah Faktur',
            'Jumlah Pembayaran',
        ];
    }

    public static function mapRow(mixed $row): array
    {
        $sale = $row instanceof Sale ? $row : null;

        if (!$sale) {
            return array_fill_keys(self::headings(), '-');
        }

        $activePaid = (float) ($sale->derived_active_paid ?? 0);
        $totalAmount = (float) ($sale->total_amount ?? 0);
        $invoiceAmount = (float) ($sale->derived_invoice_amount ?? 0);
        $deliveryAmount = (float) ($sale->derived_delivery_amount ?? 0);

        return [
            'Tanggal Pemesanan' => self::formatDate($sale->date),
            'No. Pemesanan' => $sale->reference ?? '-',
            'Jumlah Pesanan' => $totalAmount,
            'Status Pesanan' => self::derivedOrderStatus($activePaid, $invoiceAmount, $sale->status),
            'Jumlah Pengiriman' => $deliveryAmount,
            'Jumlah Faktur' => $invoiceAmount,
            'Jumlah Pembayaran' => $activePaid,
        ];
    }

    private static function derivedOrderStatus(float $activePaid, float $invoiceAmount, ?string $status): string
    {
        if ($status === 'DRAFTED' || $status === 'WAITING_APPROVAL') {
            return 'Belum Dibayar';
        }

        if ($activePaid <= 0) {
            return 'Belum Dibayar';
        }

        if ($invoiceAmount > 0 && $activePaid >= $invoiceAmount) {
            return 'Selesai';
        }

        return 'Terbayar Sebagian';
    }

    private static function formatDate(?string $value): string
    {
        return $value ? date('d/m/Y', strtotime($value)) : '-';
    }
}
