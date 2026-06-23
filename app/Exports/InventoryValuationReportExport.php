<?php

namespace App\Exports;

use App\Services\Reports\InventoryValuationReportFilterData;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InventoryValuationReportExport implements FromArray, WithHeadings, WithStyles
{
    private Collection $allRows;
    private float $totalValue;
    private InventoryValuationReportFilterData $filterData;
    private bool $isCsv;

    public function __construct(Collection $allRows, float $totalValue, InventoryValuationReportFilterData $filterData, bool $isCsv = false)
    {
        $this->allRows = $allRows;
        $this->totalValue = $totalValue;
        $this->filterData = $filterData;
        $this->isCsv = $isCsv;
    }

    public function array(): array
    {
        $exportRows = [];

        if (!$this->isCsv) {
            $exportRows[] = ['Laporan Nilai Persediaan Barang'];
            $exportRows[] = ['Tanggal Awal', $this->filterData->tanggalAwal->format('d M Y')];
            $exportRows[] = ['Tanggal Akhir', $this->filterData->tanggalAkhir->format('d M Y')];
            $exportRows[] = [];
            $exportRows[] = $this->headings();
        }

        foreach ($this->allRows as $group) {
            // Group header
            $exportRows[] = [
                $group['product_code'],
                $group['product_name'],
                '', '', '', '', '', '', '', '', '', ''
            ];

            // Opening row
            $exportRows[] = [
                '',
                '',
                $group['opening_row']['date'],
                $group['opening_row']['type_label'],
                $group['opening_row']['reference'],
                $group['opening_row']['description'],
                $group['opening_row']['mutation'],
                $group['opening_row']['running_stock'],
                $group['opening_row']['unit'],
                $group['opening_row']['running_avg'],
                $group['opening_row']['unit_price'],
                $group['opening_row']['running_value'],
            ];

            // Ledger rows
            foreach ($group['ledger_rows'] as $row) {
                $exportRows[] = [
                    '',
                    '',
                    $row['date'],
                    $row['type_label'],
                    $row['reference'],
                    $row['description'],
                    $row['mutation'],
                    $row['running_stock'],
                    $row['unit'],
                    $row['running_avg'],
                    $row['unit_price'],
                    $row['running_value'],
                ];
            }

            // Subtotal
            $exportRows[] = [
                '', '', '', '', '', 'Total stok di gudang:', 
                '', 
                $group['subtotal']['stock'],
                $group['subtotal']['unit'],
                'Subtotal nilai:',
                '',
                $group['subtotal']['value'],
            ];

            if (!$this->isCsv) {
                $exportRows[] = []; // Empty row separator between products
            }
        }

        // Grand Total
        $exportRows[] = [
            '', '', '', '', '', '', '', '', '', 'Total Nilai Persediaan:', '', $this->totalValue
        ];

        return $exportRows;
    }

    public function headings(): array
    {
        if ($this->isCsv) {
            return [
                'Kode Barang',
                'Barang',
                'Tanggal',
                'Tipe Transaksi',
                'No. Transaksi',
                'Deskripsi',
                'Mutasi',
                'Stok di Tangan',
                'Unit',
                'Harga Rata-Rata',
                'Harga Beli/Jual',
                'Nilai',
            ];
        }

        return [
            'Kode Barang',
            'Barang',
            'Tanggal',
            'Tipe Transaksi',
            'No. Transaksi',
            'Deskripsi',
            'Mutasi',
            'Stok di Tangan',
            'Unit',
            'Harga Rata-Rata',
            'Harga Beli/Jual',
            'Nilai',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        if ($this->isCsv) {
            return [];
        }

        $sheet->mergeCells('A1:L1');
        
        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            5 => ['font' => ['bold' => true]],
        ];

        return $styles;
    }
}
