<?php

namespace App\Exports;

use App\Services\Reports\InventoryDetailReportFilterData;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InventoryDetailReportExport implements FromArray, WithStyles, ShouldAutoSize
{
    private Collection $allRows;
    private InventoryDetailReportFilterData $filterData;
    private bool $isCsv;

    public function __construct(Collection $allRows, InventoryDetailReportFilterData $filterData, bool $isCsv = false)
    {
        $this->allRows = $allRows;
        $this->filterData = $filterData;
        $this->isCsv = $isCsv;
    }

    public function array(): array
    {
        $exportRows = [];

        if ($this->isCsv) {
            $exportRows[] = [
                'Kode Barang',
                'Barang',
                'Tanggal',
                'Tipe Transaksi',
                'No. Transaksi',
                'Deskripsi',
                'Mutasi',
                'Stok di Tangan',
                'Unit',
            ];
            
            foreach ($this->allRows as $group) {
                // Opening row
                $exportRows[] = [
                    $group['product_code'],
                    $group['product_name'],
                    $group['opening_row']['date'],
                    $group['opening_row']['type_label'],
                    $group['opening_row']['reference'],
                    $group['opening_row']['description'],
                    $group['opening_row']['mutation'],
                    $group['opening_row']['running_stock'],
                    $group['opening_row']['unit'],
                ];

                // Ledger rows
                foreach ($group['ledger_rows'] as $row) {
                    $exportRows[] = [
                        $group['product_code'],
                        $group['product_name'],
                        $row['date'],
                        $row['type_label'],
                        $row['reference'],
                        $row['description'],
                        $row['mutation'],
                        $row['running_stock'],
                        $row['unit'],
                    ];
                }
            }
            
            return $exportRows;
        }

        $settingId = session('setting_id');
        $companyName = $settingId ? \Modules\Setting\Entities\Setting::find($settingId)?->company_name : 'Tiga Saudara ERP';
        
        $exportRows[] = [$companyName ?: 'Tiga Saudara ERP'];
        $exportRows[] = ['Rincian Persediaan Barang'];
        
        $tanggalAwalStr = $this->filterData->tanggalAwal ? $this->filterData->tanggalAwal->format('d/m/Y') : '-';
        $tanggalAkhirStr = $this->filterData->tanggalAkhir ? $this->filterData->tanggalAkhir->format('d/m/Y') : '-';
        $exportRows[] = ["Periode: $tanggalAwalStr - $tanggalAkhirStr"];
        
        $exportRows[] = ['(dalam IDR)'];
        $exportRows[] = [];

        $exportRows[] = [
            'Tanggal',
            'Tipe Transaksi',
            'No. Transaksi',
            'Deskripsi',
            'Mutasi',
            'Stok di Tangan',
            'Unit',
        ];

        foreach ($this->allRows as $group) {
            // Group header
            $exportRows[] = [
                "({$group['product_code']}) | {$group['product_name']}",
                '', '', '', '', '', ''
            ];

            // Opening row
            $exportRows[] = [
                $group['opening_row']['date'],
                $group['opening_row']['type_label'],
                $group['opening_row']['reference'],
                $group['opening_row']['description'],
                $group['opening_row']['mutation'],
                $group['opening_row']['running_stock'],
                $group['opening_row']['unit'],
            ];

            // Ledger rows
            foreach ($group['ledger_rows'] as $row) {
                $exportRows[] = [
                    $row['date'],
                    $row['type_label'],
                    $row['reference'],
                    $row['description'],
                    $row['mutation'],
                    $row['running_stock'],
                    $row['unit'],
                ];
            }

            // Subtotal
            $exportRows[] = [
                '', '', '', '', 
                "({$group['product_code']} | {$group['product_name']}) Total Stok di Tangan", 
                $group['subtotal']['stock'],
                $group['subtotal']['unit'],
            ];

            $exportRows[] = []; // Empty row separator between products
        }

        return $exportRows;
    }

    public function styles(Worksheet $sheet)
    {
        if ($this->isCsv) {
            return [];
        }

        $sheet->mergeCells('A1:G1');
        $sheet->mergeCells('A2:G2');
        $sheet->mergeCells('A3:G3');
        $sheet->mergeCells('A4:G4');
        
        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            2 => ['font' => ['bold' => true, 'size' => 12]],
            6 => ['font' => ['bold' => true]],
        ];

        return $styles;
    }
}
