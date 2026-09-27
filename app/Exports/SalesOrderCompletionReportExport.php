<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use App\Services\Reports\SalesOrderCompletionReportFilterData;
use App\Services\Reports\SalesOrderCompletionReportQueryService;
use Modules\Setting\Entities\Setting;

class SalesOrderCompletionReportExport implements FromCollection, WithHeadings, WithMapping, WithEvents, WithColumnFormatting
{
    public function __construct(
        private Builder $query,
        private SalesOrderCompletionReportFilterData $filter,
        private bool $isCsv = false
    ) {}

    public function collection()
    {
        $data = collect($this->query->get());
        $rows = collect();
        
        $grandTotalPesanan = 0;
        $grandTotalPengiriman = 0;
        $grandTotalFaktur = 0;
        $grandTotalPembayaran = 0;

        foreach ($data as $sale) {
            $mapped = SalesOrderCompletionReportQueryService::mapRow($sale);
            $item = new \stdClass();
            $item->is_total_row = false;
            $item->data = $mapped;
            $rows->push($item);

            $grandTotalPesanan += $mapped['Jumlah Pesanan'];
            $grandTotalPengiriman += $mapped['Jumlah Pengiriman'];
            $grandTotalFaktur += $mapped['Jumlah Faktur'];
            $grandTotalPembayaran += $mapped['Jumlah Pembayaran'];
        }

        if (!$this->isCsv && $rows->count() > 0) {
            $totalRow = new \stdClass();
            $totalRow->is_total_row = true;
            $totalRow->data = [
                'Tanggal Pemesanan' => 'Total',
                'No. Pemesanan' => '',
                'Jumlah Pesanan' => $grandTotalPesanan,
                'Status Pesanan' => '',
                'Jumlah Pengiriman' => $grandTotalPengiriman,
                'Jumlah Faktur' => $grandTotalFaktur,
                'Jumlah Pembayaran' => $grandTotalPembayaran,
            ];
            $rows->push($totalRow);
        }

        return $rows;
    }

    public function headings(): array
    {
        return SalesOrderCompletionReportQueryService::headings();
    }

    public function map($row): array
    {
        if ($this->isCsv) {
            return [
                $row->data['Tanggal Pemesanan'],
                $row->data['No. Pemesanan'],
                number_format((float) $row->data['Jumlah Pesanan'], 2, '.', ''),
                $row->data['Status Pesanan'],
                number_format((float) $row->data['Jumlah Pengiriman'], 2, '.', ''),
                number_format((float) $row->data['Jumlah Faktur'], 2, '.', ''),
                number_format((float) $row->data['Jumlah Pembayaran'], 2, '.', ''),
            ];
        }

        return [
            $row->data['Tanggal Pemesanan'],
            $row->data['No. Pemesanan'],
            (float) $row->data['Jumlah Pesanan'],
            $row->data['Status Pesanan'],
            (float) $row->data['Jumlah Pengiriman'],
            (float) $row->data['Jumlah Faktur'],
            (float) $row->data['Jumlah Pembayaran'],
        ];
    }

    public function columnFormats(): array
    {
        if ($this->isCsv) {
            return [];
        }

        return [
            'C' => '#,##0', // Jumlah Pesanan
            'E' => '#,##0', // Jumlah Pengiriman
            'F' => '#,##0', // Jumlah Faktur
            'G' => '#,##0', // Jumlah Pembayaran
        ];
    }

    public function registerEvents(): array
    {
        if ($this->isCsv) {
            return [];
        }

        $startDate = $this->filter->startDate;
        $endDate = $this->filter->endDate;
        $settingId = $this->filter->scopeSettingId ?: session('setting_id');
        $setting = $settingId ? Setting::find($settingId) : null;
        $companyName = $setting ? $setting->company_name : 'Perusahaan';

        return [
            AfterSheet::class => function (AfterSheet $event) use ($startDate, $endDate, $companyName) {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = 'G';

                $sheet->insertNewRowBefore(1, 4);

                // Company Name
                $sheet->setCellValue('A1', $companyName);
                $sheet->mergeCells("A1:{$lastColumn}1");
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

                // Title
                $sheet->setCellValue('A2', 'sales_order_completion');
                $sheet->mergeCells("A2:{$lastColumn}2");
                $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);

                // Date Range
                $periodText = sprintf(
                    '%s - %s',
                    $startDate ? date('d/m/Y', strtotime($startDate)) : '-',
                    $endDate ? date('d/m/Y', strtotime($endDate)) : '-'
                );
                $sheet->setCellValue('A3', $periodText);
                $sheet->mergeCells("A3:{$lastColumn}3");

                // Currency Label
                $sheet->setCellValue('A4', '(dalam IDR)');
                $sheet->mergeCells("A4:{$lastColumn}4");
                $sheet->getStyle('A4')->getFont()->setItalic(true);

                // Style header row (now row 5)
                $sheet->getStyle('5:5')->getFont()->setBold(true);
                
                // Style the total row at the bottom
                $highestRow = $sheet->getHighestRow();
                $sheet->getStyle("{$highestRow}:{$highestRow}")->getFont()->setBold(true);
            },
        ];
    }
}
