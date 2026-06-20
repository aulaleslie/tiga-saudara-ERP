<table>
    <thead>
        <tr>
            <th colspan="8" style="font-size: 16px; font-weight: bold;">Buku Besar</th>
        </tr>
        <tr>
            <th colspan="8">Periode: {{ \Carbon\Carbon::parse($report->startDate)->format('d M Y') }} - {{ \Carbon\Carbon::parse($report->endDate)->format('d M Y') }}</th>
        </tr>
        <tr>
            <th colspan="8">Mata Uang: {{ $report->currencyCode }}</th>
        </tr>
        <tr>
            <th colspan="8" style="font-style: italic;">{{ $report->sourceNote }}</th>
        </tr>
        <tr>
            <th colspan="8"></th>
        </tr>
        <tr>
            <th style="font-weight: bold; border: 1px solid #000000;">Nama Akun / Tanggal</th>
            <th style="font-weight: bold; border: 1px solid #000000;">Transaksi</th>
            <th style="font-weight: bold; border: 1px solid #000000;">No.</th>
            <th style="font-weight: bold; border: 1px solid #000000;">Deskripsi</th>
            <th style="font-weight: bold; border: 1px solid #000000; text-align: right;">Debit</th>
            <th style="font-weight: bold; border: 1px solid #000000; text-align: right;">Kredit</th>
            <th style="font-weight: bold; border: 1px solid #000000; text-align: right;">Saldo</th>
            <th style="font-weight: bold; border: 1px solid #000000;">Tag</th>
        </tr>
    </thead>
    <tbody>
        @if(empty($report->buckets))
        <tr>
            <td colspan="8" style="text-align: center;">Tidak ada transaksi yang sesuai dengan filter yang dipilih.</td>
        </tr>
        @else
            @foreach($report->buckets as $bucket)
                <tr>
                    <td colspan="6" style="font-weight: bold; background-color: #f2f2f2; border: 1px solid #000000;">{{ $bucket->label }}</td>
                    <td style="font-weight: bold; text-align: right; background-color: #f2f2f2; border: 1px solid #000000;">{{ $bucket->beginningBalance }}</td>
                    <td style="background-color: #f2f2f2; border: 1px solid #000000;"></td>
                </tr>
                <tr>
                    <td colspan="6" style="font-style: italic; border: 1px solid #000000;">Saldo Awal</td>
                    <td style="font-style: italic; text-align: right; border: 1px solid #000000;">{{ $bucket->beginningBalance }}</td>
                    <td style="border: 1px solid #000000;"></td>
                </tr>

                @foreach($bucket->rows as $row)
                <tr>
                    <td style="border: 1px solid #000000;">{{ \Carbon\Carbon::parse($row->date)->format('d/m/Y') }}</td>
                    <td style="border: 1px solid #000000;">{{ $row->sourceType }}</td>
                    <td style="border: 1px solid #000000;">{{ $row->reference }}</td>
                    <td style="border: 1px solid #000000;">{{ $row->description }}</td>
                    <td style="text-align: right; border: 1px solid #000000;">{{ $row->debit > 0 ? $row->debit : '' }}</td>
                    <td style="text-align: right; border: 1px solid #000000;">{{ $row->credit > 0 ? $row->credit : '' }}</td>
                    <td style="text-align: right; border: 1px solid #000000;">{{ $row->balance }}</td>
                    <td style="border: 1px solid #000000;">{{ $row->tag }}</td>
                </tr>
                @endforeach

                <tr>
                    <td colspan="4" style="font-weight: bold; text-align: right; background-color: #f2f2f2; border: 1px solid #000000;">Pergerakan Periode</td>
                    <td style="font-weight: bold; text-align: right; background-color: #f2f2f2; border: 1px solid #000000;">{{ $bucket->periodDebit > 0 ? $bucket->periodDebit : '' }}</td>
                    <td style="font-weight: bold; text-align: right; background-color: #f2f2f2; border: 1px solid #000000;">{{ $bucket->periodCredit > 0 ? $bucket->periodCredit : '' }}</td>
                    <td style="font-weight: bold; text-align: right; background-color: #f2f2f2; border: 1px solid #000000;">{{ $bucket->endingBalance }}</td>
                    <td style="background-color: #f2f2f2; border: 1px solid #000000;"></td>
                </tr>
                
                <tr>
                    <td colspan="8"></td>
                </tr>
            @endforeach
        @endif
    </tbody>
</table>
