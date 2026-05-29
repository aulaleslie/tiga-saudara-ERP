<?php

namespace App\Exports;

use App\Services\Reports\PurchaseReportFilterData;
use App\Services\Reports\PurchaseReportQueryService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Purchase\Entities\Purchase;

class PurchaseReportExport implements FromCollection, WithHeadings, WithEvents
{
    protected PurchaseReportFilterData $filterData;

    public function __construct(array $filters)
    {
        $this->filterData = PurchaseReportFilterData::fromArray($filters);
    }

    public function collection(): Collection
    {
        $queryService = app(PurchaseReportQueryService::class);
        $purchases = $queryService->build($this->filterData)->get();

        return $purchases->map(function ($p) {
            return [
                'Tanggal' => date('d/m/Y', strtotime($p->date)),
                'No. Referensi' => $p->reference,
                'Nomor Pembelian Supplier' => $p->supplier_purchase_number ?? '-',
                'Pemasok' => $p->supplier->supplier_name ?? '-',
                'Status' => $this->translateStatus($p->status),
                'Status Pembayaran' => $this->translatePaymentStatus($p->payment_status),
                'Total' => $p->total_amount,
                'Pajak' => $p->tax_amount,
                'Termasuk Pajak' => $p->is_tax_included ? 'Ya' : 'Tidak',
                'Sisa Tagihan' => $p->due_amount,
            ];
        });
    }

    private function translateStatus($status): string
    {
        return match ($status) {
            Purchase::STATUS_DRAFTED => 'Draft',
            Purchase::STATUS_WAITING_APPROVAL => 'Menunggu Persetujuan',
            Purchase::STATUS_APPROVED => 'Disetujui',
            Purchase::STATUS_REJECTED => 'Ditolak',
            Purchase::STATUS_RECEIVED_PARTIALLY => 'Diterima Sebagian',
            Purchase::STATUS_RECEIVED => 'Diterima',
            Purchase::STATUS_RETURNED => 'Diretur',
            Purchase::STATUS_RETURNED_PARTIALLY => 'Diretur Sebagian',
            default => $status ?? '-',
        };
    }

    private function translatePaymentStatus($status): string
    {
        return match (strtoupper($status ?? '')) {
            'PAID' => 'Lunas',
            'UNPAID' => 'Belum Dibayar',
            'PARTIAL' => 'Sebagian',
            default => $status ?? '-',
        };
    }

    public function headings(): array
    {
        return [
            'Tanggal',
            'No. Referensi',
            'Nomor Pembelian Supplier',
            'Pemasok',
            'Status',
            'Status Pembayaran',
            'Total',
            'Pajak',
            'Termasuk Pajak',
            'Sisa Tagihan',
        ];
    }

    public function registerEvents(): array
    {
        $startDate = $this->filterData->startDate;
        $endDate = $this->filterData->endDate;

        return [
            AfterSheet::class => function (AfterSheet $event) use ($startDate, $endDate) {
                $sheet = $event->sheet->getDelegate();

                // Insert header rows at the top
                $sheet->insertNewRowBefore(1, 2);

                // Title row
                $sheet->setCellValue('A1', 'LAPORAN PEMBELIAN');
                $sheet->mergeCells('A1:J1');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

                // Period row
                $periodText = sprintf(
                    'Periode: %s s/d %s',
                    $startDate ? date('d/m/Y', strtotime($startDate)) : '-',
                    $endDate ? date('d/m/Y', strtotime($endDate)) : '-'
                );
                $sheet->setCellValue('A2', $periodText);
                $sheet->mergeCells('A2:J2');
                $sheet->getStyle('A2')->getAlignment()->setHorizontal('center');

                // Style header row (now row 3)
                $sheet->getStyle('A3:J3')->getFont()->setBold(true);
            },
        ];
    }
}
