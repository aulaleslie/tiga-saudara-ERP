<?php

namespace App\Exports;

use App\Exports\Concerns\NormalizesSettingIds;
use App\Services\Reports\InventoryValuationReportFilterData;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Modules\Setting\Entities\Setting;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InventoryValuationReportExport implements FromArray, WithHeadings, WithStyles
{
    use NormalizesSettingIds;

    private Collection $allRows;
    private float $totalValue;
    private InventoryValuationReportFilterData $filterData;
    private bool $isCsv;
    private array $settingIds;

    public function __construct(
        Collection $allRows,
        float $totalValue,
        InventoryValuationReportFilterData $filterData,
        bool $isCsv = false,
        array $settingIds = []
    ) {
        $this->allRows = $allRows;
        $this->totalValue = $totalValue;
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

        if (!$this->isCsv) {
            $exportRows[] = ['Laporan Nilai Persediaan Barang'];
            $exportRows[] = ['Tanggal Awal', $this->filterData->tanggalAwal->format('d M Y')];
            $exportRows[] = ['Tanggal Akhir', $this->filterData->tanggalAkhir->format('d M Y')];
            $exportRows[] = [];
            $exportRows[] = $this->headings();
        }

        $isMultiBusiness = count($this->settingIds) > 1;

        if (!$isMultiBusiness) {
            // Preserved single-business layout
            foreach ($this->allRows as $group) {
                // Group header
                $exportRows[] = [
                    $group['product_code'],
                    $group['product_name'],
                    '', '', '', '', '', '', '', '', '', ''
                ];

                // Opening row
                if (!empty($group['opening_row'])) {
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
                }

                // Ledger rows
                if (!empty($group['ledger_rows'])) {
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
                }

                // Subtotal
                if (!empty($group['subtotal'])) {
                    $exportRows[] = [
                        '', '', '', '', '', 'Total stok di gudang:', 
                        '', 
                        $group['subtotal']['stock'],
                        $group['subtotal']['unit'],
                        'Subtotal nilai:',
                        '',
                        $group['subtotal']['value'],
                    ];
                }

                if (!$this->isCsv) {
                    $exportRows[] = []; // Empty row separator between products
                }
            }
        } else {
            // Multi-business layout: grouped by business with separate ledgers
            $settingsMap = Setting::whereIn('id', $this->settingIds)->pluck('company_name', 'id')->toArray();

            foreach ($this->allRows as $group) {
                // Product header
                $exportRows[] = [
                    $group['product_code'],
                    $group['product_name'],
                    '', '', '', '', '', '', '', '', '', ''
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
                        '',
                        'Bisnis: ' . $sName,
                        '', '', '', '', '', '', '', '', '', ''
                    ];

                    // Opening row
                    if (!empty($detail['opening_row'])) {
                        $exportRows[] = [
                            '',
                            '',
                            $detail['opening_row']['date'],
                            $detail['opening_row']['type_label'],
                            $detail['opening_row']['reference'],
                            $detail['opening_row']['description'],
                            $detail['opening_row']['mutation'],
                            $detail['opening_row']['running_stock'],
                            $detail['opening_row']['unit'],
                            $detail['opening_row']['running_avg'],
                            $detail['opening_row']['unit_price'],
                            $detail['opening_row']['running_value'],
                        ];
                    }

                    // Ledger rows
                    if (!empty($detail['ledger_rows'])) {
                        foreach ($detail['ledger_rows'] as $row) {
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
                    }

                    // Business Subtotal
                    $exportRows[] = [
                        '', '', '', '', '', 'Total stok (' . $sName . '):', 
                        '', 
                        $detail['subtotal']['stock'] ?? $detail['ending_stock'],
                        $detail['subtotal']['unit'] ?? $group['product_unit'],
                        'Subtotal nilai (' . $sName . '):',
                        '',
                        $detail['subtotal']['value'] ?? $detail['ending_value'],
                    ];
                }

                // Product Total row
                $exportRows[] = [
                    '', '', '', '', '', 'Total stok (Semua Bisnis):',
                    '',
                    $group['ending_stock'],
                    $group['product_unit'],
                    'Total nilai:',
                    '',
                    $group['ending_value'],
                ];

                if (!$this->isCsv) {
                    $exportRows[] = []; // Empty row separator between products
                }
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
