<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Surat Jalan #{{ $slipNumber }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; }
        .title { text-align: center; font-size: 20px; font-weight: bold; margin-bottom: 8px; }
        .row-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .row-table td { vertical-align: top; }
        .info-table, .ref-table { width: 100%; border-collapse: collapse; }
        .info-table td, .ref-table td { font-size: 12px; padding: 2px 4px; }
        .main-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .main-table th, .main-table td { border: 1px solid #222; padding: 6px 4px; }
        .main-table th { font-size: 13px; font-weight: bold; background: #fff; }
        .main-table td { font-size: 12px; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }
        .small { font-size: 11px; }
        .badge { display: inline-block; padding: 2px 6px; margin: 1px 4px 0 0; background: #eee; border: 1px solid #aaa; border-radius: 4px; font-size: 10.5px; }
        .block { display: block; }
    </style>
</head>
<body>

<div class="title">SURAT JALAN</div>

<table class="row-table">
    <tr>
        {{-- LEFT: Company & slip meta --}}
        <td style="width: 55%;">
            <span class="bold">{{ settings()->company_name ?? 'TIGA COMPUTER' }}</span><br>
            <div class="small" style="margin-bottom:4px;">
                {{ settings()->company_address ?? 'JL. SOEKARNO HATTA RT. 012 RW. 004 PANE, RASANAE BARAT' }}<br>
                Telp: {{ settings()->company_phone ?? '082236387676' }}<br>
                Email: {{ settings()->company_email ?? '-' }}
            </div>

            <table class="info-table">
                <tr>
                    <td style="width: 32%;">Pengiriman #</td>
                    <td style="width: 3%;">:</td>
                    <td style="width: 65%;">{{ $slipNumber }}</td>
                </tr>
                <tr>
                    <td>Tanggal</td>
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

        {{-- RIGHT: Recipient --}}
        <td style="width: 45%; vertical-align: top;">
            <div style="margin-bottom: 10px;">
                <span class="bold">KEPADA YTH.</span><br>
                {{ $customer->customer_name ?: 'WALK IN' }}<br>
                @if(!empty($customer->shipping_address ?? $customer->address))
                    <span class="small">{{ $customer->shipping_address ?? $customer->address }}</span>
                @endif
            </div>
        </td>
    </tr>
</table>

<table class="main-table">
    <thead>
    <tr>
        <th style="width:25%;">Kode</th>
        <th style="width:50%;">Nama Barang</th>
        <th style="width:10%;" class="text-center">Qty</th>
        <th style="width:15%;" class="text-center">Satuan</th>
    </tr>
    </thead>
    <tbody>
    @foreach($grouped as $row)
        <tr>
            <td>{{ $row->product_code ?? '-' }}</td>
            <td>
                {{ $row->product->product_name ?? '-' }}
                {{-- serials as badges under product name --}}
                @if(!empty($row->serial_numbers) && count($row->serial_numbers) > 0)
                    <span class="block" style="margin-top:4px;">
                        @foreach($row->serial_numbers as $sn)
                            <span class="badge">{{ $sn }}</span>
                        @endforeach
                    </span>
                @endif
            </td>
            <td class="text-center">{{ $row->quantity }}</td>
            <td class="text-center">{{ $row->unit_name ?? '-' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

{{-- Signatures --}}
<table style="width:100%; border-collapse: collapse; margin-top: 36px;">
    <tr>
        <td style="width:33%; text-align:center;">
            Gudang,<br><br><br><br>
            (____________________)
        </td>
        <td style="width:33%; text-align:center;">
            Pengemudi,<br><br><br><br>
            (____________________)
        </td>
        <td style="width:33%; text-align:center;">
            Penerima,<br><br><br><br>
            (____________________)
        </td>
    </tr>
</table>

</body>
</html>
