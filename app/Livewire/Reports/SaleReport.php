<?php

namespace App\Livewire\Reports;

use App\Exports\SaleReportExport;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Sale\Entities\Sale;
use Spatie\Tags\Tag;

class SaleReport extends Component
{
    use WithPagination;

    public $customers;
    public $startDate;
    public $endDate;
    public $customerId;
    public $saleStatus;
    public $paymentStatus;
    public $selectedTag;
    public $filterTriggered = false;
    public $isGlobal = false;

    protected $paginationTheme = 'bootstrap';

    public function mount($customers, $isGlobal = false)
    {
        $this->customers = $customers;
        $this->isGlobal = $isGlobal;
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
        $this->customerId = '';
        $this->saleStatus = '';
        $this->paymentStatus = '';
        $this->selectedTag = '';
    }

    public function applyFilters()
    {
        $this->filterTriggered = true;
        $this->resetPage();
    }

    public function exportExcel()
    {
        $filters = $this->exportFilters();
        $filename = $this->isGlobal ? 'laporan-penjualan-global.xlsx' : 'laporan-penjualan.xlsx';
        return Excel::download(new SaleReportExport($filters), $filename);
    }

    public function exportCsv()
    {
        $filters = $this->exportFilters();
        $filename = $this->isGlobal ? 'laporan-penjualan-global.csv' : 'laporan-penjualan.csv';
        return Excel::download(new SaleReportExport($filters), $filename, \Maatwebsite\Excel\Excel::CSV);
    }

    private function exportFilters(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'customerId' => $this->customerId,
            'saleStatus' => $this->saleStatus,
            'paymentStatus' => $this->paymentStatus,
            'selectedTag' => $this->selectedTag,
            'isGlobal' => $this->isGlobal,
        ];
    }

    public function render()
    {
        $query = Sale::with(['customer', 'tags'])
            ->when(!$this->isGlobal, function ($q) {
                $q->where('setting_id', session('setting_id'));
            })
            ->when($this->filterTriggered, function ($q) {
                $q->when($this->startDate, fn($q) => $q->whereDate('date', '>=', $this->startDate))
                    ->when($this->endDate, fn($q) => $q->whereDate('date', '<=', $this->endDate))
                    ->when($this->customerId, fn($q) => $q->where('customer_id', $this->customerId))
                    ->when($this->saleStatus, fn($q) => $q->where('status', $this->saleStatus))
                    ->when($this->paymentStatus, fn($q) => $q->where('payment_status', $this->paymentStatus))
                    ->when($this->selectedTag, fn($q) => $q->whereHas('tags', fn($tq) => $tq->where('tags.id', $this->selectedTag)));
            })
            ->orderBy('date', 'desc');

        return view('livewire.reports.sale-report', [
            'sales' => $this->filterTriggered ? $query->paginate(15) : collect(),
            'tags' => Tag::all(),
            'statuses' => [
                Sale::STATUS_DRAFTED => 'Draft',
                Sale::STATUS_WAITING_APPROVAL => 'Menunggu Persetujuan',
                Sale::STATUS_APPROVED => 'Disetujui',
                Sale::STATUS_REJECTED => 'Ditolak',
                Sale::STATUS_DISPATCHED_PARTIALLY => 'Dikirim Sebagian',
                Sale::STATUS_DISPATCHED => 'Terkirim',
                Sale::STATUS_RETURNED => 'Diretur',
                Sale::STATUS_RETURNED_PARTIALLY => 'Diretur Sebagian',
            ],
            'isGlobal' => $this->isGlobal,
        ]);
    }
}
