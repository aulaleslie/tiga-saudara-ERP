<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoiceNo }}</title>
    <style>
        body {
            font-family: sans-serif;
            font-size: 12px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .table, .table th, .table td {
            border: 1px solid black;
        }

        .table th, .table td {
            padding: 6px;
            text-align: left;
        }
    </style>
</head>
<body>
<h2>FAKTUR PENJUALAN</h2>
<p><strong>No:</strong> {{ $invoiceNo }}</p>
<p><strong>Tanggal:</strong> {{ $invoiceDate }}</p>
<p><strong>Pelanggan:</strong> {{ $customer['*DisplayName'] ?? 'N/A' }}</p>
<p><strong>NPWP:</strong> {{ $customer['TaxNumber'] ?? '-' }}</p>

<table class="table">
    <thead>
    <tr>
        <th>Produk</th>
        <th>Kuantitas</th>
        <th>Satuan</th>
        <th>Harga Satuan</th>
        <th>Subtotal</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($items as $item)
        <tr>
            <td>{{ $item['Produk'] }}</td>
            <td>{{ $item['Kuantitas'] }}</td>
            <td>{{ $item['Satuan'] }}</td>
            <td>{{ number_format($item['Harga Satuan'], 0, ',', '.') }}</td>
            <td>{{ number_format($item['Jumlah Tagihan'], 0, ',', '.') }}</td>
        </tr>
    @endforeach
    </tbody>
    @if ($taxes->isNotEmpty())
        <tfoot>
        <tr>
            <td colspan="4"><strong>PPN</strong></td>
            <td>{{ number_format($taxes->sum('Jumlah Tagihan'), 0, ',', '.') }}</td>
        </tr>
        </tfoot>
    @endif
</table>

<p><strong>Total:</strong> Rp {{ number_format($total, 0, ',', '.') }}</p>
</body>
</html>
