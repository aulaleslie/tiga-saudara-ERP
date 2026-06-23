<?php

namespace App\Exports;

use App\Services\Reports\InventorySummaryReportFilterData;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class InventorySummaryReportExport implements FromCollection, WithHeadings, WithMapping, WithEvents, WithTitle
{
    private Collection $data;
    private InventorySummaryReportFilterData $filterData;
    private bool $isCsv;
    private float $totalValue;

    public function __construct(Collection $data, InventorySummaryReportFilterData $filterData, bool $isCsv = false)
    {
        $this->data = $data;
        $this->filterData = $filterData;
        $this->isCsv = $isCsv;
        $this->totalValue = $data->sum('value');
    }

    public function title(): string
    {
        return 'Inventory Summary';
    }

    public function collection()
    {
        return $this->data;
    }

    public function headings(): array
    {
        return [
            'Kode Produk',
            'Nama Produk',
            'Stok di tangan',
            'Batas Minimum',
            'Satuan',
            'Harga Rata-rata',
            'Nilai',
        ];
    }

    public function map($row): array
    {
        return [
            $row['product_code'] ?: '',
            $row['product_name'],
            (float) $row['stock'],
            (float) $row['minimum_stock'],
            $row['product_unit'],
            (float) $row['average_cost'],
            (float) $row['value'],
        ];
    }

    public function registerEvents(): array
    {
        if ($this->isCsv) {
            return [];
        }

        $asOfDate = $this->filterData->asOfDate;
        $columnCount = count($this->headings());
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        $totalValue = $this->totalValue;
        $sortCol = $this->filterData->sortColumn ?? 'product_name';
        $sortDir = $this->filterData->sortDirection ?? 'asc';

        $settingId = session('setting_id');
        $companyName = $settingId ? \Modules\Setting\Entities\Setting::find($settingId)?->company_name : 'Tiga Saudara ERP';

        $sortColMap = [
            'product_code' => 'Kode Produk',
            'product_name' => 'Nama Produk',
            'stock' => 'Stok di tangan',
            'average_cost' => 'Harga Rata-rata',
            'value' => 'Nilai',
        ];
        $sortColFormatted = $sortColMap[$sortCol] ?? $sortCol;
        $sortDirFormatted = strtolower($sortDir) === 'asc' ? 'A-Z' : 'Z-A';

        return [
            AfterSheet::class => function (AfterSheet $event) use ($asOfDate, $lastColumn, $totalValue, $companyName, $sortColFormatted, $sortDirFormatted) {
                $sheet = $event->sheet->getDelegate();

                $sheet->insertNewRowBefore(1, 6);

                $sheet->setCellValue('A1', $companyName ?: 'Tiga Saudara ERP');
                $sheet->setCellValue('A2', 'Ringkasan Persediaan Barang');
                $sheet->setCellValue('A3', 'As Of: ' . ($asOfDate ? $asOfDate->format('d/m/Y') : '-'));
                $sheet->setCellValue('A4', '(dalam IDR)');
                $sheet->setCellValue('A5', "Sorted by: {$sortColFormatted}, {$sortDirFormatted}");

                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
                $sheet->getStyle('7:7')->getFont()->setBold(true);

                $highestRow = $sheet->getHighestRow();
                $totalRow = $highestRow + 1;
                $sheet->setCellValue("F{$totalRow}", 'Total Nilai:');
                $sheet->setCellValue("G{$totalRow}", $totalValue);
                $sheet->getStyle("F{$totalRow}:G{$totalRow}")->getFont()->setBold(true);
            },
        ];
    }
}
