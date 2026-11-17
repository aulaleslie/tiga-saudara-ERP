<?php

namespace App\Exports;

use App\Services\Reports\SoldSerialNumberReportFilterData;
use App\Services\Reports\SoldSerialNumberReportQueryService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class SoldSerialNumberReportExport extends DefaultValueBinder implements
    FromCollection,
    WithHeadings,
    WithMapping,
    WithColumnFormatting,
    WithCustomValueBinder,
    ShouldAutoSize
{
    public function __construct(
        protected SoldSerialNumberReportFilterData $filter,
        protected ?SoldSerialNumberReportQueryService $queryService = null,
    ) {
        $this->queryService ??= new SoldSerialNumberReportQueryService();
    }

    public function collection(): Collection
    {
        return $this->queryService->project($this->filter);
    }

    public function headings(): array
    {
        return [
            'Product name',
            'SN',
            'Tgl',
            'Bulan',
            'Tahun',
        ];
    }

    public function map($row): array
    {
        return [
            (string) $row['product_name'],
            (string) $row['sn'],
            (int) $row['day'],
            (int) $row['month'],
            (int) $row['year'],
        ];
    }

    public function columnFormats(): array
    {
        return [
            'B' => NumberFormat::FORMAT_TEXT,
        ];
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if ($cell->getColumn() === 'B' && $cell->getRow() > 1) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
