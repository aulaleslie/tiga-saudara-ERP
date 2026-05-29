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
    public $deliveryStatus, $paymentStatus;
    public $periodPreset = '';
    public $dateBasis = 'transaction_date';
    public $transactionType = 'purchase_invoice';
    public $filterTriggered = false;
    public $isGlobal = false;
    public $settingId;

    public $appliedFilters = [];

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
        $this->startDate = $isGlobal ? now()->startOfMonth()->format('Y-m-d') : now()->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
        $this->appliedFilters = array_merge($this->exportFilters(), ['scopeSettingId' => $this->settingId]);
    }

    public function updatedPeriodPreset($value)
    {
        switch ($value) {
            case 'today':
                $this->startDate = now()->format('Y-m-d');
                $this->endDate = now()->format('Y-m-d');
                break;
            case 'this_week':
                $this->startDate = now()->startOfWeek()->format('Y-m-d');
                $this->endDate = now()->endOfWeek()->format('Y-m-d');
                break;
            case 'this_month':
                $this->startDate = now()->startOfMonth()->format('Y-m-d');
                $this->endDate = now()->endOfMonth()->format('Y-m-d');
                break;
            case 'this_year':
                $this->startDate = now()->startOfYear()->format('Y-m-d');
                $this->endDate = now()->endOfYear()->format('Y-m-d');
                break;
        }
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

    public function cancelFilters()
    {
        if (!empty($this->appliedFilters)) {
            $this->startDate = $this->appliedFilters['startDate'] ?? $this->startDate;
            $this->endDate = $this->appliedFilters['endDate'] ?? $this->endDate;
            $this->supplierIds = $this->appliedFilters['supplierIds'] ?? [];
            $this->withTax = $this->appliedFilters['withTax'] ?? '';
            $this->tagIds = $this->appliedFilters['tagIds'] ?? [];
            $this->deliveryStatus = $this->appliedFilters['deliveryStatus'] ?? '';
            $this->paymentStatus = $this->appliedFilters['paymentStatus'] ?? '';
            $this->periodPreset = $this->appliedFilters['periodPreset'] ?? '';
            $this->dateBasis = $this->appliedFilters['dateBasis'] ?? 'transaction_date';
            $this->transactionType = $this->appliedFilters['transactionType'] ?? 'purchase_invoice';
        }
        $this->supplierSearch = '';
        $this->supplierOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    public function resetFilters()
    {
        $this->startDate = $this->isGlobal ? now()->startOfMonth()->format('Y-m-d') : now()->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
        $this->supplierIds = [];
        $this->tagIds = [];
        $this->withTax = '';
        $this->deliveryStatus = '';
        $this->paymentStatus = '';
        $this->periodPreset = '';
        $this->dateBasis = 'transaction_date';
        $this->transactionType = 'purchase_invoice';
        
        $this->supplierSearch = '';
        $this->supplierOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
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

            $this->appliedFilters = $validated;
            $this->filterTriggered = true;
            $this->resetPage();
        } catch (ValidationException $e) {
            $this->filterTriggered = false;
            throw $e;
        }
    }

    public function exportExcel(PurchaseReportSnapshotService $snapshotService)
    {
        $this->dispatch('alert', ['type' => 'error', 'message' => 'Fitur ekspor belum tersedia untuk versi ini.']);
        return null;
    }

    public function exportCsv(PurchaseReportSnapshotService $snapshotService)
    {
        $this->dispatch('alert', ['type' => 'error', 'message' => 'Fitur ekspor belum tersedia untuk versi ini.']);
        return null;
    }

    public function exportPdf(PurchaseReportSnapshotService $snapshotService)
    {
        $this->dispatch('alert', ['type' => 'error', 'message' => 'Fitur ekspor belum tersedia untuk versi ini.']);
        return null;
    }

    private function canExport(PurchaseReportSnapshotService $snapshotService): bool
    {
        $currentFilter = PurchaseReportFilterData::fromArray($this->appliedFilters);
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
            'deliveryStatus' => $this->deliveryStatus,
            'paymentStatus' => $this->paymentStatus,
            'isGlobal' => $this->isGlobal,
            'periodPreset' => $this->periodPreset,
            'dateBasis' => $this->dateBasis,
            'transactionType' => $this->transactionType,
        ];
    }

    public function render(PurchaseReportQueryService $queryService)
    {
        $purchases = collect();
        if ($this->filterTriggered) {
            $filterData = PurchaseReportFilterData::fromArray($this->appliedFilters);
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
                Purchase::STATUS_RETURNED_PARTIALLY => 'Diretur Sebagian',
                Purchase::STATUS_RETURNED => 'Diretur',
            ],
            'isGlobal' => $this->isGlobal,
        ]);
    }
}
