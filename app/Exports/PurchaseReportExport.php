<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Purchase\Entities\Purchase;

class PurchaseReportExport implements FromCollection, WithHeadings, WithEvents
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
        $supplierId = $this->filters['supplierId'] ?? null;
        $withTax = $this->filters['withTax'] ?? null;
        $selectedTag = $this->filters['selectedTag'] ?? null;
        $status = $this->filters['status'] ?? null;
        $paymentStatus = $this->filters['paymentStatus'] ?? null;

        return Purchase::with('supplier')
            ->where('setting_id', $settingId)
            ->when($startDate, fn($q) => $q->where('date', '>=', $startDate))
            ->when($endDate, fn($q) => $q->where('date', '<=', $endDate))
            ->when($supplierId, fn($q) => $q->where('supplier_id', $supplierId))
            ->when($withTax !== null && $withTax !== '', fn($q) => $q->where('is_tax_included', $withTax))
            ->when($selectedTag, fn($q) => $q->whereHas('tags', fn($tq) => $tq->where('tags.id', $selectedTag)))
            ->when($status, fn($q) => $q->where('status', $status))
            ->when($paymentStatus, fn($q) => $q->where('payment_status', $paymentStatus))
            ->get()
            ->map(fn($p) => [
                'Tanggal' => date('d/m/Y', strtotime($p->date)),
                'No. Referensi' => $p->reference,
                'Pemasok' => $p->supplier->supplier_name ?? '-',
                'Status' => $this->translateStatus($p->status),
                'Status Pembayaran' => $this->translatePaymentStatus($p->payment_status),
                'Total' => $p->total_amount,
                'Pajak' => $p->tax_amount,
                'Termasuk Pajak' => $p->is_tax_included ? 'Ya' : 'Tidak',
                'Sisa Tagihan' => $p->due_amount,
            ]);
    }

    private function translateStatus($status): string
    {
        return match ($status) {
            'DRAFTED' => 'Draft',
            'WAITING_APPROVAL' => 'Menunggu Persetujuan',
            'APPROVED' => 'Disetujui',
            'REJECTED' => 'Ditolak',
            'RECEIVED PARTIALLY' => 'Diterima Sebagian',
            'RECEIVED' => 'Diterima',
            'RETURNED' => 'Diretur',
            'RETURNED PARTIALLY' => 'Diretur Sebagian',
            default => $status ?? '-',
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
        $startDate = $this->filters['startDate'] ?? '-';
        $endDate = $this->filters['endDate'] ?? '-';

        return [
            AfterSheet::class => function (AfterSheet $event) use ($startDate, $endDate) {
                $sheet = $event->sheet->getDelegate();

                // Insert header rows at the top
                $sheet->insertNewRowBefore(1, 2);

                // Title row
                $sheet->setCellValue('A1', 'LAPORAN PEMBELIAN');
                $sheet->mergeCells('A1:I1');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

                // Period row
                $periodText = sprintf(
                    'Periode: %s s/d %s',
                    $startDate ? date('d/m/Y', strtotime($startDate)) : '-',
                    $endDate ? date('d/m/Y', strtotime($endDate)) : '-'
                );
                $sheet->setCellValue('A2', $periodText);
                $sheet->mergeCells('A2:I2');
                $sheet->getStyle('A2')->getAlignment()->setHorizontal('center');

                // Style header row (now row 3)
                $sheet->getStyle('A3:I3')->getFont()->setBold(true);
            },
        ];
    }
}
