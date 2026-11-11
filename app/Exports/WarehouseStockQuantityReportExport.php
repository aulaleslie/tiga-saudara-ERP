<?php

namespace App\Exports;

use App\Exports\Concerns\NormalizesSettingIds;
use App\Services\Reports\WarehouseStockQuantityReportFilterData;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Carbon\Carbon;
use Modules\Setting\Entities\Setting;

class WarehouseStockQuantityReportExport implements FromCollection, WithHeadings, WithMapping, WithEvents, WithTitle
{
    use NormalizesSettingIds;

    private Collection $data;
    private WarehouseStockQuantityReportFilterData $filterData;
    private array $availableWarehouses;
    private bool $isCsv;
    private array $warehouseColumns;
    private array $settingIds;
    private Collection $settings;

    public function __construct(Collection $data, WarehouseStockQuantityReportFilterData $filterData, array $availableWarehouses, bool $isCsv = false, array $settingIds = [])
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
        return 'Warehouse Stock Quantity';
    }

    public function collection()
    {
        return $this->data;
    }

    public function headings(): array
    {
        if (count($this->settingIds) <= 1) {
            $headings = [
                'Product Code',
                'Product Name',
            ];

            foreach ($this->warehouseColumns as $warehouse) {
                $headings[] = $warehouse['raw_name'] ?? $warehouse['name'];
            }

            $headings[] = 'Total Quantity';
            $headings[] = 'Product Unit';

            return $headings;
        }

        $headings = [
            'Product Code',
            'Product Name',
        ];

        foreach ($this->settings as $setting) {
            $bWarehouses = array_filter($this->warehouseColumns, fn($w) => ($w['setting_id'] ?? null) == $setting->id);
            foreach ($bWarehouses as $warehouse) {
                $rawName = $warehouse['raw_name'] ?? $warehouse['name'];
                $headings[] = "{$setting->company_name} — {$rawName}";
            }
            $headings[] = "{$setting->company_name} — Total";
        }

        $headings[] = 'Total Quantity';
        $headings[] = 'Product Unit';

        return $headings;
    }

    public function map($row): array
    {
        if (count($this->settingIds) <= 1) {
            $mapped = [
                $row->product_code ?: '',
                $row->product_name,
            ];

            foreach ($this->warehouseColumns as $warehouse) {
                $qty = $row->{"qty_loc_{$warehouse['id']}"} ?? 0;
                $mapped[] = (float) $qty;
            }

            $mapped[] = (float) $row->total_quantity;
            $mapped[] = $row->product_unit;

            return $mapped;
        }

        $mapped = [
            $row->product_code ?: '',
            $row->product_name,
        ];

        foreach ($this->settings as $setting) {
            $bWarehouses = array_filter($this->warehouseColumns, fn($w) => ($w['setting_id'] ?? null) == $setting->id);
            foreach ($bWarehouses as $warehouse) {
                $qty = $row->{"qty_loc_{$warehouse['id']}"} ?? 0;
                $mapped[] = (float) $qty;
            }
            $bTotal = $row->{"qty_business_{$setting->id}"} ?? 0;
            $mapped[] = (float) $bTotal;
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

        $isMulti = count($this->settingIds) > 1;
        $settingId = $this->settingIds[0] ?? null;
        $companyName = $isMulti
            ? 'Konsolidasi'
            : ($settingId ? ($this->settings->firstWhere('id', $settingId)?->company_name ?? Setting::find($settingId)?->company_name) : 'Tiga Saudara ERP');

        return [
            AfterSheet::class => function (AfterSheet $event) use ($asOfDate, $companyName) {
                $sheet = $event->sheet->getDelegate();
                $sheet->insertNewRowBefore(1, 4);

                $sheet->setCellValue('A1', $companyName ?: 'Tiga Saudara ERP');
                $sheet->setCellValue('A2', 'Kuantitas Stok Gudang');
                $sheet->setCellValue('A3', 'As Of Date: ' . $asOfDate);

                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
                $sheet->getStyle('5:5')->getFont()->setBold(true);
            },
        ];
    }
}
