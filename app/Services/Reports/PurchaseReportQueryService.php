<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\EffectivePurchaseReportingDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Purchase\Entities\ReceivedNote;

class PurchaseReportQueryService
{
    public function build(PurchaseReportFilterData $filter): Builder
    {
        if ($filter->reportMode === 'header') {
            return $this->buildHeaderQuery($filter);
        }

        return $this->buildDetailQuery($filter);
    }

    private function buildDetailQuery(PurchaseReportFilterData $filter): Builder
    {
        // Sub-query: payment rows per purchase.
        $activePaymentSub = $this->activePaymentSubquery();

        // Sub-query: distinct approved receiving location names per purchase_detail
        // Uses plain GROUP_CONCAT for SQLite/MySQL cross-compatibility
        $gudangSub = DB::table('received_note_details as rnd')
            ->join('received_notes as rn', 'rn.id', '=', 'rnd.received_note_id')
            ->join('locations as loc', 'loc.id', '=', 'rn.location_id')
            ->where('rn.status', ReceivedNote::STATUS_APPROVED)
            ->select('rnd.po_detail_id', DB::raw('GROUP_CONCAT(DISTINCT loc.name) as gudang'))
            ->groupBy('rnd.po_detail_id');

        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id');
        $dateColumn = $filter->dateBasis === 'due_date' ? 'purchases.due_date' : EffectivePurchaseReportingDate::sqlExpression();
        $effectivePaidExpression = $this->effectivePaidExpression();

    $query = PurchaseDetail::query()->with([
                'purchase.supplier',
                'purchase.tags',
                'tax',
                'product.unit',
                'product.baseUnit',
            ])
            ->join('purchases', 'purchase_details.purchase_id', '=', 'purchases.id')
            ->leftJoin('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
            ->leftJoinSub($activePaymentSub, 'ap', 'ap.purchase_id', '=', 'purchases.id')
            ->leftJoinSub($gudangSub, 'gd', 'gd.po_detail_id', '=', 'purchase_details.id')
            ->select(
                'purchase_details.*',
                'gd.gudang',
                DB::raw($effectivePaidExpression . ' as derived_active_paid'),
            );

        return $this->applyCommonFilters($query, $filter, $scopeSettingId, $dateColumn, 'purchase.tags');
    }

    private function buildHeaderQuery(PurchaseReportFilterData $filter): Builder
    {
        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id');
        $dateColumn = $filter->dateBasis === 'due_date' ? 'purchases.due_date' : EffectivePurchaseReportingDate::sqlExpression();
        $effectivePaidExpression = $this->effectivePaidExpression();

        $query = Purchase::query()->with([
                'supplier',
                'tags',
            ])
            ->leftJoin('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
            ->leftJoinSub($this->activePaymentSubquery(), 'ap', 'ap.purchase_id', '=', 'purchases.id')
            ->select(
                'purchases.*',
                DB::raw($effectivePaidExpression . ' as derived_active_paid'),
            );

        return $this->applyCommonFilters($query, $filter, $scopeSettingId, $dateColumn, 'tags');
    }

    private function activePaymentSubquery()
    {
        return DB::table('purchase_payments')
            ->select('purchase_id')
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN amount ELSE 0 END) as active_paid',
                [PurchasePayment::STATUS_ACTIVE]
            )
            ->selectRaw('COUNT(*) as payment_count')
            ->groupBy('purchase_id');
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

    private function applyCommonFilters(
        Builder $query,
        PurchaseReportFilterData $filter,
        int $scopeSettingId,
        string $dateColumn,
        string $tagRelation
    ): Builder {
        $query
            ->when(!$filter->isGlobal, fn($builder) => $builder->where('purchases.setting_id', $scopeSettingId));

        if ($dateColumn === EffectivePurchaseReportingDate::sqlExpression()) {
            $query
                ->whereRaw($dateColumn . ' >= ?', [$filter->startDate])
                ->whereRaw($dateColumn . ' <= ?', [$filter->endDate]);
        } else {
            // Wrap due_date in DATE() for consistent comparison across SQLite/MySQL
            $query
                ->whereRaw('DATE(' . $dateColumn . ') >= ?', [$filter->startDate])
                ->whereRaw('DATE(' . $dateColumn . ') <= ?', [$filter->endDate]);
        }

        if (!empty($filter->supplierIds)) {
            $query->whereIn('purchases.supplier_id', $filter->supplierIds);
        }

        if (!empty($filter->tagIds)) {
            $query->whereHas($tagRelation, fn($builder) => $builder->whereIn('tags.id', $filter->tagIds));
        }

        if (!empty($filter->documentStatuses)) {
            $query->whereIn('purchases.status', $filter->documentStatuses);
        }

        if (!empty($filter->paymentStatuses)) {
            $query->where(function (Builder $builder) use ($filter) {
                foreach ($filter->paymentStatuses as $status) {
                    match (strtoupper($status)) {
                        'UNPAID' => $builder->orWhere(function ($subQuery) {
                            $subQuery->whereRaw('(' . $this->effectivePaidExpression() . ') <= 0');
                        }),
                        'PARTIAL' => $builder->orWhere(function ($subQuery) {
                            $subQuery->whereRaw('(' . $this->effectivePaidExpression() . ') > 0')
                                ->whereRaw('(' . $this->effectivePaidExpression() . ') < purchases.total_amount');
                        }),
                        'PAID' => $builder->orWhere(function ($subQuery) {
                            $subQuery->whereRaw('(' . $this->effectivePaidExpression() . ') >= purchases.total_amount')
                                ->whereRaw('purchases.total_amount > 0');
                        }),
                        default => null,
                    };
                }
            });
        }

        return $query;
    }

    public function applySort(Builder $query, string $sortField, string $sortDirection, string $reportMode = 'detail'): void
    {
        $direction = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        match ($sortField) {
            'date'                     => $query->orderByRaw(EffectivePurchaseReportingDate::sqlExpression() . ' ' . $direction),
            'reference'                => $query->orderBy('purchases.reference', $direction),
            'supplier_purchase_number' => $query->orderBy('purchases.supplier_purchase_number', $direction),
            'supplier_name'            => $query->orderBy('suppliers.supplier_name', $direction),
            'status'                   => $query->orderBy('purchases.status', $direction),
            'payment_status'           => $query->orderByRaw('
                (CASE
                    WHEN derived_active_paid <= 0 THEN 1
                    WHEN purchases.total_amount > 0 AND derived_active_paid >= purchases.total_amount THEN 3
                    ELSE 2
                END) ' . $direction
            ),
            'total_amount'             => $query->orderBy('purchases.total_amount', $direction),
            'due_date'                 => $query->orderBy('purchases.due_date', $direction),
            'product_name'             => $reportMode === 'detail' ? $query->orderBy('purchase_details.product_name', $direction) : null,
            'product_code'             => $reportMode === 'detail' ? $query->orderBy('purchase_details.product_code', $direction) : null,
            default                    => null,
        };

        $query->orderBy('purchases.id', 'desc');

        if ($reportMode === 'detail') {
            $query->orderBy('purchase_details.id', 'asc');
        }
    }

    /**
     * Map a PurchaseDetail row (with its loaded purchase/supplier/tax and injected gudang/derived_active_paid)
     * into the display/export column set.
     */
    public static function headingsFor(string $reportMode = 'detail'): array
    {
        if ($reportMode === 'header') {
            return [
                'Tanggal',
                'Nomor Transaksi',
                'Nomor Pembelian Supplier',
                'Nama Panggilan',
                'Status Dokumen',
                'Status Pembayaran',
                'Memo',
                'Total',
                'Sisa Tagihan',
                'Tanggal Jatuh Tempo',
                'Jumlah Kena Pajak',
                'Total Pajak',
                'Pembayaran',
                'No Ref',
                'Tag',
            ];
        }

        return [
            'Tanggal',
            'Nomor Transaksi',
            'Nomor Pembelian Supplier',
            'Nama Panggilan',
            'Status Dokumen',
            'Status Pembayaran',
            'Memo',
            'Total',
            'Sisa Tagihan',
            'Tanggal Jatuh Tempo',
            'Jumlah Kena Pajak',
            'Total Pajak',
            'Pembayaran',
            'Email',
            'Alamat Penagihan',
            'Alamat Pengiriman',
            'No Ref',
            'Tag',
            'Gudang',
            'Nama Produk',
            'Kode Produk',
            'Deskripsi',
            'Kuantitas',
            'Satuan',
            'Harga per Unit',
            'Tarif Pajak',
            'Jumlah Pajak',
            'Jumlah Kena Pajak per Baris',
            'Jumlah Per Baris',
            'Pesan',
            'Biaya Pengiriman',
            'Diskon',
            'Nama Perusahaan',
            'Nomor Pajak',
            'Nomor Ponsel',
            'Nomor Telepon',
            'Sisa Tagihan Hari Ini',
            'Diskon %',
        ];
    }

    public static function mapRow(mixed $row, string $reportMode = 'detail'): array
    {
        if ($reportMode === 'header') {
            $purchase = $row instanceof Purchase ? $row : ($row?->purchase);

            return $purchase instanceof Purchase
                ? self::mapHeaderRow($purchase)
                : array_fill_keys(self::headingsFor('header'), '-');
        }

        return $row instanceof PurchaseDetail
            ? self::mapDetailRow($row)
            : array_fill_keys(self::headingsFor('detail'), '-');
    }

    private static function mapDetailRow(PurchaseDetail $detail): array
    {
        $purchase = $detail->purchase;
        $supplier = $purchase?->supplier;
        $tax      = $detail->tax;

        $activePaid  = (float) ($detail->derived_active_paid ?? 0);
        $totalAmount = (float) ($purchase?->total_amount ?? 0);

        return [
            'Tanggal'                     => self::formatDate($purchase?->effective_date),
            'Nomor Transaksi'             => $purchase?->reference ?? '-',
            'Nomor Pembelian Supplier'    => $purchase?->supplier_purchase_number ?? '-',
            'Nama Panggilan'              => $supplier?->supplier_name ?? '-',
            'Status Dokumen'              => self::documentStatusLabel($purchase?->status),
            'Status Pembayaran'           => self::derivedPaymentStatus($activePaid, $totalAmount),
            'Memo'                        => $purchase?->note ?? '-',
            'Total'                       => $totalAmount,
            'Sisa Tagihan'                => max(0, $totalAmount - $activePaid),
            'Tanggal Jatuh Tempo'         => self::formatDate($purchase?->due_date),
            'Jumlah Kena Pajak'           => max(0, $totalAmount - ($purchase?->tax_amount ?? 0)),
            'Total Pajak'                 => $purchase?->tax_amount ?? 0,
            'Pembayaran'                  => $activePaid,
            'Email'                       => $supplier?->supplier_email ?? '-',
            'Alamat Penagihan'            => $supplier?->billing_address ?? $supplier?->address ?? '-',
            'Alamat Pengiriman'           => $supplier?->shipping_address ?? $supplier?->address ?? '-',
            'No Ref'                      => $purchase?->supplier_reference_no ?? '-',
            'Tag'                         => self::tagNames($purchase),
            'Gudang'                      => $detail->gudang ?? '-',
            'Nama Produk'                 => $detail->product_name ?? '-',
            'Kode Produk'                 => $detail->product_code ?? '-',
            'Deskripsi'                   => $detail->product?->description ?? '-',
            'Kuantitas'                   => $detail->quantity ?? 0,
            'Satuan'                      => $detail->product?->unit?->short_name ?? $detail->product?->baseUnit?->short_name ?? $detail->product?->product_unit ?? '-',
            'Harga per Unit'              => $detail->unit_price ?? 0,
            'Tarif Pajak'                 => $tax?->tax_percentage ?? '-',
            'Jumlah Pajak'                => $detail->product_tax_amount ?? 0,
            'Jumlah Kena Pajak per Baris' => max(0, ($detail->sub_total ?? 0) - ($detail->product_tax_amount ?? 0)),
            'Jumlah Per Baris'            => $detail->sub_total ?? 0,
            'Pesan'                       => $purchase?->note ?? '-',
            'Biaya Pengiriman'            => $purchase?->shipping_amount ?? 0,
            'Diskon'                      => $purchase?->discount_amount ?? 0,
            'Nama Perusahaan'             => $supplier?->supplier_name ?? '-',
            'Nomor Pajak'                 => $supplier?->npwp ?? '-',
            'Nomor Ponsel'                => $supplier?->supplier_phone ?? '-',
            'Nomor Telepon'               => $supplier?->fax ?? '-',
            'Sisa Tagihan Hari Ini'       => max(0, $totalAmount - $activePaid),
            'Diskon %'                    => $purchase?->discount_amount
                                                ? ($totalAmount > 0 ? round($purchase->discount_amount / $totalAmount * 100, 2) : 0)
                                                : 0,
        ];
    }

    private static function mapHeaderRow(Purchase $purchase): array
    {
        $supplier = $purchase->supplier;
        $activePaid = (float) ($purchase->derived_active_paid ?? 0);
        $totalAmount = (float) ($purchase->total_amount ?? 0);

        return [
            'Tanggal' => self::formatDate($purchase->effective_date),
            'Nomor Transaksi' => $purchase->reference ?? '-',
            'Nomor Pembelian Supplier' => $purchase->supplier_purchase_number ?? '-',
            'Nama Panggilan' => $supplier?->supplier_name ?? '-',
            'Status Dokumen' => self::documentStatusLabel($purchase->status),
            'Status Pembayaran' => self::derivedPaymentStatus($activePaid, $totalAmount),
            'Memo' => $purchase->note ?? '-',
            'Total' => $totalAmount,
            'Sisa Tagihan' => max(0, $totalAmount - $activePaid),
            'Tanggal Jatuh Tempo' => self::formatDate($purchase->due_date),
            'Jumlah Kena Pajak' => max(0, $totalAmount - ($purchase->tax_amount ?? 0)),
            'Total Pajak' => $purchase->tax_amount ?? 0,
            'Pembayaran' => $activePaid,
            'No Ref' => $purchase->supplier_reference_no ?? '-',
            'Tag' => self::tagNames($purchase),
        ];
    }

    private static function derivedPaymentStatus(float $activePaid, float $totalAmount): string
    {
        if ($activePaid <= 0) {
            return 'Belum Dibayar';
        }

        if ($totalAmount > 0 && $activePaid >= $totalAmount) {
            return 'Lunas';
        }

        return 'Terbayar Sebagian';
    }

    private static function documentStatusLabel(?string $status): string
    {
        $documentStatusLabels = [
            Purchase::STATUS_DRAFTED => 'Draf',
            Purchase::STATUS_WAITING_APPROVAL => 'Menunggu Persetujuan',
            Purchase::STATUS_APPROVED => 'Disetujui',
            Purchase::STATUS_REJECTED => 'Ditolak',
            Purchase::STATUS_RECEIVED_PARTIALLY => 'Diterima Sebagian',
            Purchase::STATUS_RECEIVED => 'Diterima',
            Purchase::STATUS_RETURNED_PARTIALLY => 'Diretur Sebagian',
            Purchase::STATUS_RETURNED => 'Diretur',
        ];

        return $status ? ($documentStatusLabels[$status] ?? $status) : '-';
    }

    private static function formatDate(?string $value): string
    {
        return $value ? date('d/m/Y', strtotime($value)) : '-';
    }

    private static function tagNames(?Purchase $purchase): string
    {
        if (!$purchase) {
            return '-';
        }

        $tagNames = $purchase->tags
            ->map(function ($tag) {
                if (is_array($tag->name)) {
                    return $tag->name['en'] ?? reset($tag->name) ?: null;
                }

                return is_string($tag->name) ? $tag->name : null;
            })
            ->filter()
            ->implode(', ');

        return $tagNames !== '' ? $tagNames : '-';
    }
}
