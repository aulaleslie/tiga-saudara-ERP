<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Purchase Report</title></head>
<body>
<h2>Laporan Pembelian</h2>
<table width="100%" border="1" cellspacing="0" cellpadding="5">
    <thead>
    <tr>
        <th>Tanggal</th>
        <th>Referensi</th>
        <th>Supplier</th>
        <th>Total</th>
        <th>Pajak</th>
        <th>Tax Included</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($purchases as $p)
        <tr>
            <td>{{ $p['Date'] }}</td>
            <td>{{ $p['Reference'] }}</td>
            <td>{{ $p['Supplier'] }}</td>
            <td>{{ number_format($p['Total Amount'], 2) }}</td>
            <td>{{ number_format($p['Tax Amount'], 2) }}</td>
            <td>{{ $p['Tax Included'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
