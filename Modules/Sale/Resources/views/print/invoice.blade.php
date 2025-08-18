<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Sales Invoice #{{ $invoiceNumber }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; }
        .title { text-align: center; font-size: 24px; font-weight: bold; margin-bottom: 8px; }
        .row-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .row-table td { vertical-align: top; }
        .info-table, .ref-table { width: 100%; border-collapse: collapse; }
        .info-table td, .ref-table td { font-size: 12px; padding: 2px 4px; }
        .main-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .main-table th, .main-table td { border: 1px solid #222; padding: 6px 4px; }
        .main-table th { font-size: 13px; font-weight: bold; background: #fff; }
        .main-table td { font-size: 12px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .bold { font-weight: bold; }
        .small { font-size: 11px; }
        .upper { text-transform: uppercase; }
        .box { border: 1px solid #222; padding: 8px; margin-top: 14px; margin-bottom: 10px; }
        .footer-table { width: 100%; margin-top: 10px; }
        .footer-table td { padding: 2px 4px; font-size: 13px; }
        .ttd-table { width: 100%; margin-top: 46px; }
        .ttd-table td { width: 50%; vertical-align: bottom; text-align: center; font-size: 13px; }
    </style>
</head>
<body>
<div class="title">FAKTUR</div>

<table class="row-table">
    <tr>
        <td style="width: 55%;">
            <span class="bold">{{ settings()->company_name ?? 'TIGA COMPUTER' }}</span><br>
            <div class="small" style="margin-bottom:4px">
                {{ settings()->company_address ?? 'JL. SOEKARNO HATTA RT. 012  RW. 004 PANE, RASANAE BARAT' }}<br>
                Telp: {{ settings()->company_phone ?? '082236387676' }}<br>
                Fax:
            </div>

            <table class="info-table">
                <tr>
                    <td style="width: 32%;">Ref. Pelanggan</td>
                    <td style="width: 3%;">:</td>
                    <td style="width: 65%;">{{ $sale->reference ?? '-' }}</td>
                </tr>
                <tr>
                    <td>Tgl.Faktur</td>
                    <td>:</td>
                    <td>{{ $tanggal->format('d/m/Y') }}</td>
                </tr>
                <tr>
                    <td>Jatuh Tempo</td>
                    <td>:</td>
                    <td>{{ $jatuhTempo->format('d/m/Y') }}</td>
                </tr>
            </table>
        </td>

        <td style="width: 45%; vertical-align: top;">
            <table class="ref-table" style="margin-bottom: 10px;">
                <tr>
                    <td class="bold" style="width: 20%;">NO.</td>
                    <td class="bold" style="width: 80%;">{{ $invoiceNumber }}</td>
                </tr>
            </table>

            <div style="margin-top: 8px;">
                <span class="bold">KEPADA YTH.</span><br>
                {{ $customer->customer_name ?: 'CASH' }}
            </div>
        </td>
    </tr>
</table>

<table class="main-table">
    <thead>
    <tr>
        <th style="width:40%;">Nama Barang</th>
        <th style="width:15%;">Kemasan</th>
        <th style="width:10%;" class="text-center">Qty</th>
        <th style="width:17%;" class="text-right">Harga Satuan<br>(Rp.)</th>
        <th style="width:18%;" class="text-right">Jumlah (Rp.)</th>
    </tr>
    </thead>
    <tbody>
    @php
        $rupiah = fn($n) => number_format((float)$n, 2, ',', '.');
    @endphp

    @foreach($details as $detail)
        <tr>
            <td>
                {{ $detail->product_name ?? ($detail->product->product_name ?? '-') }}
                @if(!empty($detail->product_code))
                    <br><span class="small">{{ $detail->product_code }}</span>
                @endif
            </td>
            <td class="text-center">{{ $detail->product->baseUnit->name ?? '-' }}</td>
            <td class="text-center">{{ $detail->quantity }}</td>
            <td class="text-right">{{ $rupiah($detail->unit_price ?? $detail->price) }}</td>
            <td class="text-right">{{ $rupiah($detail->sub_total) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="box">
    <div class="bold">TERBILANG</div>
    <div class="upper" style="margin-top:4px;">
        {{ function_exists('terbilang') ? strtoupper(terbilang($total)) . ' RUPIAH' : '' }}
    </div>
</div>

<table class="footer-table">
    <tr>
        <td style="border: none; width:80%"></td>
        <td style="border: none; text-align:right;" class="bold">Total</td>
        <td style="border: none; width:120px; text-align:right;">{{ $rupiah($total) }}</td>
    </tr>
    <tr>
        <td style="border: none;"></td>
        <td style="border: none; text-align:right;" class="bold">Netto</td>
        <td style="border: none; text-align:right;">{{ $rupiah($total) }}</td>
    </tr>
    <tr>
        <td style="border: none;"></td>
        <td style="border: none; text-align:right;" class="bold">Bayaran Diterima</td>
        <td style="border: none; text-align:right;">{{ $rupiah($paid) }}</td>
    </tr>
    <tr>
        <td style="border: none;"></td>
        <td style="border: none; text-align:right;" class="bold">Sisa Tagihan</td>
        <td style="border: none; text-align:right;">{{ $rupiah($due) }}</td>
    </tr>
</table>

<table class="ttd-table">
    <tr>
        <td>
            Penerima,<br>
            Tanda Tangan / Cap<br><br><br>
            (...............................................)
        </td>
        <td>
            Hormat kami,<br><br><br><br>
            (...............................................)
        </td>
    </tr>
</table>
</body>
</html>
