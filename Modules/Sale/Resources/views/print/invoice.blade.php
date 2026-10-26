<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $documentTitle ?? 'FAKTUR PENJUALAN' }} #{{ $documentNumber ?? ($sale->reference ?? $invoiceNumber ?? $sale->id) }}</title>
    <style>
        @page {
            margin: 20mm 15mm 20mm 15mm;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            color: #111;
            line-height: 1.3;
        }
        .title {
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 4px;
            letter-spacing: 0.5px;
        }
        .badge-status {
            display: inline-block;
            padding: 2px 8px;
            font-size: 10px;
            font-weight: bold;
            border-radius: 3px;
            text-transform: uppercase;
        }
        .badge-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .badge-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .badge-secondary { background: #e2e3e5; color: #383d41; border: 1px solid #d6d8db; }
        .badge-info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }

        .row-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .row-table td { vertical-align: top; }
        .info-table, .ref-table { width: 100%; border-collapse: collapse; }
        .info-table td, .ref-table td { font-size: 11px; padding: 2px 4px; }

        .main-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .main-table thead {
            display: table-header-group;
        }
        .main-table tr {
            page-break-inside: avoid;
        }
        .main-table th, .main-table td {
            border: 1px solid #333;
            padding: 5px 4px;
        }
        .main-table th {
            font-size: 11px;
            font-weight: bold;
            background: #f5f5f5;
        }
        .main-table td {
            font-size: 11px;
        }

        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .bold { font-weight: bold; }
        .box {
            border: 1px solid #333;
            padding: 6px 8px;
            margin-top: 10px;
            margin-bottom: 8px;
            background: #fafafa;
        }
        .footer-table {
            width: 100%;
            margin-top: 6px;
            border-collapse: collapse;
        }
        .footer-table td {
            padding: 2px 4px;
            font-size: 11px;
        }
        .ttd-table {
            width: 100%;
            margin-top: 30px;
            page-break-inside: avoid;
        }
        .ttd-table td {
            width: 50%;
            vertical-align: bottom;
            text-align: center;
            font-size: 11px;
        }
        .small { font-size: 10px; color: #444; }
        .upper { text-transform: uppercase; }
        .reconciliation-diff {
            color: #856404;
            font-style: italic;
        }
    </style>
</head>
<body>
@php
    $compName = $company['name'] ?? ($sale->tenantSetting->company_name ?? 'TIGA COMPUTER');
    $compAddr = $company['address'] ?? ($sale->tenantSetting->company_address ?? '');
    $compPhone = $company['phone'] ?? ($sale->tenantSetting->company_phone ?? '');
    $compEmail = $company['email'] ?? ($sale->tenantSetting->company_email ?? '');
    $isPkp = isset($isPkp) ? (bool)$isPkp : (bool)($sale->tenantSetting->is_pkp ?? false);

    $docTitle = $documentTitle ?? 'FAKTUR PENJUALAN';
    $docNumber = $documentNumber ?? ($sale->reference ?? $invoiceNumber ?? (string)$sale->id);
    $dateStr = $dateFormatted ?? ($tanggal ? $tanggal->format('d/m/Y') : ($sale->date ? \Carbon\Carbon::parse($sale->date)->format('d/m/Y') : '-'));
    $dueStr = $dueDateFormatted ?? ($jatuhTempo ? $jatuhTempo->format('d/m/Y') : ($sale->due_date ? \Carbon\Carbon::parse($sale->due_date)->format('d/m/Y') : '-'));

    $statLabel = $statusLabel ?? (string)($sale->status ?? 'Approved');
    $isDraft = $isDraft ?? in_array($sale->status ?? '', [\Modules\Sale\Entities\Sale::STATUS_DRAFTED, 'Draft', 'DRAFT'], true);
    $isPending = $isPending ?? in_array($sale->status ?? '', [\Modules\Sale\Entities\Sale::STATUS_WAITING_APPROVAL, 'Pending', 'PENDING'], true);
    $isRejected = $isRejected ?? in_array($sale->status ?? '', [\Modules\Sale\Entities\Sale::STATUS_REJECTED, 'Rejected', 'REJECTED'], true);
    $isArch = $isArchived ?? ($sale->isArchived());

    $showSettlement = isset($showSettlement) ? (bool)$showSettlement : ($variant ?? 'invoice') === 'invoice';

    $fmtQty = function($qty) {
        if ($qty === null || $qty === '') return '0';
        $num = (float)$qty;
        return rtrim(rtrim(number_format($num, 3, '.', ''), '0'), '.');
    };
    $fmtRupiah = fn($n) => number_format((float)$n, 0, ',', '.');
@endphp

<div class="title">{{ $docTitle }}</div>

<table class="row-table">
    <tr>
        <td style="width: 55%;">
            <span class="bold" style="font-size: 13px;">{{ $compName }}</span><br>
            @if(filled($compAddr) || filled($compPhone) || filled($compEmail))
                <div class="small" style="margin-bottom:4px">
                    @if(filled($compAddr)){!! nl2br(e($compAddr)) !!}<br>@endif
                    @if(filled($compPhone))Telp: {{ $compPhone }}<br>@endif
                    @if(filled($compEmail))Email: {{ $compEmail }}<br>@endif
                </div>
            @endif

            <table class="info-table" style="margin-top: 6px;">
                <tr>
                    <td style="width: 32%;">Ref. Pelanggan</td>
                    <td style="width: 3%;">:</td>
                    <td style="width: 65%;">{{ $sale->reference ?? '-' }}</td>
                </tr>
                @if($isPkp && !empty($sale->tax_ref_no))
                <tr>
                    <td>No. Faktur Pajak</td>
                    <td>:</td>
                    <td>{{ $sale->tax_ref_no }}</td>
                </tr>
                @endif
                <tr>
                    <td>Tgl. Dokumen</td>
                    <td>:</td>
                    <td>{{ $dateStr }}</td>
                </tr>
                <tr>
                    <td>Jatuh Tempo</td>
                    <td>:</td>
                    <td>{{ $dueStr }}</td>
                </tr>
                <tr>
                    <td>Status</td>
                    <td>:</td>
                    <td>
                        <span class="bold">{{ $statLabel }}</span>
                        @if($isDraft)
                            <span class="badge-status badge-secondary ml-1">DRAFT</span>
                        @endif
                        @if($isPending)
                            <span class="badge-status badge-warning ml-1">MENUNGGU PERSETUJUAN</span>
                        @endif
                        @if($isRejected)
                            <span class="badge-status badge-danger ml-1">DITOLAK</span>
                        @endif
                        @if($isArch)
                            <span class="badge-status badge-info ml-1">ARSIP</span>
                        @endif
                    </td>
                </tr>
            </table>
        </td>

        <td style="width: 45%; vertical-align: top;">
            <table class="ref-table" style="margin-bottom: 10px;">
                <tr>
                    <td class="bold" style="width: 25%;">NO.</td>
                    <td class="bold" style="width: 75%; font-size: 13px;">{{ $docNumber }}</td>
                </tr>
            </table>

            <div style="margin-top: 8px;">
                <span class="bold">KEPADA YTH.</span><br>
                {{ $customer->customer_name ?: 'CASH' }}
                @if(!empty($customer->address))
                    <br><span class="small">{{ $customer->address }}</span>
                @endif
                @if($isPkp && filled($customer->npwp))
                    <br><span class="small">NPWP: {{ $customer->npwp }}</span>
                @endif
            </div>
        </td>
    </tr>
</table>

<table class="main-table">
    <thead>
    <tr>
        <th style="width:40%;">Nama Barang</th>
        <th style="width:15%;" class="text-center">Kemasan</th>
        <th style="width:10%;" class="text-center">Qty</th>
        <th style="width:17%;" class="text-right">Harga Satuan<br>(Rp.)</th>
        <th style="width:18%;" class="text-right">Jumlah (Rp.)</th>
    </tr>
    </thead>
    <tbody>
    @php
        $rawRows = $details ?? [];
        $runningSubtotal = 0.0;
        $runningTax = 0.0;
    @endphp

    @foreach($rawRows as $item)
        @php
            $isArr = is_array($item);
            $pName = $isArr ? ($item['product_name'] ?? '-') : ($item->display_product_name ?? '-');
            $pCode = $isArr ? ($item['product_code'] ?? null) : ($item->product_code ?? ($item->line_group_key ?? null));
            $unitName = $isArr ? ($item['unit_name'] ?? '-') : ($item->product->baseUnit->name ?? '-');
            $qty = $isArr ? ($item['quantity'] ?? 0) : $item->quantity;
            $price = $isArr ? ($item['unit_price'] ?? 0) : ($item->unit_price ?? $item->price);
            $sub = $isArr ? ($item['sub_total'] ?? 0) : $item->sub_total;
            $runningSubtotal += (float)$sub;
            $rowTax = $isArr ? ($item['tax_amount'] ?? 0) : ($item->product_tax_amount ?? 0);
            $runningTax += (float)$rowTax;
        @endphp
        <tr>
            <td>
                {{ $pName }}
                @if(!empty($pCode))
                    <br><span class="small">{{ $pCode }}</span>
                @endif
            </td>
            <td class="text-center">{{ $unitName }}</td>
            <td class="text-center">{{ $fmtQty($qty) }}</td>
            <td class="text-right">{{ $fmtRupiah($price) }}</td>
            <td class="text-right">{{ $fmtRupiah($sub) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

@php
    $subtotalVal = isset($totals['subtotal']) ? (float)$totals['subtotal'] : $runningSubtotal;
    $discVal = isset($totals['overall_discount']) ? (float)$totals['overall_discount'] : (float)($sale->discount_amount ?? 0);
    $discPct = isset($totals['overall_discount_percentage']) ? (float)$totals['overall_discount_percentage'] : (float)($sale->discount_percentage ?? 0);
    $shippingVal = isset($totals['shipping']) ? (float)$totals['shipping'] : (float)($sale->shipping_amount ?? 0);
    $dppVal = isset($totals['dpp']) ? (float)$totals['dpp'] : 0.0;
    $taxVal = isset($totals['tax_amount']) ? (float)$totals['tax_amount'] : $runningTax;
    $diffVal = isset($totals['reconciliation_diff']) ? (float)$totals['reconciliation_diff'] : 0.0;
    $grandTotalVal = isset($totals['grand_total']) ? (float)$totals['grand_total'] : (float)($total ?? $sale->total_amount);

    $monetaryPaid = isset($totals['monetary_paid']) ? (float)$totals['monetary_paid'] : (float)($paid ?? $sale->paid_amount);
    $creditsApplied = isset($totals['credits_applied']) ? (float)$totals['credits_applied'] : 0.0;
    $totalPaidVal = isset($totals['total_paid']) ? (float)$totals['total_paid'] : ($monetaryPaid + $creditsApplied);
    $dueVal = isset($totals['due_amount']) ? (float)$totals['due_amount'] : (float)($due ?? $sale->due_amount);
@endphp

<div class="box">
    <div class="bold">TERBILANG</div>
    <div class="upper" style="margin-top:3px;">
        {{ function_exists('terbilang') ? strtoupper(terbilang($grandTotalVal)) . ' RUPIAH' : '' }}
    </div>
</div>

<table class="footer-table">
    <tr>
        <td style="border: none; width:65%"></td>
        <td style="border: none; text-align:right;" class="bold">Subtotal</td>
        <td style="border: none; width:130px; text-align:right;">
            {{ $fmtRupiah($subtotalVal) }}
        </td>
    </tr>

    @if($discVal > 0 || $discPct > 0)
        <tr>
            <td style="border: none;"></td>
            <td style="border: none; text-align:right;" class="bold">
                Diskon {{ $discPct > 0 ? '(' . rtrim(rtrim(number_format($discPct, 2, '.', ''), '0'), '.') . '%)' : '' }}
            </td>
            <td style="border: none; text-align:right;">
                - {{ $fmtRupiah($discVal) }}
            </td>
        </tr>
    @endif

    @if($isPkp)
        <tr>
            <td style="border: none;"></td>
            <td style="border: none; text-align:right;" class="bold">
                DPP {{ ($isTaxIncluded ?? false) ? '(Termasuk Pajak)' : '' }}
            </td>
            <td style="border: none; text-align:right;">
                {{ $fmtRupiah($dppVal) }}
            </td>
        </tr>
        @if(!empty($totals['tax_by_rate']))
            @foreach($totals['tax_by_rate'] as $rateItem)
                <tr>
                    <td style="border: none;"></td>
                    <td style="border: none; text-align:right;" class="bold">
                        PPN ({{ rtrim(rtrim(number_format((float)$rateItem['rate'], 2, '.', ''), '0'), '.') }}%)
                    </td>
                    <td style="border: none; text-align:right;">
                        {{ $fmtRupiah($rateItem['tax_amount']) }}
                    </td>
                </tr>
            @endforeach
        @else
            <tr>
                <td style="border: none;"></td>
                <td style="border: none; text-align:right;" class="bold">
                    PPN {{ ($sale->tax_percentage ?? 0) > 0 ? '(' . rtrim(rtrim(number_format((float)$sale->tax_percentage, 2, '.', ''), '0'), '.') . '%)' : '' }}
                </td>
                <td style="border: none; text-align:right;">
                    {{ $fmtRupiah($taxVal) }}
                </td>
            </tr>
        @endif
    @endif

    @if($shippingVal > 0)
        <tr>
            <td style="border: none;"></td>
            <td style="border: none; text-align:right;" class="bold">Ongkos Kirim</td>
            <td style="border: none; text-align:right;">
                {{ $fmtRupiah($shippingVal) }}
            </td>
        </tr>
    @endif

    @if(abs($diffVal) >= 0.01)
        <tr>
            <td style="border: none;"></td>
            <td style="border: none; text-align:right;" class="bold reconciliation-diff">
                Selisih Rekonsiliasi
            </td>
            <td style="border: none; text-align:right;" class="reconciliation-diff">
                {{ $diffVal > 0 ? '+' : '' }}{{ $fmtRupiah($diffVal) }}
            </td>
        </tr>
    @endif

    <tr>
        <td style="border: none;"></td>
        <td style="border: none; text-align:right; font-size:12px;" class="bold">Total</td>
        <td style="border: none; text-align:right; font-size:12px;" class="bold">
            {{ $fmtRupiah($grandTotalVal) }}
        </td>
    </tr>

    @if($showSettlement)
        <tr>
            <td style="border: none;"></td>
            <td style="border: none; text-align:right;" class="bold">Bayaran Diterima</td>
            <td style="border: none; text-align:right;">
                {{ $fmtRupiah($monetaryPaid) }}
            </td>
        </tr>
        @if($creditsApplied > 0)
            <tr>
                <td style="border: none;"></td>
                <td style="border: none; text-align:right;" class="bold">Saldo Kredit / Retur</td>
                <td style="border: none; text-align:right;">
                    {{ $fmtRupiah($creditsApplied) }}
                </td>
            </tr>
        @endif
        <tr>
            <td style="border: none;"></td>
            <td style="border: none; text-align:right;" class="bold">Sisa Tagihan</td>
            <td style="border: none; text-align:right;">
                {{ $fmtRupiah($dueVal) }}
            </td>
        </tr>
    @endif
</table>

<table class="ttd-table">
    <tr>
        <td>
            Penerima / Pelanggan,<br>
            Tanda Tangan / Cap<br><br><br><br>
            (...............................................)
        </td>
        <td>
            Hormat kami,<br><br><br><br><br>
            (...............................................)
        </td>
    </tr>
</table>
</body>
</html>
