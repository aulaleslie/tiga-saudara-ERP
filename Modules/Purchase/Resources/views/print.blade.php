<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Purchase Invoice #{{ $purchase->reference ?? $purchase->id }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; }
        .title { text-align: center; font-size: 24px; font-weight: bold; margin-bottom: 8px; }
        .row-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .row-table td { vertical-align: top; }
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        .info-table td { font-size: 12px; padding: 2px 4px; }
        .ref-table { width: 100%; border-collapse: collapse; margin-top: 0; }
        .ref-table td { font-size: 12px; padding: 2px 4px; }
        .main-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .main-table th, .main-table td { border: 1px solid #222; padding: 6px 4px; }
        .main-table th { font-size: 13px; font-weight: bold; background: #fff; }
        .main-table td { font-size: 12px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .bold { font-weight: bold; }
        .box { border: 1px solid #222; padding: 8px; margin-top: 14px; margin-bottom: 10px; }
        .footer-table { width: 100%; margin-top: 10px; }
        .footer-table td { padding: 2px 4px; font-size: 13px; }
        .ttd-table { width: 100%; margin-top: 46px; }
        .ttd-table td { width: 50%; vertical-align: bottom; text-align: center; font-size: 13px; }
        .small { font-size: 11px; }
        .upper { text-transform: uppercase; }
    </style>
</head>
<body>
<div class="title">PEMBELIAN</div>

<table class="row-table">
    <tr>
        <td style="width: 55%;">
            <span class="bold">TIGA COMPUTER</span><br>
            <div style="margin-bottom:4px">
                JL. SOEKARNO HATTA RT. 012 RW. 004 PANE, RASANAE BARAT<br>
                Telp: 082236387676<br>
                Fax:
            </div>
            <table class="info-table">
                <tr>
                    <td style="width: 32%;">Ref. Supplier</td>
                    <td style="width: 3%;">:</td>
                    <td style="width: 65%;">{{ $purchase->reference ?? '-' }}</td>
                </tr>
                <tr>
                    <td>Tanggal Pembelian</td>
                    <td>:</td>
                    <td>{{ \Carbon\Carbon::parse($purchase->date)->format('d/m/Y') }}</td>
                </tr>
                <tr>
                    <td>Jatuh Tempo</td>
                    <td>:</td>
                    <td>{{ \Carbon\Carbon::parse($purchase->due_date)->format('d/m/Y') }}</td>
                </tr>
            </table>
        </td>
        <td style="width: 45%; vertical-align: top;">
            <div style="margin-bottom:16px;">
                <span class="bold">KEPADA YTH.</span><br>
                {{ $supplier->contact_name ?? '-' }}<br>
                {{ $supplier->supplier_name ?? '-' }}
            </div>
            <table class="ref-table">
                <tr>
                    <td class="bold" style="width: 30%;">NO.</td>
                    <td class="bold" style="width: 70%;">
                        {{ $purchase->reference ?? '-' }}
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
        <th style="width:7%">Qty</th>
        <th style="width:9%">Satuan</th>
        <th style="width:16%">Harga Satuan<br>(Rp.)</th>
        <th style="width:10%">Diskon%</th>
        <th style="width:12%">Nilai Diskon</th>
        <th style="width:16%">Jumlah (Rp.)</th>
    </tr>
    </thead>
    <tbody>
    @foreach($details as $detail)
        <tr>
            <td>
                {{ $detail->product_name ?? ($detail->product->product_name ?? '-') }}
                @if(!empty($detail->product_code))
                    <br><span class="small">{{ $detail->product_code }}</span>
                @endif
            </td>
            <td class="text-center">{{ $detail->quantity }}</td>
            <td class="text-center">
                {{ $detail->product->baseUnit->name ?? '-' }}
            </td>
            <td class="text-right">{{ number_format($detail->unit_price, 0, ',', '.') }}</td>
            <td class="text-right">
                {{ $detail->unit_price > 0
                    ? number_format(($detail->product_discount_amount / $detail->unit_price) * 100, 1)
                    : '0.0' }}%
            </td>
            <td class="text-right">{{ number_format($detail->product_discount_amount, 0, ',', '.') }}</td>
            <td class="text-right">{{ number_format($detail->sub_total, 0, ',', '.') }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="box">
    <div class="bold">TERBILANG</div>
    <div class="upper" style="margin-top:4px;">
        {{ function_exists('terbilang') ? strtoupper(terbilang($purchase->total_amount)) . ' RUPIAH' : '' }}
    </div>
</div>

<table class="footer-table">
    <tr>
        <td style="border: none; width:80%"></td>
        <td style="border: none; text-align:right;" class="bold">Total</td>
        <td style="border: none; width:120px; text-align:right;">
            {{ number_format($purchase->total_amount, 0, ',', '.') }}
        </td>
    </tr>
    <tr>
        <td style="border: none;"></td>
        <td style="border: none; text-align:right;" class="bold">Netto</td>
        <td style="border: none; text-align:right;">
            {{ number_format($purchase->total_amount, 0, ',', '.') }}
        </td>
    </tr>
    <tr>
        <td style="border: none;"></td>
        <td style="border: none; text-align:right;" class="bold">Sisa Tagihan</td>
        <td style="border: none; text-align:right;">
            {{ number_format($purchase->due_amount, 0, ',', '.') }}
        </td>
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
