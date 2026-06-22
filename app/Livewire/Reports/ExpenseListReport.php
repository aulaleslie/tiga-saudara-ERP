<?php

namespace App\Livewire\Reports;

use Livewire\Component;
use Livewire\WithPagination;
use App\Services\Reports\ExpenseListReportFilterData;
use App\Services\Reports\ExpenseListReportQueryService;
use App\Services\Reports\ExpenseListReportValidator;
use App\Services\Reports\ExpenseListReportSnapshotService;
use App\Exports\ExpenseListReportExport;
use Maatwebsite\Excel\Facades\Excel;
use Modules\People\Entities\Supplier;
use Spatie\Tags\Tag;
use Illuminate\Validation\ValidationException;

class ExpenseListReport extends Component
{
    use WithPagination;

    public $startDate;
    public $endDate;
    public $settingId;
    public $sortField = 'date';
    public $sortDirection = 'desc';

    public $supplierIds = [];
    public $tagIds = [];
    public $tagLogic = 'Salah satu';
    public $detailMode = false;

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

    public function mount()
    {
        $this->settingId = session('setting_id');
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->endOfMonth()->format('Y-m-d');
        $this->appliedFilters = $this->exportFilters();
        $this->filterTriggered = false;
    }

    // Select/Remove Methods
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

    // Search Methods
    public function updatedSupplierSearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->supplierOptions = [];
            return;
        }
        $settingId = $this->settingId ?: session('setting_id');
        $this->supplierOptions = Supplier::query()
            ->where('setting_id', $settingId)
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
        $tags = Tag::query()
            ->where(fn ($q) => $q->containing($value, $locale)->orWhere(fn($sq) => $sq->containing($value, 'en')))
            ->limit(10)->get(['id', 'name']);
            
        $this->tagOptions = $tags->map(function ($tag) use ($locale) {
            $nameData = is_string($tag->name) ? json_decode($tag->name, true) : $tag->name;
            $name = is_array($nameData) ? ($nameData[$locale] ?? ($nameData['en'] ?? reset($nameData))) : (string) $tag->name;
            return ['id' => $tag->id, 'name' => $name];
        })->toArray();
    }

    public function toggleDetailMode(): void
    {
        $this->detailMode = !$this->detailMode;
    }

    public function cancelFilters(): void
    {
        if (!empty($this->appliedFilters)) {
            $this->startDate = $this->appliedFilters['startDate'] ?? $this->startDate;
            $this->endDate = $this->appliedFilters['endDate'] ?? $this->endDate;
            $this->supplierIds = $this->appliedFilters['supplierIds'] ?? [];
            $this->supplierLabels = $this->appliedFilters['supplierLabels'] ?? [];
            $this->tagIds = $this->appliedFilters['tagIds'] ?? [];
            $this->tagLabels = $this->appliedFilters['tagLabels'] ?? [];
            $this->tagLogic = $this->appliedFilters['tagLogic'] ?? 'Salah satu';
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
        $this->supplierIds = [];
        $this->supplierLabels = [];
        $this->tagIds = [];
        $this->tagLabels = [];
        $this->tagLogic = 'Salah satu';
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
            'supplierIds' => $this->supplierIds,
            'tagIds' => $this->tagIds,
            'tagLogic' => $this->tagLogic,
            'sortField' => $this->sortField,
            'sortDirection' => $this->sortDirection,
        ];
    }

    public function applyFilters(
        ExpenseListReportValidator $validator,
        ExpenseListReportQueryService $queryService,
        ExpenseListReportSnapshotService $snapshotService
    ) {
        $filterArray = $this->exportFilters();
        
        try {
            $validated = $validator->validate($filterArray);
            
            $this->appliedFilters = array_merge($validated, [
                'supplierLabels' => $this->supplierLabels,
                'tagLabels' => $this->tagLabels,
            ]);
            
            $this->filterTriggered = true;

            $filter = ExpenseListReportFilterData::fromArray($this->appliedFilters);
            $filter->scopeSettingId = $this->settingId;
            $count = $queryService->build($filter)->count();
            $snapshotService->createSnapshot($filter, $count);
            
            $this->resetPage();
        } catch (ValidationException $e) {
            $this->filterTriggered = false;
            throw $e;
        }
    }

    public function exportExcel(
        ExpenseListReportSnapshotService $snapshotService, 
        ExpenseListReportQueryService $queryService
    ) {
        $filter = ExpenseListReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;
        $filter->detailMode = $this->detailMode;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

        $filename = "daftar_pengeluaran_{$filter->startDate}_{$filter->endDate}.xlsx";

        return Excel::download(new ExpenseListReportExport($query, $filter, false), $filename);
    }

    public function exportCsv(
        ExpenseListReportSnapshotService $snapshotService, 
        ExpenseListReportQueryService $queryService
    ) {
        $filter = ExpenseListReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;
        $filter->detailMode = $this->detailMode;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

        $filename = "daftar_pengeluaran_{$filter->startDate}_{$filter->endDate}.csv";

        return Excel::download(new ExpenseListReportExport($query, $filter, true), $filename, \Maatwebsite\Excel\Excel::CSV);
    }

    public function exportPdf(
        ExpenseListReportSnapshotService $snapshotService, 
        ExpenseListReportQueryService $queryService
    ) {
        $filter = ExpenseListReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;
        $filter->detailMode = $this->detailMode;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

        $filename = "daftar_pengeluaran_{$filter->startDate}_{$filter->endDate}.pdf";

        return Excel::download(new ExpenseListReportExport($query, $filter, false), $filename, \Maatwebsite\Excel\Excel::DOMPDF);
    }

    public function render(ExpenseListReportQueryService $queryService)
    {
        $expenses = collect();
        $grandTotals = ['Jumlah' => 0, 'Tax' => 0, 'Sisa Tagihan' => 0];
        
        if ($this->filterTriggered) {
            $filter = new ExpenseListReportFilterData(
                startDate: $this->appliedFilters['startDate'] ?? $this->startDate,
                endDate: $this->appliedFilters['endDate'] ?? $this->endDate,
                scopeSettingId: $this->settingId,
                supplierIds: $this->appliedFilters['supplierIds'] ?? [],
                tagIds: $this->appliedFilters['tagIds'] ?? [],
                tagLogic: $this->appliedFilters['tagLogic'] ?? 'Salah satu',
                sortField: $this->appliedFilters['sortField'] ?? 'date',
                sortDirection: $this->appliedFilters['sortDirection'] ?? 'desc',
                detailMode: $this->detailMode
            );

            $query = $queryService->build($filter);
            $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

            // Get all for totals
            $totalQuery = clone $query;
            $totalQuery->setEagerLoads([]);
            
            $expenses = $query->paginate(15);

            // Compute grand totals from all matching expenses (not just current page)
            if ($expenses->total() > 0) {
                $allExpenses = (clone $query)->get();
                $grandTotals = ExpenseListReportQueryService::computeTotals($allExpenses);
            }
        }

        return view('livewire.reports.expense-list-report', [
            'expenses' => $expenses,
            'grandTotals' => $grandTotals,
        ]);
    }
}
