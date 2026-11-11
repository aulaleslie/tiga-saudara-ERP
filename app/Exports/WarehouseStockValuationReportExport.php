<?php

namespace App\Exports;

use App\Exports\Concerns\NormalizesSettingIds;
use App\Services\Reports\WarehouseStockValuationReportFilterData;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Carbon\Carbon;
use Modules\Setting\Entities\Setting;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class WarehouseStockValuationReportExport implements FromCollection, WithHeadings, WithMapping, WithEvents, WithTitle
{
    use NormalizesSettingIds;

    private Collection $data;
    private WarehouseStockValuationReportFilterData $filterData;
    private array $availableWarehouses;
    private bool $isCsv;
    private array $warehouseColumns;
    private array $settingIds;
    private Collection $settings;

    public function __construct(Collection $data, WarehouseStockValuationReportFilterData $filterData, array $availableWarehouses, bool $isCsv = false, array $settingIds = [])
    {
        $this->data = $data;
        $this->filterData = $filterData;
        $this->availableWarehouses = $availableWarehouses;
        $this->isCsv = $isCsv;

        $this->settingIds = $this->normalizeSettingIds(
            $settingIds,
            $filterData->selectedSettingIds ?? null,
            $filterData->scopeSettingId ?? null
        );
        $this->settings = Setting::query()->whereIn('id', $this->settingIds)->orderBy('company_name')->get();

        $selectedIds = empty($filterData->warehouseIds) ? array_column($availableWarehouses, 'id') : $filterData->warehouseIds;
        $filtered = array_filter($availableWarehouses, function($w) use ($selectedIds) {
            return in_array($w['id'], $selectedIds);
        });

        usort($filtered, function($a, $b) use ($selectedIds) {
            return array_search($a['id'], $selectedIds) <=> array_search($b['id'], $selectedIds);
        });

        $this->warehouseColumns = $filtered;
    }

    public function title(): string
    {
        return 'Nilai Stok Gudang';
    }

    public function collection()
    {
        if (count($this->settingIds) > 1) {
            return $this->data;
        }

        if ($this->isCsv) {
            $rows = [];
            foreach ($this->data as $product) {
                $rows[] = [
                    'gudang' => $product->warehouse_name,
                    'kode_produk' => $product->product_code ?: '',
                    'nama_produk' => $product->product_name,
                    'qty' => (float)$product->qty,
                    'min_qty' => (float)$product->minimum_qty,
                    'satuan_produk' => $product->product_unit,
                    'harga_rata_rata' => (float)$product->average_cost,
                    'nilai_persediaan' => (float)$product->stock_value,
                ];
            }
            return collect($rows);
        }

        $rows = [];
        $grandTotal = 0;
        $currentWarehouse = null;

        foreach ($this->data as $product) {
            if ($currentWarehouse !== $product->warehouse_id) {
                $rows[] = [
                    'type' => 'group',
                    'gudang' => $product->warehouse_name,
                    'kode_produk' => $product->warehouse_name,
                    'nama_produk' => '',
                    'qty' => '',
                    'min_qty' => '',
                    'satuan_produk' => '',
                    'harga_rata_rata' => '',
                    'nilai_persediaan' => '',
                ];
                $currentWarehouse = $product->warehouse_id;
            }

            $rows[] = [
                'type' => 'product',
                'gudang' => $product->warehouse_name,
                'kode_produk' => $product->product_code ?: '',
                'nama_produk' => $product->product_name,
                'qty' => (float)$product->qty,
                'min_qty' => (float)$product->minimum_qty,
                'satuan_produk' => $product->product_unit,
                'harga_rata_rata' => (float)$product->average_cost,
                'nilai_persediaan' => (float)$product->stock_value,
            ];

            $grandTotal += (float)$product->stock_value;
        }

        $rows[] = [
            'type' => 'total',
            'gudang' => '',
            'kode_produk' => 'Total Nilai Persediaan Seluruh Produk',
            'nama_produk' => '',
            'qty' => '',
            'min_qty' => '',
            'satuan_produk' => '',
            'harga_rata_rata' => '',
            'nilai_persediaan' => $grandTotal,
        ];

        return collect($rows);
    }

    public function headings(): array
    {
        if (count($this->settingIds) <= 1) {
            if ($this->isCsv) {
                return [
                    'Gudang',
                    'Kode Produk',
                    'Nama Produk',
                    'Qty',
                    'Min. Qty',
                    'Satuan Produk',
                    'Harga Rata-rata',
                    'Nilai Persediaan',
                ];
            }

            return [
                'Gudang / Kode Produk',
                'Nama Produk',
                'Qty',
                'Min. Qty',
                'Satuan Produk',
                'Harga Rata-rata',
                'Nilai Persediaan',
            ];
        }

        $headings = [
            'Kode produk / SKU',
            'Nama produk',
            'Satuan Produk',
            'Total — Stok',
            'Total — Nilai',
        ];

        foreach ($this->settings as $setting) {
            $bWarehouses = array_filter($this->warehouseColumns, fn($w) => ($w['setting_id'] ?? null) == $setting->id);
            foreach ($bWarehouses as $w) {
                $rawName = $w['raw_name'] ?? $w['name'];
                $headings[] = "{$setting->company_name} — {$rawName} Stok";
            }
            $headings[] = "{$setting->company_name} — Total Stok";
            $headings[] = "{$setting->company_name} — Biaya Rata-rata";
            $headings[] = "{$setting->company_name} — Nilai";
        }

        return $headings;
    }

    public function map($row): array
    {
        if (count($this->settingIds) <= 1) {
            if ($this->isCsv) {
                return [
                    $row['gudang'],
                    $row['kode_produk'],
                    $row['nama_produk'],
                    $row['qty'],
                    $row['min_qty'],
                    $row['satuan_produk'],
                    $row['harga_rata_rata'],
                    $row['nilai_persediaan'],
                ];
            }

            if (($row['type'] ?? '') === 'group') {
                return [
                    $row['kode_produk'],
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                ];
            }

            if (($row['type'] ?? '') === 'total') {
                return [
                    $row['kode_produk'],
                    '',
                    '',
                    '',
                    '',
                    '',
                    $row['nilai_persediaan'],
                ];
            }

            return [
                $row['kode_produk'],
                $row['nama_produk'],
                $row['qty'],
                $row['min_qty'],
                $row['satuan_produk'],
                $row['harga_rata_rata'],
                $row['nilai_persediaan'],
            ];
        }

        $rowMap = [
            $row->product_code ?: '',
            $row->product_name,
            $row->product_unit,
            (float) ($row->total_quantity ?? $row->qty ?? 0),
            (float) ($row->total_value ?? $row->stock_value ?? 0),
        ];

        foreach ($this->settings as $setting) {
            $bWarehouses = array_filter($this->warehouseColumns, fn($w) => ($w['setting_id'] ?? null) == $setting->id);
            foreach ($bWarehouses as $w) {
                $qty = $row->{"qty_loc_{$w['id']}"} ?? 0;
                $rowMap[] = (float) $qty;
            }
            $rowMap[] = (float) ($row->{"qty_business_{$setting->id}"} ?? 0);
            $rowMap[] = (float) ($row->{"avg_business_{$setting->id}"} ?? 0);
            $rowMap[] = (float) ($row->{"val_business_{$setting->id}"} ?? 0);
        }

        return $rowMap;
    }

    public function registerEvents(): array
    {
        if ($this->isCsv) {
            return [];
        }

        $asOfDateStr = $this->filterData->asOfDate;
        $asOfDate = $asOfDateStr ? Carbon::parse($asOfDateStr)->format('d/m/Y') : '-';

        $isMulti = count($this->settingIds) > 1;
        $settingId = $this->settingIds[0] ?? null;
        $companyName = $isMulti
            ? 'Konsolidasi'
            : ($settingId ? ($this->settings->firstWhere('id', $settingId)?->company_name ?? Setting::find($settingId)?->company_name) : 'Tiga Saudara ERP');

        $headings = $this->headings();
        $columnCount = count($headings);
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);

        if (! $isMulti) {
            $rowCount = count($this->collection()) + 4; // +3 for meta, +1 for headings
            return [
                AfterSheet::class => function (AfterSheet $event) use ($asOfDate, $companyName, $rowCount) {
                    $sheet = $event->sheet->getDelegate();

                    $sheet->insertNewRowBefore(1, 3);

                    $sheet->setCellValue('A1', $companyName ?: 'Tiga Saudara ERP');
                    $sheet->setCellValue('A2', 'Nilai stok gudang (dalam IDR)');
                    $sheet->setCellValue('A3', $asOfDate);

                    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                    $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
                    $sheet->getStyle('4:4')->getFont()->setBold(true);

                    // Format number columns
                    $sheet->getStyle("C5:C{$rowCount}")->getNumberFormat()->setFormatCode('#,##0.00');
                    $sheet->getStyle("D5:D{$rowCount}")->getNumberFormat()->setFormatCode('#,##0.00');
                    $sheet->getStyle("F5:G{$rowCount}")->getNumberFormat()->setFormatCode('#,##0.00');

                    // Apply grouping styling
                    $data = $this->collection()->toArray();
                    $rowIdx = 5;
                    foreach ($data as $row) {
                        if ($row['type'] === 'group') {
                            $sheet->mergeCells("A{$rowIdx}:G{$rowIdx}");
                            $sheet->getStyle("A{$rowIdx}")->getFont()->setBold(true);
                            $sheet->getStyle("A{$rowIdx}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                                ->getStartColor()->setARGB('FFF0F0F0');
                        } elseif ($row['type'] === 'total') {
                            $sheet->mergeCells("A{$rowIdx}:F{$rowIdx}");
                            $sheet->getStyle("A{$rowIdx}:G{$rowIdx}")->getFont()->setBold(true);
                            $sheet->getStyle("A{$rowIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                        }
                        $rowIdx++;
                    }
                },
            ];
        }

        return [
            AfterSheet::class => function (AfterSheet $event) use ($asOfDate, $companyName, $lastColumn) {
                $sheet = $event->sheet->getDelegate();
                $sheet->insertNewRowBefore(1, 4);

                $sheet->setCellValue('A1', $companyName ?: 'Tiga Saudara ERP');
                $sheet->setCellValue('A2', 'Nilai Stok Gudang');
                $sheet->setCellValue('A3', 'As Of Date: ' . $asOfDate);

                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
                $sheet->getStyle('5:5')->getFont()->setBold(true);

                $highestRow = $sheet->getHighestRow();
                $totalRow = $highestRow + 1;

                $grandTotal = $this->data->sum('stock_value');
                $sheet->setCellValue("C{$totalRow}", 'Total:');
                $sheet->setCellValue("E{$totalRow}", (float) $grandTotal);

                $colIndex = 5;
                foreach ($this->settings as $setting) {
                    $bWarehouses = array_filter($this->warehouseColumns, fn($w) => ($w['setting_id'] ?? null) == $setting->id);
                    $colIndex += count($bWarehouses) + 2;
                    $colIndex += 1;
                    $valColLetter = Coordinate::stringFromColumnIndex($colIndex);
                    $bTotalValue = (float) $this->data->sum("val_business_{$setting->id}");
                    $sheet->setCellValue("{$valColLetter}{$totalRow}", $bTotalValue);
                }
                $sheet->getStyle("A{$totalRow}:{$lastColumn}{$totalRow}")->getFont()->setBold(true);
            },
        ];
    }
}
