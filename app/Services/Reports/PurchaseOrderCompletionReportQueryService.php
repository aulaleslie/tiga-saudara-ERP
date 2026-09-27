<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\EffectivePurchaseReportingDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;

class PurchaseOrderCompletionReportQueryService
{
    public function build(PurchaseOrderCompletionReportFilterData $filter): Builder
    {
        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id') ?: 0;
        $activePaymentSub = $this->activePaymentSubquery();
        $purchaseDeliveryAmountSub = $this->purchaseDeliveryAmountSubquery($scopeSettingId);

        $effectivePaidExpression = $this->effectivePaidExpression();
        $invoiceAmountExpression = $this->invoiceAmountExpression();

        $query = Purchase::query()->with([
                'supplier',
                'tags',
            ])
            ->leftJoin('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
            ->leftJoinSub($activePaymentSub, 'ap', 'ap.purchase_id', '=', 'purchases.id')
            ->leftJoinSub($purchaseDeliveryAmountSub, 'da', 'da.purchase_id', '=', 'purchases.id')
            ->select(
                'purchases.*',
                DB::raw($effectivePaidExpression . ' as derived_active_paid'),
                DB::raw($invoiceAmountExpression . ' as derived_invoice_amount'),
                DB::raw('COALESCE(da.delivery_amount, 0) as derived_delivery_amount')
            );

        return $this->applyCommonFilters($query, $filter, $scopeSettingId);
    }

    private function activePaymentSubquery()
    {
        return DB::table('purchase_payments')
            ->select('purchase_id')
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN amount ELSE 0 END) as active_paid',
                [PurchasePayment::STATUS_ACTIVE]
            )
            ->selectRaw(
                'COUNT(*) as payment_count'
            )
            ->groupBy('purchase_id');
    }

    private function purchaseDeliveryAmountSubquery(int $scopeSettingId)
    {
        return DB::table('received_note_details')
            ->join('received_notes', 'received_note_details.received_note_id', '=', 'received_notes.id')
            ->join('purchase_details', 'received_note_details.po_detail_id', '=', 'purchase_details.id')
            ->join('purchases', 'purchase_details.purchase_id', '=', 'purchases.id')
            ->select(
                'purchases.id as purchase_id',
                DB::raw('SUM(received_note_details.quantity_received * (CASE WHEN purchase_details.quantity > 0 THEN purchase_details.sub_total / purchase_details.quantity ELSE 0 END)) as delivery_amount')
            )
            ->where('purchases.setting_id', $scopeSettingId)
            ->where('received_notes.status', 'APPROVED')
            ->groupBy('purchases.id');
    }

    private function effectivePaidExpression(): string
    {
        return <<<'SQL'
CASE
    WHEN COALESCE(ap.payment_count, 0) > 0 THEN COALESCE(ap.active_paid, 0)
    WHEN COALESCE(purchases.paid_amount, 0) > 0 THEN COALESCE(purchases.paid_amount, 0)
    WHEN COALESCE(purchases.total_amount, 0) - COALESCE(purchases.due_amount, 0) > 0
        THEN COALESCE(purchases.total_amount, 0) - COALESCE(purchases.due_amount, 0)
    ELSE 0
END
SQL;
    }

    private function invoiceAmountExpression(): string
    {
        return <<<'SQL'
CASE
    WHEN purchases.status IN ('WAITING_APPROVAL', 'DRAFTED') THEN 0
    ELSE COALESCE(purchases.total_amount, 0)
END
SQL;
    }

    private function applyCommonFilters(
        Builder $query,
        PurchaseOrderCompletionReportFilterData $filter,
        int $scopeSettingId
    ): Builder {
        $query
            ->when(!$filter->isGlobal, fn($builder) => $builder->where('purchases.setting_id', $scopeSettingId))
            ->whereRaw(EffectivePurchaseReportingDate::sqlExpression() . ' >= ?', [$filter->startDate])
            ->whereRaw(EffectivePurchaseReportingDate::sqlExpression() . ' <= ?', [$filter->endDate]);

        if (!empty($filter->supplierIds)) {
            $query->whereIn('purchases.supplier_id', $filter->supplierIds);
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
            $statuses = ['WAITING_APPROVAL', 'APPROVED', 'RECEIVED PARTIALLY', 'RECEIVED', 'RETURNED PARTIALLY', 'RETURNED'];
        }

        if (!empty($statuses)) {
            $query->whereIn('purchases.status', $statuses);
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
            'date'                     => $query->orderByRaw(EffectivePurchaseReportingDate::sqlExpression() . ' ' . $direction),
            'reference'                => $query->orderBy('purchases.reference', $direction),
            'supplier_name'            => $query->orderBy('suppliers.supplier_name', $direction),
            'total_amount'             => $query->orderBy('purchases.total_amount', $direction),
            default                    => null,
        };

        $query->orderBy('purchases.id', 'desc');
    }

    public static function headings(): array
    {
        return [
            'Tanggal Pemesanan',
            'No. Pemesanan',
            'Jumlah Pemesanan',
            'Status Pemesanan',
            'Jumlah Pengiriman',
            'Jumlah Faktur',
            'Jumlah Pembayaran',
        ];
    }

    public static function mapRow(mixed $row): array
    {
        $purchase = $row instanceof Purchase ? $row : null;

        if (!$purchase) {
            return array_fill_keys(self::headings(), '-');
        }

        $activePaid = (float) ($purchase->derived_active_paid ?? 0);
        $totalAmount = (float) ($purchase->total_amount ?? 0);
        $invoiceAmount = (float) ($purchase->derived_invoice_amount ?? 0);
        $deliveryAmount = (float) ($purchase->derived_delivery_amount ?? 0);

        return [
            'Tanggal Pemesanan' => self::formatDate($purchase->effective_date),
            'No. Pemesanan' => $purchase->reference ?? '-',
            'Jumlah Pemesanan' => $totalAmount,
            'Status Pemesanan' => self::derivedOrderStatus($activePaid, $invoiceAmount, $purchase->status),
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
