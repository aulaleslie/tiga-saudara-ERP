<?php

namespace App\Exports;

use App\Services\Reports\SaleReportFilterData;
use App\Services\Reports\SaleReportQueryService;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;

class SaleReportExport implements FromQuery, WithHeadings, WithMapping, WithEvents
{
    public function __construct(
        protected Builder $query,
        protected SaleReportFilterData $filterData,
        protected bool $isCsv = false
    ) {}

    public function query()
    {
        return $this->query;
    }

    public function headings(): array
    {
        return SaleReportQueryService::headingsFor($this->filterData->reportMode);
    }

    public function map($row): array
    {
        return array_values(SaleReportQueryService::mapRow($row, $this->filterData->reportMode));
    }

    public function registerEvents(): array
    {
        if ($this->isCsv) {
            return [];
        }

        $startDate = $this->filterData->startDate;
        $endDate = $this->filterData->endDate;
        $columnCount = count($this->headings());
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnCount);

        return [
            AfterSheet::class => function (AfterSheet $event) use ($startDate, $endDate, $lastColumn) {
                $sheet = $event->sheet->getDelegate();

                // Insert header rows at the top
                $sheet->insertNewRowBefore(1, 2);

                // Title row
                $sheet->setCellValue('A1', 'LAPORAN PENJUALAN');
                $sheet->mergeCells("A1:{$lastColumn}1");
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

                // Period row
                $periodText = sprintf(
                    'Periode: %s s/d %s',
                    $startDate ? date('d/m/Y', strtotime($startDate)) : '-',
                    $endDate ? date('d/m/Y', strtotime($endDate)) : '-'
                );
                $sheet->setCellValue('A2', $periodText);
                $sheet->mergeCells("A2:{$lastColumn}2");
                $sheet->getStyle('A2')->getAlignment()->setHorizontal('center');

                // Style header row (now row 3)
                $sheet->getStyle('3:3')->getFont()->setBold(true);
            },
        ];
    }
}
