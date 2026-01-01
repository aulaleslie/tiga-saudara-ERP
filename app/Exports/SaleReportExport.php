<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Sale\Entities\Sale;

class SaleReportExport implements FromCollection, WithHeadings, WithEvents
{
    protected array $filters;

    public function __construct(array $filters)
    {
        $this->filters = $filters;
    }

    public function collection(): Collection
    {
        $settingId = session('setting_id');
        $startDate = $this->filters['startDate'] ?? null;
        $endDate = $this->filters['endDate'] ?? null;
        $customerId = $this->filters['customerId'] ?? null;
        $saleStatus = $this->filters['saleStatus'] ?? null;
        $paymentStatus = $this->filters['paymentStatus'] ?? null;
        $selectedTag = $this->filters['selectedTag'] ?? null;

        return Sale::with('customer')
            ->where('setting_id', $settingId)
            ->when($startDate, fn($q) => $q->whereDate('date', '>=', $startDate))
            ->when($endDate, fn($q) => $q->whereDate('date', '<=', $endDate))
            ->when($customerId, fn($q) => $q->where('customer_id', $customerId))
            ->when($saleStatus, fn($q) => $q->where('status', $saleStatus))
            ->when($paymentStatus, fn($q) => $q->where('payment_status', $paymentStatus))
            ->when($selectedTag, fn($q) => $q->whereHas('tags', fn($tq) => $tq->where('tags.id', $selectedTag)))
            ->orderBy('date', 'desc')
            ->get()
            ->map(fn($s) => [
                'Tanggal' => date('d/m/Y', strtotime($s->date)),
                'No. Referensi' => $s->reference,
                'Pelanggan' => $s->customer->customer_name ?? '-',
                'Status' => $this->translateStatus($s->status),
                'Status Pembayaran' => $this->translatePaymentStatus($s->payment_status),
                'Total' => $s->total_amount,
                'Dibayar' => $s->paid_amount,
                'Sisa Tagihan' => $s->due_amount,
            ]);
    }

    private function translateStatus($status): string
    {
        return match ($status) {
            'DRAFTED' => 'Draft',
            'WAITING_APPROVAL' => 'Menunggu Persetujuan',
            'APPROVED' => 'Disetujui',
            'REJECTED' => 'Ditolak',
            'DISPATCHED PARTIALLY' => 'Dikirim Sebagian',
            'DISPATCHED' => 'Terkirim',
            'RETURNED' => 'Diretur',
            'RETURNED PARTIALLY' => 'Diretur Sebagian',
            default => $status,
        };
    }

    private function translatePaymentStatus($status): string
    {
        return match (strtolower($status ?? '')) {
            'paid' => 'Lunas',
            'unpaid' => 'Belum Dibayar',
            'partial' => 'Sebagian',
            default => $status ?? '-',
        };
    }

    public function headings(): array
    {
        return [
            'Tanggal',
            'No. Referensi',
            'Pelanggan',
            'Status',
            'Status Pembayaran',
            'Total',
            'Dibayar',
            'Sisa Tagihan',
        ];
    }

    public function registerEvents(): array
    {
        $startDate = $this->filters['startDate'] ?? '-';
        $endDate = $this->filters['endDate'] ?? '-';

        return [
            AfterSheet::class => function (AfterSheet $event) use ($startDate, $endDate) {
                $sheet = $event->sheet->getDelegate();

                // Insert header rows at the top
                $sheet->insertNewRowBefore(1, 2);

                // Title row
                $sheet->setCellValue('A1', 'LAPORAN PENJUALAN');
                $sheet->mergeCells('A1:H1');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

                // Period row
                $periodText = sprintf(
                    'Periode: %s s/d %s',
                    $startDate ? date('d/m/Y', strtotime($startDate)) : '-',
                    $endDate ? date('d/m/Y', strtotime($endDate)) : '-'
                );
                $sheet->setCellValue('A2', $periodText);
                $sheet->mergeCells('A2:H2');
                $sheet->getStyle('A2')->getAlignment()->setHorizontal('center');

                // Style header row (now row 3)
                $sheet->getStyle('A3:H3')->getFont()->setBold(true);
            },
        ];
    }
}
