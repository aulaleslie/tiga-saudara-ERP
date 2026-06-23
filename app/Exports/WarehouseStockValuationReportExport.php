<?php

namespace App\Exports;

use App\Services\Reports\WarehouseStockValuationReportFilterData;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class WarehouseStockValuationReportExport implements FromCollection, WithHeadings, WithMapping, WithEvents, WithTitle
{
    private Collection $data;
    private WarehouseStockValuationReportFilterData $filterData;
    private array $availableWarehouses;
    private bool $isCsv;
    private array $warehouseColumns;

    public function __construct(Collection $data, WarehouseStockValuationReportFilterData $filterData, array $availableWarehouses, bool $isCsv = false)
    {
        $this->data = $data;
        $this->filterData = $filterData;
        $this->availableWarehouses = $availableWarehouses;
        $this->isCsv = $isCsv;
        
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

        // Total row
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
            'Gudang / Kode Produk', // Adjusting for sample-aligned "Gudang/Kode Produk"
            'Nama Produk',
            'Qty',
            'Min. Qty',
            'Satuan Produk',
            'Harga Rata-rata',
            'Nilai Persediaan',
        ];
    }

    public function map($row): array
    {
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

    public function registerEvents(): array
    {
        if ($this->isCsv) {
            return [];
        }

        $asOfDateStr = $this->filterData->asOfDate;
        $asOfDate = $asOfDateStr ? Carbon::parse($asOfDateStr)->format('d/m/Y') : '-';
        
        $settingId = session('setting_id');
        $companyName = $settingId ? \Modules\Setting\Entities\Setting::find($settingId)?->company_name : 'Tiga Saudara ERP';

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
}
