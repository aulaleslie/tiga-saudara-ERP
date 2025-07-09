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
    public $filterTriggered = false;

    protected $paginationTheme = 'bootstrap';

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
        ];
    }

    public function render()
    {
        $query = Purchase::with('supplier')
            ->when($this->filterTriggered, function ($q) {
                $q->when($this->startDate, fn($q) => $q->where('date', '>=', $this->startDate))
                    ->when($this->endDate, fn($q) => $q->where('date', '<=', $this->endDate))
                    ->when($this->supplierId, fn($q) => $q->where('supplier_id', $this->supplierId))
                    ->when($this->withTax !== null && $this->withTax !== '', fn($q) => $q->where('is_tax_included', $this->withTax))
                    ->when($this->selectedTag, fn($q) => $q->whereHas('tags', fn($tq) => $tq->where('tags.id', $this->selectedTag)));
            });

        return view('livewire.reports.purchase-report', [
            'purchases' => $query->paginate(10),
            'suppliers' => Supplier::all(),
            'tags' => Tag::all(),
        ]);
    }
}
