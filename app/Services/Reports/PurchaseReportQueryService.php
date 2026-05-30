<?php

namespace App\Services\Reports;

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
        // Sub-query: sum of active payment amounts per purchase (amounts stored as x100 integers)
        $activePaymentSub = DB::table('purchase_payments')
            ->select('purchase_id', DB::raw('SUM(amount) / 100.0 as active_paid'))
            ->where('status', PurchasePayment::STATUS_ACTIVE)
            ->groupBy('purchase_id');

        // Sub-query: distinct approved receiving location names per purchase_detail
        // Uses plain GROUP_CONCAT for SQLite/MySQL cross-compatibility
        $gudangSub = DB::table('received_note_details as rnd')
            ->join('received_notes as rn', 'rn.id', '=', 'rnd.received_note_id')
            ->join('locations as loc', 'loc.id', '=', 'rn.location_id')
            ->where('rn.status', ReceivedNote::STATUS_APPROVED)
            ->select('rnd.po_detail_id', DB::raw('GROUP_CONCAT(DISTINCT loc.name) as gudang'))
            ->groupBy('rnd.po_detail_id');

        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id');
        $dateColumn = $filter->dateBasis === 'due_date' ? 'purchases.due_date' : 'purchases.date';

        $query = PurchaseDetail::with([
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
                DB::raw('COALESCE(ap.active_paid, 0) as derived_active_paid'),
            )
            ->when(!$filter->isGlobal, fn($q) => $q->where('purchases.setting_id', $scopeSettingId))
            ->where($dateColumn, '>=', $filter->startDate)
            ->where($dateColumn, '<=', $filter->endDate);

        // Supplier filter (OR semantics)
        if (!empty($filter->supplierIds)) {
            $query->whereIn('purchases.supplier_id', $filter->supplierIds);
        }

        // Tag filter (OR semantics — purchase has at least one selected tag)
        if (!empty($filter->tagIds)) {
            $query->whereHas('purchase.tags', fn($q) => $q->whereIn('tags.id', $filter->tagIds));
        }

        // Document status filter (OR semantics)
        if (!empty($filter->documentStatuses)) {
            $query->whereIn('purchases.status', $filter->documentStatuses);
        }

        // Payment status filter derived from active payments (OR semantics)
        if (!empty($filter->paymentStatuses)) {
            $query->where(function (Builder $q) use ($filter) {
                foreach ($filter->paymentStatuses as $status) {
                    match (strtoupper($status)) {
                        'UNPAID'  => $q->orWhere(function ($sub) {
                                $sub->whereRaw('COALESCE(ap.active_paid, 0) <= 0');
                            }),
                        'PARTIAL' => $q->orWhere(function ($sub) {
                                $sub->whereRaw('COALESCE(ap.active_paid, 0) > 0')
                                    ->whereRaw('COALESCE(ap.active_paid, 0) < purchases.total_amount');
                            }),
                        'PAID'    => $q->orWhere(function ($sub) {
                                $sub->whereRaw('COALESCE(ap.active_paid, 0) >= purchases.total_amount')
                                    ->whereRaw('purchases.total_amount > 0');
                            }),
                        default   => null,
                    };
                }
            });
        }

        return $query;
    }

    /**
     * Map a PurchaseDetail row (with its loaded purchase/supplier/tax and injected gudang/derived_active_paid)
     * into the display/export column set.
     */
    public static function mapRow(PurchaseDetail $detail): array
    {
        $purchase = $detail->purchase;
        $supplier = $purchase?->supplier;
        $tax      = $detail->tax;

        $activePaid  = (float) ($detail->derived_active_paid ?? 0);
        $totalAmount = (float) ($purchase?->total_amount ?? 0);

        if ($activePaid <= 0) {
            $derivedPaymentStatus = 'Belum Dibayar';
        } elseif ($totalAmount > 0 && $activePaid >= $totalAmount) {
            $derivedPaymentStatus = 'Lunas';
        } else {
            $derivedPaymentStatus = 'Terbayar Sebagian';
        }

        $locale  = app()->getLocale();
        $tagNames = $purchase?->tags->map(function ($tag) use ($locale) {
            $nameData = is_array($tag->name) ? $tag->name : (json_decode($tag->name, true) ?? []);
            return $nameData[$locale] ?? ($nameData['en'] ?? (is_array($nameData) ? reset($nameData) : $tag->name));
        })->implode(', ') ?? '-';

        return [
            'Tanggal'                     => $purchase?->date ? date('d/m/Y', strtotime($purchase->date)) : '-',
            'Nomor Transaksi'             => $purchase?->reference ?? '-',
            'Nomor Pembelian Supplier'    => $purchase?->supplier_purchase_number ?? '-',
            'Nama Panggilan'              => $supplier?->supplier_name ?? '-',
            'Status Dokumen'              => $purchase?->status ?? '-',
            'Status Pembayaran'           => $derivedPaymentStatus,
            'Memo'                        => $purchase?->note ?? '-',
            'Total'                       => $totalAmount,
            'Sisa Tagihan'                => max(0, $totalAmount - $activePaid),
            'Tanggal Jatuh Tempo'         => $purchase?->due_date ? date('d/m/Y', strtotime($purchase->due_date)) : '-',
            'Jumlah Kena Pajak'           => max(0, $totalAmount - ($purchase?->tax_amount ?? 0)),
            'Total Pajak'                 => $purchase?->tax_amount ?? 0,
            'Pembayaran'                  => $activePaid,
            'Email'                       => $supplier?->supplier_email ?? '-',
            'Alamat Penagihan'            => $supplier?->billing_address ?? $supplier?->address ?? '-',
            'Alamat Pengiriman'           => $supplier?->shipping_address ?? $supplier?->address ?? '-',
            'No Ref'                      => $purchase?->supplier_reference_no ?? '-',
            'Tag'                         => $tagNames,
            'Gudang'                      => $detail->gudang ?? '-',
            'Nama Produk'                 => $detail->product_name ?? '-',
            'Kode Produk'                 => $detail->product_code ?? '-',
            'Deskripsi'                   => $detail->product?->description ?? '-',
            'Kuantitas'                   => $detail->quantity ?? 0,
            'Satuan'                      => $detail->product?->unit?->short_name ?? $detail->product?->baseUnit?->short_name ?? $detail->product?->product_unit ?? '-',
            'Harga per Unit'              => $detail->unit_price ?? 0,
            'Diskon Per Baris %'          => $detail->product_discount_type === 'percentage'
                                                ? ($detail->product_discount_amount ?? 0)
                                                : 0,
            'Tarif Pajak'                 => $tax?->tax_percentage ?? '-',
            'Jumlah Pajak'                => $detail->product_tax_amount ?? 0,
            'Jumlah Kena Pajak per Baris' => max(0, ($detail->sub_total ?? 0) - ($detail->product_tax_amount ?? 0)),
            'Jumlah Per Baris'            => $detail->sub_total ?? 0,
            'Diskon'                      => $detail->product_discount_amount ?? 0,
            'Pesan'                       => $purchase?->note ?? '-',
            'Biaya Pengiriman'            => $purchase?->shipping_amount ?? 0,
            'Jumlah Pemotongan'           => $purchase?->discount_amount ?? 0,
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
}
