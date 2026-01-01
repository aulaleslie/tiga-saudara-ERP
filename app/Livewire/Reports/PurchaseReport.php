<?php

namespace App\Livewire\Reports;

use App\Exports\PurchaseReportExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Spatie\Tags\Tag;

class PurchaseReport extends Component
{
    use WithPagination;

    public $startDate, $endDate, $supplierId, $withTax, $selectedTag;
    public $status, $paymentStatus;
    public $filterTriggered = false;

    protected $paginationTheme = 'bootstrap';

    public function mount()
    {
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
    }

    public function applyFilters()
    {
        $this->filterTriggered = true;
        $this->resetPage();
    }

    public function exportExcel()
    {
        $filters = $this->exportFilters();
        return Excel::download(new PurchaseReportExport($filters), 'laporan-pembelian.xlsx');
    }

    public function exportCsv()
    {
        $filters = $this->exportFilters();
        return Excel::download(new PurchaseReportExport($filters), 'laporan-pembelian.csv', \Maatwebsite\Excel\Excel::CSV);
    }

    public function exportPdf()
    {
        $filters = $this->exportFilters();
        $purchases = (new PurchaseReportExport($filters))->collection();
        $pdf = Pdf::loadView('exports.purchase-pdf', ['purchases' => $purchases]);
        return response()->streamDownload(fn () => print($pdf->stream()), 'laporan-pembelian.pdf');
    }

    private function exportFilters(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'supplierId' => $this->supplierId,
            'withTax' => $this->withTax,
            'selectedTag' => $this->selectedTag,
            'status' => $this->status,
            'paymentStatus' => $this->paymentStatus,
        ];
    }

    public function render()
    {
        $settingId = session('setting_id');

        $query = Purchase::with('supplier')
            ->where('setting_id', $settingId)
            ->when($this->filterTriggered, function ($q) {
                $q->when($this->startDate, fn($q) => $q->where('date', '>=', $this->startDate))
                    ->when($this->endDate, fn($q) => $q->where('date', '<=', $this->endDate))
                    ->when($this->supplierId, fn($q) => $q->where('supplier_id', $this->supplierId))
                    ->when($this->withTax !== null && $this->withTax !== '', fn($q) => $q->where('is_tax_included', $this->withTax))
                    ->when($this->selectedTag, fn($q) => $q->whereHas('tags', fn($tq) => $tq->where('tags.id', $this->selectedTag)))
                    ->when($this->status, fn($q) => $q->where('status', $this->status))
                    ->when($this->paymentStatus, fn($q) => $q->where('payment_status', $this->paymentStatus));
            });

        return view('livewire.reports.purchase-report', [
            'purchases' => $this->filterTriggered ? $query->paginate(15) : collect(),
            'suppliers' => Supplier::all(),
            'tags' => Tag::all(),
            'statuses' => [
                Purchase::STATUS_DRAFTED => 'Draft',
                Purchase::STATUS_WAITING_APPROVAL => 'Menunggu Persetujuan',
                Purchase::STATUS_APPROVED => 'Disetujui',
                Purchase::STATUS_REJECTED => 'Ditolak',
                Purchase::STATUS_RECEIVED_PARTIALLY => 'Diterima Sebagian',
                Purchase::STATUS_RECEIVED => 'Diterima',
            ],
        ]);
    }
}
