<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $documentTitle ?? 'PEMBELIAN' }} #{{ $documentNumber ?? ($purchase->reference ?? $purchase->id) }}</title>
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
    $compName = $company['name'] ?? ($purchase->tenantSetting->company_name ?? 'TIGA COMPUTER');
    $compAddr = $company['address'] ?? ($purchase->tenantSetting->company_address ?? '');
    $compPhone = $company['phone'] ?? ($purchase->tenantSetting->company_phone ?? '');
    $compEmail = $company['email'] ?? ($purchase->tenantSetting->company_email ?? '');
    $isPkp = isset($isPkp) ? (bool)$isPkp : (bool)($purchase->tenantSetting->is_pkp ?? false);

    $docTitle = $documentTitle ?? 'PEMBELIAN';
    $docNumber = $documentNumber ?? ($purchase->reference ?? (string)$purchase->id);
    $dateStr = $dateFormatted ?? ($purchase->date ? \Carbon\Carbon::parse($purchase->date)->format('d/m/Y') : '-');
    $dueStr = $dueDateFormatted ?? ($purchase->due_date ? \Carbon\Carbon::parse($purchase->due_date)->format('d/m/Y') : '-');

    $statLabel = $statusLabel ?? (string)($purchase->status ?? 'Approved');
    $isDraft = $isDraft ?? in_array($purchase->status ?? '', [\Modules\Purchase\Entities\Purchase::STATUS_DRAFTED, 'Draft', 'DRAFT'], true);
    $isPending = $isPending ?? in_array($purchase->status ?? '', [\Modules\Purchase\Entities\Purchase::STATUS_WAITING_APPROVAL, 'Pending', 'PENDING'], true);
    $isRejected = $isRejected ?? in_array($purchase->status ?? '', [\Modules\Purchase\Entities\Purchase::STATUS_REJECTED, 'Rejected', 'REJECTED'], true);
    $isArch = $isArchived ?? ($purchase->isArchived());

    $showSettlement = isset($showSettlement) ? (bool)$showSettlement : ($variant ?? 'purchase') === 'purchase';

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
                    <td style="width: 32%;">Ref. Supplier</td>
                    <td style="width: 3%;">:</td>
                    <td style="width: 65%;">{{ $purchase->reference ?? '-' }}</td>
                </tr>
                @if($isPkp && !empty($purchase->tax_ref_no))
                <tr>
                    <td>No. Faktur Pajak</td>
                    <td>:</td>
                    <td>{{ $purchase->tax_ref_no }}</td>
                </tr>
                @endif
                <tr>
                    <td>Tanggal</td>
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
            <div style="margin-bottom: 12px;">
                <span class="bold">KEPADA YTH.</span><br>
                {{ $supplier->contact_name ?? ($supplier->supplier_name ?? '-') }}<br>
                @if(!empty($supplier->supplier_name) && $supplier->supplier_name !== ($supplier->contact_name ?? ''))
                    {{ $supplier->supplier_name }}<br>
                @endif
                @if(!empty($supplier->address))
                    <span class="small">{{ $supplier->address }}</span><br>
                @endif
                @if(!empty($supplier->supplier_phone))
                    <span class="small">Telp: {{ $supplier->supplier_phone }}</span>
                @endif
            </div>

            <table class="ref-table">
                <tr>
                    <td class="bold" style="width: 25%;">NO.</td>
                    <td class="bold" style="width: 75%; font-size: 13px;">
                        {{ $docNumber }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<table class="main-table">
    <thead>
    <tr>
        <th style="width:30%">Nama Barang</th>
        <th style="width:7%" class="text-center">Qty</th>
        <th style="width:9%" class="text-center">Satuan</th>
        <th style="width:16%" class="text-right">Harga Satuan<br>(Rp.)</th>
        <th style="width:10%" class="text-right">Diskon%</th>
        <th style="width:12%" class="text-right">Nilai Diskon</th>
        <th style="width:16%" class="text-right">Jumlah (Rp.)</th>
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
            $pCode = $isArr ? ($item['product_code'] ?? null) : ($item->product_code ?? null);
            $qty = $isArr ? ($item['entered_quantity'] ?? 0) : $item->effective_entered_quantity;
            $unit = $isArr ? ($item['unit_name'] ?? '-') : $item->effective_unit_name;
            $price = $isArr ? ($item['entered_unit_price'] ?? 0) : $item->effective_entered_unit_price;
            $isPct = $isArr ? ($item['is_percentage_discount'] ?? false) : $item->isPercentageDiscount();
            $pctVal = $isArr ? ($item['discount_percentage'] ?? null) : ($isPct ? $item->resolveEnteredDiscountInput() : null);
            $amtVal = $isArr ? ($item['discount_amount'] ?? 0) : $item->resolveEnteredCurrencyDiscount();
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
            <td class="text-center">{{ $fmtQty($qty) }}</td>
            <td class="text-center">{{ $unit }}</td>
            <td class="text-right">{{ $fmtRupiah($price) }}</td>
            <td class="text-right">
                @if($isPct && $pctVal !== null)
                    {{ rtrim(rtrim(number_format((float)$pctVal, 2, '.', ''), '0'), '.') }}%
                @elseif($price > 0 && $amtVal > 0)
                    {{ number_format(($amtVal / $price) * 100, 1, '.', '') }}%
                @else
                    -
                @endif
            </td>
            <td class="text-right">{{ $fmtRupiah($amtVal) }}</td>
            <td class="text-right">{{ $fmtRupiah($sub) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

@php
    $subtotalVal = isset($totals['subtotal']) ? (float)$totals['subtotal'] : $runningSubtotal;
    $discVal = isset($totals['overall_discount']) ? (float)$totals['overall_discount'] : (float)($purchase->discount_amount ?? 0);
    $discPct = isset($totals['overall_discount_percentage']) ? (float)$totals['overall_discount_percentage'] : (float)($purchase->discount_percentage ?? 0);
    $shippingVal = isset($totals['shipping']) ? (float)$totals['shipping'] : (float)($purchase->shipping_amount ?? 0);
    $dppVal = isset($totals['dpp']) ? (float)$totals['dpp'] : 0.0;
    $taxVal = isset($totals['tax_amount']) ? (float)$totals['tax_amount'] : $runningTax;
    $diffVal = isset($totals['reconciliation_diff']) ? (float)$totals['reconciliation_diff'] : 0.0;
    $grandTotalVal = isset($totals['grand_total']) ? (float)$totals['grand_total'] : (float)$purchase->total_amount;
    $paidVal = isset($totals['paid_amount']) ? (float)$totals['paid_amount'] : (float)$purchase->paid_amount;
    $dueVal = isset($totals['due_amount']) ? (float)$totals['due_amount'] : (float)$purchase->due_amount;
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
                    PPN {{ ($purchase->tax_percentage ?? 0) > 0 ? '(' . rtrim(rtrim(number_format((float)$purchase->tax_percentage, 2, '.', ''), '0'), '.') . '%)' : '' }}
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
                {{ $fmtRupiah($paidVal) }}
            </td>
        </tr>
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
            Penerima / Supplier,<br>
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
