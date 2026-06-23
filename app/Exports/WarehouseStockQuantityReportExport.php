<?php

namespace App\Exports;

use App\Services\Reports\WarehouseStockQuantityReportFilterData;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Carbon\Carbon;

class WarehouseStockQuantityReportExport implements FromCollection, WithHeadings, WithMapping, WithEvents, WithTitle
{
    private Collection $data;
    private WarehouseStockQuantityReportFilterData $filterData;
    private array $availableWarehouses;
    private bool $isCsv;
    private array $warehouseColumns;

    public function __construct(Collection $data, WarehouseStockQuantityReportFilterData $filterData, array $availableWarehouses, bool $isCsv = false)
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
        return 'Warehouse Stock Quantity';
    }

    public function collection()
    {
        return $this->data;
    }

    public function headings(): array
    {
        $headings = [
            'Product Code',
            'Product Name'
        ];

        foreach ($this->warehouseColumns as $warehouse) {
            $headings[] = $warehouse['name'];
        }

        $headings[] = 'Total Quantity';
        $headings[] = 'Product Unit';

        return $headings;
    }

    public function map($row): array
    {
        $mapped = [
            $row->product_code ?: '',
            $row->product_name
        ];

        foreach ($this->warehouseColumns as $warehouse) {
            $qty = $row->{"qty_loc_{$warehouse['id']}"} ?? 0;
            $mapped[] = (float) $qty;
        }

        $mapped[] = (float) $row->total_quantity;
        $mapped[] = $row->product_unit;

        return $mapped;
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

        return [
            AfterSheet::class => function (AfterSheet $event) use ($asOfDate, $companyName) {
                $sheet = $event->sheet->getDelegate();

                $sheet->insertNewRowBefore(1, 3);

                $sheet->setCellValue('A1', $companyName ?: 'Tiga Saudara ERP');
                $sheet->setCellValue('A2', 'WAREHOUSE STOCK QUANTITY');
                $sheet->setCellValue('A3', $asOfDate);

                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
                $sheet->getStyle('4:4')->getFont()->setBold(true);
            },
        ];
    }
}
