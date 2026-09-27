<?php

namespace App\Livewire\Reports;

use Livewire\Component;
use Livewire\WithPagination;
use App\Services\Reports\PurchaseOrderCompletionReportFilterData;
use App\Services\Reports\PurchaseOrderCompletionReportQueryService;
use Modules\People\Entities\Supplier;
use Spatie\Tags\Tag;
use App\Services\Reports\PurchaseOrderCompletionReportValidator;
use App\Services\Reports\PurchaseOrderCompletionReportSnapshotService;
use App\Exports\PurchaseOrderCompletionReportExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Validation\ValidationException;

class PurchaseOrderCompletionReport extends Component
{
    use WithPagination;

    public $startDate, $endDate;
    public $settingId;
    public $sortField = 'date';
    public $sortDirection = 'desc';

    public $supplierIds = [];
    public $tagIds = [];
    public $tagLogic = 'any';
    public $sourceStage = 'Pemesanan';
    public $periodPreset = '';

    public $filterTriggered = false;
    public $appliedFilters = [];

    // Searchable filter states
    public $supplierSearch = '';
    public $supplierOptions = [];
    public $tagSearch = '';
    public $tagOptions = [];

    // Selected labels for display pills
    public $supplierLabels = [];
    public $tagLabels = [];

    protected $paginationTheme = 'bootstrap';

    protected $queryString = [
        'startDate' => ['except' => ''],
        'endDate' => ['except' => ''],
        'sourceStage' => ['except' => 'Pemesanan'],
        'supplierIds' => ['except' => []],
        'tagIds' => ['except' => []],
        'tagLogic' => ['except' => 'any'],
        'sortField' => ['except' => 'date'],
        'sortDirection' => ['except' => 'desc'],
    ];

    public function mount()
    {
        $this->settingId = session('setting_id');
        $this->startDate = $this->startDate ?: now()->startOfMonth()->format('Y-m-d');
        $this->endDate = $this->endDate ?: now()->endOfMonth()->format('Y-m-d');
        $this->sourceStage = $this->sourceStage ?: 'Pemesanan';
        $this->appliedFilters = $this->exportFilters();
        $this->filterTriggered = false;
    }

    public function updatedPeriodPreset($value): void
    {
        match ($value) {
            'today'          => [$this->startDate, $this->endDate] = [now()->format('Y-m-d'), now()->format('Y-m-d')],
            'this_week'      => [$this->startDate, $this->endDate] = [now()->startOfWeek()->format('Y-m-d'), now()->endOfWeek()->format('Y-m-d')],
            'this_month'     => [$this->startDate, $this->endDate] = [now()->startOfMonth()->format('Y-m-d'), now()->endOfMonth()->format('Y-m-d')],
            'this_quarter'   => [$this->startDate, $this->endDate] = [now()->startOfQuarter()->format('Y-m-d'), now()->endOfQuarter()->format('Y-m-d')],
            'this_year'      => [$this->startDate, $this->endDate] = [now()->startOfYear()->format('Y-m-d'), now()->endOfYear()->format('Y-m-d')],
            'previous_month' => [$this->startDate, $this->endDate] = [now()->subMonth()->startOfMonth()->format('Y-m-d'), now()->subMonth()->endOfMonth()->format('Y-m-d')],
            'previous_year'  => [$this->startDate, $this->endDate] = [now()->subYear()->startOfYear()->format('Y-m-d'), now()->subYear()->endOfYear()->format('Y-m-d')],
            default          => null,
        };
    }

    public function selectSupplier(int $id, string $name): void
    {
        if (!in_array($id, $this->supplierIds)) {
            $this->supplierIds[] = $id;
            $this->supplierLabels[$id] = $name;
        }
        $this->supplierSearch = '';
        $this->supplierOptions = [];
    }

    public function removeSupplier(int $id): void
    {
        $this->supplierIds = array_values(array_diff($this->supplierIds, [$id]));
        unset($this->supplierLabels[$id]);
    }

