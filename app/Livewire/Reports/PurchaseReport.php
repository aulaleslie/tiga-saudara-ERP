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

use App\Services\Reports\PurchaseReportFilterData;
use App\Services\Reports\PurchaseReportQueryService;
use App\Services\Reports\PurchaseReportSnapshotService;
use App\Services\Reports\PurchaseReportValidator;
use Illuminate\Validation\ValidationException;

class PurchaseReport extends Component
{
    use WithPagination;

    public $startDate, $endDate, $withTax;
    public $supplierIds = [];
    public $tagIds = [];
    public $status, $paymentStatus;
    public $filterTriggered = false;
    public $isGlobal = false;
    public $settingId;

    // Searchable filter states
    public $supplierSearch = '';
    public $supplierOptions = [];
    public $tagSearch = '';
    public $tagOptions = [];

    protected $paginationTheme = 'bootstrap';

    protected $listeners = ['supplierSelected', 'tagSelected'];

    public function selectSupplier($id)
    {
        if (!in_array($id, $this->supplierIds)) {
            $this->supplierIds[] = $id;
        }
        $this->supplierSearch = '';
        $this->supplierOptions = [];
    }

    public function removeSupplier($id)
    {
        $this->supplierIds = array_diff($this->supplierIds, [$id]);
    }

    public function selectTag($id)
    {
        if (!in_array($id, $this->tagIds)) {
            $this->tagIds[] = $id;
        }
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    public function removeTag($id)
    {
        $this->tagIds = array_diff($this->tagIds, [$id]);
    }

    public function mount($isGlobal = false)
    {
        $this->isGlobal = $isGlobal;
        $this->settingId = session('setting_id');
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
    }

    public function updatedSupplierSearch($value)
    {
        
        if (strlen($value) < 2) {
            $this->supplierOptions = [];
            return;
        }

        $this->supplierOptions = Supplier::query()
            ->when(!$this->isGlobal, fn($q) => $q->where('setting_id', $this->settingId))
            ->when(!empty($this->supplierIds), fn($q) => $q->whereNotIn('id', $this->supplierIds))
            ->where('supplier_name', 'like', '%' . $value . '%')
            ->limit(10)
            ->get(['id', 'supplier_name'])
            ->toArray();
    }

    public function updatedTagSearch($value)
    {

        if (strlen($value) < 2) {
            $this->tagOptions = [];
            return;
        }

        $locale = app()->getLocale();
        $this->tagOptions = Tag::query()
            ->when(!empty($this->tagIds), fn($q) => $q->whereNotIn('id', $this->tagIds))
            ->where("name->$locale", 'like', '%' . $value . '%')
            ->limit(10)
            ->get(['id', 'name'])
            ->toArray();
    }

    public function applyFilters(
        PurchaseReportValidator $validator,
        PurchaseReportQueryService $queryService,
        PurchaseReportSnapshotService $snapshotService
    ) {
        $filterArray = $this->exportFilters();
        $filterArray['scopeSettingId'] = $this->settingId;

        try {
            $validated = $validator->validate($filterArray);
            $filterData = PurchaseReportFilterData::fromArray($validated);
            
            $query = $queryService->build($filterData);
            $count = $query->count();

            $snapshotService->createSnapshot($filterData, $count);

            $this->filterTriggered = true;
            $this->resetPage();
        } catch (ValidationException $e) {
            $this->filterTriggered = false;
            throw $e;
        }
    }

    public function exportExcel(PurchaseReportSnapshotService $snapshotService)
    {
        if (!$this->canExport($snapshotService)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan jalankan laporan terlebih dahulu atau perbarui hasil laporan sebelum mengekspor.']);
            return null;
        }

        $filters = array_merge($this->exportFilters(), ['scopeSettingId' => $this->settingId]);
        $filename = $this->isGlobal ? 'laporan-pembelian-global.xlsx' : 'laporan-pembelian.xlsx';
        return Excel::download(new PurchaseReportExport($filters), $filename);
    }

    public function exportCsv(PurchaseReportSnapshotService $snapshotService)
    {
        if (!$this->canExport($snapshotService)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan jalankan laporan terlebih dahulu atau perbarui hasil laporan sebelum mengekspor.']);
            return null;
        }

        $filters = array_merge($this->exportFilters(), ['scopeSettingId' => $this->settingId]);
        $filename = $this->isGlobal ? 'laporan-pembelian-global.csv' : 'laporan-pembelian.csv';
        return Excel::download(new PurchaseReportExport($filters), $filename, \Maatwebsite\Excel\Excel::CSV);
    }

    public function exportPdf(PurchaseReportSnapshotService $snapshotService)
    {
        if (!$this->canExport($snapshotService)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan jalankan laporan terlebih dahulu atau perbarui hasil laporan sebelum mengekspor.']);
            return null;
        }

        $filters = array_merge($this->exportFilters(), ['scopeSettingId' => $this->settingId]);
        $purchases = (new PurchaseReportExport($filters))->collection();
        $pdf = Pdf::loadView('exports.purchase-pdf', ['purchases' => $purchases]);
        $filename = $this->isGlobal ? 'laporan-pembelian-global.pdf' : 'laporan-pembelian.pdf';
        return response()->streamDownload(fn () => print($pdf->stream()), $filename);
    }

    private function canExport(PurchaseReportSnapshotService $snapshotService): bool
    {
        $currentFilter = PurchaseReportFilterData::fromArray(
            array_merge($this->exportFilters(), ['scopeSettingId' => $this->settingId])
        );
        return $snapshotService->isValidForExport($currentFilter);
    }

    private function exportFilters(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'supplierIds' => $this->supplierIds,
            'withTax' => $this->withTax,
            'tagIds' => $this->tagIds,
            'status' => $this->status,
            'paymentStatus' => $this->paymentStatus,
            'isGlobal' => $this->isGlobal,
        ];
    }

    public function render(PurchaseReportQueryService $queryService)
    {
        $purchases = collect();
        if ($this->filterTriggered) {
            $filterData = PurchaseReportFilterData::fromArray(
                array_merge($this->exportFilters(), ['scopeSettingId' => $this->settingId])
            );
            $purchases = $queryService->build($filterData)->paginate(15);
        }

        return view('livewire.reports.purchase-report', [
            'purchases' => $purchases,
            'statuses' => [
                Purchase::STATUS_DRAFTED => 'Draft',
                Purchase::STATUS_WAITING_APPROVAL => 'Menunggu Persetujuan',
                Purchase::STATUS_APPROVED => 'Disetujui',
                Purchase::STATUS_REJECTED => 'Ditolak',
                Purchase::STATUS_RECEIVED_PARTIALLY => 'Diterima Sebagian',
                Purchase::STATUS_RECEIVED => 'Diterima',
            ],
            'isGlobal' => $this->isGlobal,
        ]);
    }
}
