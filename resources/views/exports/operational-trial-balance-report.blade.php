<table>
    <thead>
        <tr>
            <th colspan="8" style="font-size: 16px; font-weight: bold;">{{ $setting?->company_name ?? 'Company' }}</th>
        </tr>
        <tr>
            <th colspan="8" style="font-size: 14px; font-weight: bold;">Neraca Saldo</th>
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
            <th rowspan="2" style="font-weight: bold; border: 1px solid #000000; vertical-align: middle;">Kode Akun</th>
            <th rowspan="2" style="font-weight: bold; border: 1px solid #000000; vertical-align: middle;">Kategori / Akun</th>
            <th colspan="2" style="font-weight: bold; border: 1px solid #000000; text-align: center;">Saldo Awal</th>
            <th colspan="2" style="font-weight: bold; border: 1px solid #000000; text-align: center;">Pergerakan</th>
            <th colspan="2" style="font-weight: bold; border: 1px solid #000000; text-align: center;">Saldo Akhir</th>
        </tr>
        <tr>
            <th style="font-weight: bold; border: 1px solid #000000; text-align: right;">Debit</th>
            <th style="font-weight: bold; border: 1px solid #000000; text-align: right;">Kredit</th>
            <th style="font-weight: bold; border: 1px solid #000000; text-align: right;">Debit</th>
            <th style="font-weight: bold; border: 1px solid #000000; text-align: right;">Kredit</th>
            <th style="font-weight: bold; border: 1px solid #000000; text-align: right;">Debit</th>
            <th style="font-weight: bold; border: 1px solid #000000; text-align: right;">Kredit</th>
        </tr>
    </thead>
    <tbody>
        @if(empty($report->categories))
        <tr>
            <td colspan="8" style="text-align: center;">Tidak ada transaksi yang sesuai dengan filter yang dipilih.</td>
        </tr>
        @else
            @foreach($report->categories as $category)
                <tr>
                    <td colspan="8" style="font-weight: bold; background-color: #f2f2f2; border: 1px solid #000000;">{{ $category->categoryName }}</td>
                </tr>
                @foreach($category->rows as $row)
                <tr>
                    <td style="border: 1px solid #000000;">{{ $row->code }}</td>
                    <td style="border: 1px solid #000000;">{{ $row->label }}</td>
                    <td style="text-align: right; border: 1px solid #000000;">{{ $row->openingDebit > 0 ? $row->openingDebit : '' }}</td>
                    <td style="text-align: right; border: 1px solid #000000;">{{ $row->openingCredit > 0 ? $row->openingCredit : '' }}</td>
                    <td style="text-align: right; border: 1px solid #000000;">{{ $row->periodDebit > 0 ? $row->periodDebit : '' }}</td>
                    <td style="text-align: right; border: 1px solid #000000;">{{ $row->periodCredit > 0 ? $row->periodCredit : '' }}</td>
                    <td style="text-align: right; border: 1px solid #000000;">{{ $row->endingDebit > 0 ? $row->endingDebit : '' }}</td>
                    <td style="text-align: right; border: 1px solid #000000;">{{ $row->endingCredit > 0 ? $row->endingCredit : '' }}</td>
                </tr>
                @endforeach
                <tr>
                    <td colspan="2" style="font-weight: bold; text-align: right; background-color: #f2f2f2; border: 1px solid #000000;">Total {{ $category->categoryName }}</td>
                    <td style="font-weight: bold; text-align: right; background-color: #f2f2f2; border: 1px solid #000000;">{{ $category->totalOpeningDebit > 0 ? $category->totalOpeningDebit : '' }}</td>
                    <td style="font-weight: bold; text-align: right; background-color: #f2f2f2; border: 1px solid #000000;">{{ $category->totalOpeningCredit > 0 ? $category->totalOpeningCredit : '' }}</td>
                    <td style="font-weight: bold; text-align: right; background-color: #f2f2f2; border: 1px solid #000000;">{{ $category->totalPeriodDebit > 0 ? $category->totalPeriodDebit : '' }}</td>
                    <td style="font-weight: bold; text-align: right; background-color: #f2f2f2; border: 1px solid #000000;">{{ $category->totalPeriodCredit > 0 ? $category->totalPeriodCredit : '' }}</td>
                    <td style="font-weight: bold; text-align: right; background-color: #f2f2f2; border: 1px solid #000000;">{{ $category->totalEndingDebit > 0 ? $category->totalEndingDebit : '' }}</td>
                    <td style="font-weight: bold; text-align: right; background-color: #f2f2f2; border: 1px solid #000000;">{{ $category->totalEndingCredit > 0 ? $category->totalEndingCredit : '' }}</td>
                </tr>
            @endforeach
                <tr>
                    <td colspan="2" style="font-weight: bold; color: #ffffff; text-align: right; background-color: #343a40; border: 1px solid #000000;">TOTAL KESELURUHAN</td>
                    <td style="font-weight: bold; color: #ffffff; text-align: right; background-color: #343a40; border: 1px solid #000000;">{{ $report->grandTotalOpeningDebit > 0 ? $report->grandTotalOpeningDebit : '' }}</td>
                    <td style="font-weight: bold; color: #ffffff; text-align: right; background-color: #343a40; border: 1px solid #000000;">{{ $report->grandTotalOpeningCredit > 0 ? $report->grandTotalOpeningCredit : '' }}</td>
                    <td style="font-weight: bold; color: #ffffff; text-align: right; background-color: #343a40; border: 1px solid #000000;">{{ $report->grandTotalPeriodDebit > 0 ? $report->grandTotalPeriodDebit : '' }}</td>
                    <td style="font-weight: bold; color: #ffffff; text-align: right; background-color: #343a40; border: 1px solid #000000;">{{ $report->grandTotalPeriodCredit > 0 ? $report->grandTotalPeriodCredit : '' }}</td>
                    <td style="font-weight: bold; color: #ffffff; text-align: right; background-color: #343a40; border: 1px solid #000000;">{{ $report->grandTotalEndingDebit > 0 ? $report->grandTotalEndingDebit : '' }}</td>
                    <td style="font-weight: bold; color: #ffffff; text-align: right; background-color: #343a40; border: 1px solid #000000;">{{ $report->grandTotalEndingCredit > 0 ? $report->grandTotalEndingCredit : '' }}</td>
                </tr>
        @endif
    </tbody>
</table>