    public function selectTag(int $id, string $name): void
    {
        if (!in_array($id, $this->tagIds)) {
            $this->tagIds[] = $id;
            $this->tagLabels[$id] = $name;
        }
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    public function removeTag(int $id): void
    {
        $this->tagIds = array_values(array_diff($this->tagIds, [$id]));
        unset($this->tagLabels[$id]);
    }

    public function toggleSourceStage(string $stage): void
    {
        $this->sourceStage = $stage;
    }

    public function updatedSupplierSearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->supplierOptions = [];
            return;
        }
        $this->supplierOptions = Supplier::query()
            ->where('setting_id', $this->settingId)
            ->whereRaw('LOWER(supplier_name) LIKE ?', ['%' . mb_strtolower($value) . '%'])
            ->limit(10)->get(['id', 'supplier_name'])->toArray();
    }

    public function updatedTagSearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->tagOptions = [];
            return;
        }
        $locale = app()->getLocale();
        $this->tagOptions = Tag::query()
            ->where(fn ($q) => $q->containing($value, $locale)->orWhere(fn($sq) => $sq->containing($value, 'en')))
            ->limit(10)->get(['id', 'name'])->toArray();
    }

    public function sortBy($field): void
    {
        $allowedFields = ['date', 'reference', 'supplier_name', 'total_amount'];

        if (!in_array($field, $allowedFields)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function sortIcon($field): string
    {
        if ($field !== $this->sortField) return '';
        return $this->sortDirection === 'asc'
            ? '<i class="bi bi-caret-up-fill text-primary ms-1"></i>'
            : '<i class="bi bi-caret-down-fill text-primary ms-1"></i>';
    }

    public function cancelFilters(): void
    {
        if (!empty($this->appliedFilters)) {
            $this->startDate = $this->appliedFilters['startDate'] ?? $this->startDate;
            $this->endDate = $this->appliedFilters['endDate'] ?? $this->endDate;
            $this->periodPreset = $this->appliedFilters['periodPreset'] ?? '';
            $this->supplierIds = $this->appliedFilters['supplierIds'] ?? [];
            $this->supplierLabels = $this->appliedFilters['supplierLabels'] ?? [];
            $this->tagIds = $this->appliedFilters['tagIds'] ?? [];
            $this->tagLabels = $this->appliedFilters['tagLabels'] ?? [];
            $this->tagLogic = $this->appliedFilters['tagLogic'] ?? 'any';
            $this->sourceStage = $this->appliedFilters['sourceStage'] ?? 'Pemesanan';
            $this->sortField = $this->appliedFilters['sortField'] ?? 'date';
            $this->sortDirection = $this->appliedFilters['sortDirection'] ?? 'desc';
        }
        $this->supplierSearch = '';
        $this->supplierOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    public function resetFilters(): void
    {
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->endOfMonth()->format('Y-m-d');
        $this->periodPreset = '';
        $this->supplierIds = [];
        $this->supplierLabels = [];
        $this->tagIds = [];
        $this->tagLabels = [];
        $this->tagLogic = 'any';
        $this->sourceStage = 'Pemesanan';
        $this->sortField = 'date';
        $this->sortDirection = 'desc';
        $this->supplierSearch = '';
        $this->supplierOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    private function exportFilters(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'periodPreset' => $this->periodPreset,
            'supplierIds' => $this->supplierIds,
            'tagIds' => $this->tagIds,
            'tagLogic' => $this->tagLogic,
            'sourceStage' => $this->sourceStage,
            'sortField' => $this->sortField,
            'sortDirection' => $this->sortDirection,
        ];
    }

    public function applyFilters(
        PurchaseOrderCompletionReportValidator $validator,
        PurchaseOrderCompletionReportQueryService $queryService,
        PurchaseOrderCompletionReportSnapshotService $snapshotService
    ) {
        $filterArray = $this->exportFilters();
        
        try {
            $validated = $validator->validate($filterArray);
            
            $this->appliedFilters = array_merge($validated, [
                'periodPreset' => $this->periodPreset,
                'supplierLabels' => $this->supplierLabels,
                'tagLabels' => $this->tagLabels,
                'sortField' => $this->sortField,
                'sortDirection' => $this->sortDirection,
            ]);
            
            $this->filterTriggered = true;

            $applied = $this->appliedFilters;
            $applied['scopeSettingId'] = $this->settingId;
            $filter = PurchaseOrderCompletionReportFilterData::fromArray($applied);
            $count = $queryService->build($filter)->count();
            $snapshotService->createSnapshot($filter, $count);
            
            $this->resetPage();
        } catch (ValidationException $e) {
            $this->filterTriggered = false;
            throw $e;
        }
    }

    public function exportExcel(
        PurchaseOrderCompletionReportSnapshotService $snapshotService, 
        PurchaseOrderCompletionReportQueryService $queryService
    ) {
        if (!$this->filterTriggered || empty($this->appliedFilters)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan filter data terlebih dahulu sebelum melakukan ekspor.']);
            return null;
        }

        $current = $this->exportFilters();
        $current['scopeSettingId'] = $this->settingId;
        $filter = PurchaseOrderCompletionReportFilterData::fromArray($current);

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Filter telah diubah. Silakan klik tombol Filter kembali sebelum mengekspor.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $current['sortField'] ?? 'date', $current['sortDirection'] ?? 'desc');

        $filename = "purchase_order_completion_{$filter->startDate}_{$filter->endDate}.xlsx";

        return Excel::download(new PurchaseOrderCompletionReportExport($query, $filter, false), $filename);
    }

    public function exportCsv(
        PurchaseOrderCompletionReportSnapshotService $snapshotService, 
        PurchaseOrderCompletionReportQueryService $queryService
    ) {
        if (!$this->filterTriggered || empty($this->appliedFilters)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan filter data terlebih dahulu sebelum melakukan ekspor.']);
            return null;
        }

        $current = $this->exportFilters();
        $current['scopeSettingId'] = $this->settingId;
        $filter = PurchaseOrderCompletionReportFilterData::fromArray($current);

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Filter telah diubah. Silakan klik tombol Filter kembali sebelum mengekspor.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $current['sortField'] ?? 'date', $current['sortDirection'] ?? 'desc');

        $filename = "purchase_order_completion_{$filter->startDate}_{$filter->endDate}.csv";

        return Excel::download(new PurchaseOrderCompletionReportExport($query, $filter, true), $filename, \Maatwebsite\Excel\Excel::CSV);
    }

    public function render(PurchaseOrderCompletionReportQueryService $queryService)
    {
        $purchases = collect();
        $totals = null;
        
        if ($this->filterTriggered) {
            $applied = $this->appliedFilters;
            $applied['scopeSettingId'] = $this->settingId;
            $filter = PurchaseOrderCompletionReportFilterData::fromArray($applied);

            $query = $queryService->build($filter);

            $totalsSub = clone $query;
            $totalsSub->getQuery()->orders = null;
            $totals = \Illuminate\Support\Facades\DB::query()->fromSub($totalsSub, 't')->selectRaw('
                SUM(t.total_amount) as sum_total_amount,
                SUM(t.derived_delivery_amount) as sum_delivery_amount,
                SUM(t.derived_invoice_amount) as sum_invoice_amount,
                SUM(t.derived_active_paid) as sum_active_paid
            ')->first();

            $queryService->applySort($query, $this->sortField, $this->sortDirection);

            $purchases = $query->paginate(15);
        }

        return view('livewire.reports.purchase-order-completion-report', [
            'purchases' => $purchases,
            'mapRow' => [$queryService, 'mapRow'],
            'totals' => $totals
        ]);
    }
}
