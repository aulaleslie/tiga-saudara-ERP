<?php

namespace App\Exports;

use App\Exports\Concerns\NormalizesSettingIds;
use App\Services\Reports\InventoryDetailReportFilterData;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Modules\Setting\Entities\Setting;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InventoryDetailReportExport implements FromArray, WithStyles, ShouldAutoSize
{
    use NormalizesSettingIds;

    private Collection $allRows;
    private InventoryDetailReportFilterData $filterData;
    private bool $isCsv;
    private array $settingIds;

    public function __construct(Collection $allRows, InventoryDetailReportFilterData $filterData, bool $isCsv = false, array $settingIds = [])
    {
        $this->allRows = $allRows;
        $this->filterData = $filterData;
        $this->isCsv = $isCsv;
        $dataSettingIds = !empty($allRows->first()['businesses']) ? array_keys($allRows->first()['businesses']) : null;
        $this->settingIds = $this->normalizeSettingIds(
            $settingIds,
            $filterData->selectedSettingIds ?? null,
            $filterData->settingId ?? null,
            $dataSettingIds
        );
    }

    public function array(): array
    {
        $exportRows = [];
        $isMultiBusiness = count($this->settingIds) > 1;

        if ($this->isCsv) {
            if (!$isMultiBusiness) {
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
                    if (!empty($group['opening_row'])) {
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
                    }

                    // Ledger rows
                    if (!empty($group['ledger_rows'])) {
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
                }
                
                return $exportRows;
            }

            // Multi-business CSV
            $exportRows[] = [
                'Kode Barang',
                'Barang',
                'Bisnis',
                'Tanggal',
                'Tipe Transaksi',
                'No. Transaksi',
                'Deskripsi',
                'Mutasi',
                'Stok di Tangan',
                'Unit',
            ];

            $settingsMap = Setting::whereIn('id', $this->settingIds)->pluck('company_name', 'id')->toArray();

            foreach ($this->allRows as $group) {
                foreach ($this->settingIds as $sId) {
                    $detail = $group['businesses'][$sId] ?? null;
                    if (!$detail) {
                        continue;
                    }
                    $hasActivity = ($detail['opening_stock'] != 0) || ($detail['ending_stock'] != 0) || !empty($detail['ledger_rows']);
                    if (!$hasActivity) {
                        continue;
                    }
                    $sName = $settingsMap[$sId] ?? "Bisnis #$sId";

                    if (!empty($detail['opening_row'])) {
                        $exportRows[] = [
                            $group['product_code'],
                            $group['product_name'],
                            $sName,
                            $detail['opening_row']['date'],
                            $detail['opening_row']['type_label'],
                            $detail['opening_row']['reference'],
                            $detail['opening_row']['description'],
                            $detail['opening_row']['mutation'],
                            $detail['opening_row']['running_stock'],
                            $detail['opening_row']['unit'],
                        ];
                    }

                    if (!empty($detail['ledger_rows'])) {
                        foreach ($detail['ledger_rows'] as $row) {
                            $exportRows[] = [
                                $group['product_code'],
                                $group['product_name'],
                                $sName,
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
                }
            }

            return $exportRows;
        }

        // Excel format
        if (!$isMultiBusiness) {
            $settingId = $this->settingIds[0] ?? null;
            $companyName = $settingId ? Setting::find($settingId)?->company_name : 'Tiga Saudara ERP';
            
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
                if (!empty($group['opening_row'])) {
                    $exportRows[] = [
                        $group['opening_row']['date'],
                        $group['opening_row']['type_label'],
                        $group['opening_row']['reference'],
                        $group['opening_row']['description'],
                        $group['opening_row']['mutation'],
                        $group['opening_row']['running_stock'],
                        $group['opening_row']['unit'],
                    ];
                }

                // Ledger rows
                if (!empty($group['ledger_rows'])) {
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
                }

                // Subtotal
                if (!empty($group['subtotal'])) {
                    $exportRows[] = [
                        '', '', '', '', 
                        "({$group['product_code']} | {$group['product_name']}) Total Stok di Tangan", 
                        $group['subtotal']['stock'],
                        $group['subtotal']['unit'],
                    ];
                }

                $exportRows[] = []; // Empty row separator between products
            }

            return $exportRows;
        }

        // Multi-business Excel
        $settingsMap = Setting::whereIn('id', $this->settingIds)->pluck('company_name', 'id')->toArray();

        $exportRows[] = ['Tiga Saudara ERP'];
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

            foreach ($this->settingIds as $sId) {
                $detail = $group['businesses'][$sId] ?? null;
                if (!$detail) {
                    continue;
                }
                $hasActivity = ($detail['opening_stock'] != 0) || ($detail['ending_stock'] != 0) || !empty($detail['ledger_rows']);
                if (!$hasActivity) {
                    continue;
                }
                $sName = $settingsMap[$sId] ?? "Bisnis #$sId";

                // Business header
                $exportRows[] = [
                    'Bisnis: ' . $sName,
                    '', '', '', '', '', ''
                ];

                // Opening row
                if (!empty($detail['opening_row'])) {
                    $exportRows[] = [
                        $detail['opening_row']['date'],
                        $detail['opening_row']['type_label'],
                        $detail['opening_row']['reference'],
                        $detail['opening_row']['description'],
                        $detail['opening_row']['mutation'],
                        $detail['opening_row']['running_stock'],
                        $detail['opening_row']['unit'],
                    ];
                }

                // Ledger rows
                if (!empty($detail['ledger_rows'])) {
                    foreach ($detail['ledger_rows'] as $row) {
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
                }

                // Business Subtotal
                $exportRows[] = [
                    '', '', '', '', 
                    "Total Stok di Tangan ({$sName})", 
                    $detail['subtotal']['stock'] ?? $detail['ending_stock'],
                    $detail['subtotal']['unit'] ?? $group['product_unit'],
                ];
            }

            // Product total row
            $exportRows[] = [
                '', '', '', '', 
                "({$group['product_code']} | {$group['product_name']}) Total Stok di Tangan (Semua Bisnis)", 
                $group['ending_stock'],
                $group['product_unit'],
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
