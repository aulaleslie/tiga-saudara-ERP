<?php

namespace App\Livewire\Reports;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use App\Services\Reports\ExpenseDetailsReportFilterData;
use App\Services\Reports\ExpenseDetailsReportQueryService;
use App\Services\Reports\ExpenseDetailsReportValidator;
use App\Services\Reports\ExpenseDetailsReportSnapshotService;
use App\Exports\ExpenseDetailsReportExport;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Expense\Entities\ExpenseCategory;
use Spatie\Tags\Tag;
use Illuminate\Validation\ValidationException;

class ExpenseDetailsReport extends Component
{
    use WithPagination;

    public $startDate;
    public $endDate;
    public $settingId;
    public $sortDirection = 'asc';

    public $categoryIds = [];
    public $tagIds = [];
    public $tagLogic = 'Salah satu';

    public $filterTriggered = false;
    
    public $appliedFilters = [];

    // Searchable filter states
    public $categorySearch = '';
    public $categoryOptions = [];
    public $tagSearch = '';
    public $tagOptions = [];

    // Selected labels for display pills
    public $categoryLabels = [];
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
    public function selectCategory(int $id, string $name): void
    {
        if (!in_array($id, $this->categoryIds)) {
            $this->categoryIds[] = $id;
            $this->categoryLabels[$id] = $name;
        }
        $this->categorySearch = '';
        $this->categoryOptions = [];
    }

    public function removeCategory(int $id): void
    {
        $this->categoryIds = array_values(array_diff($this->categoryIds, [$id]));
        unset($this->categoryLabels[$id]);
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
    public function updatedCategorySearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->categoryOptions = [];
            return;
        }
        $this->categoryOptions = ExpenseCategory::query()
            ->whereRaw('LOWER(category_name) LIKE ?', ['%' . mb_strtolower($value) . '%'])
            ->limit(10)->get(['id', 'category_name'])->toArray();
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

    public function cancelFilters(): void
    {
        if (!empty($this->appliedFilters)) {
            $this->startDate = $this->appliedFilters['startDate'] ?? $this->startDate;
            $this->endDate = $this->appliedFilters['endDate'] ?? $this->endDate;
            $this->categoryIds = $this->appliedFilters['categoryIds'] ?? [];
            $this->categoryLabels = $this->appliedFilters['categoryLabels'] ?? [];
            $this->tagIds = $this->appliedFilters['tagIds'] ?? [];
            $this->tagLabels = $this->appliedFilters['tagLabels'] ?? [];
            $this->tagLogic = $this->appliedFilters['tagLogic'] ?? 'Salah satu';
            $this->sortDirection = $this->appliedFilters['sortDirection'] ?? 'asc';
        }
        $this->categorySearch = '';
        $this->categoryOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    public function resetFilters(): void
    {
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->endOfMonth()->format('Y-m-d');
        $this->categoryIds = [];
        $this->categoryLabels = [];
        $this->tagIds = [];
        $this->tagLabels = [];
        $this->tagLogic = 'Salah satu';
        $this->sortDirection = 'asc';
        $this->categorySearch = '';
        $this->categoryOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    private function exportFilters(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'categoryIds' => $this->categoryIds,
            'tagIds' => $this->tagIds,
            'tagLogic' => $this->tagLogic,
            'sortDirection' => $this->sortDirection,
        ];
    }

    public function applyFilters(
        ExpenseDetailsReportValidator $validator,
        ExpenseDetailsReportQueryService $queryService,
        ExpenseDetailsReportSnapshotService $snapshotService
    ) {
        $filterArray = $this->exportFilters();
        
        try {
            $validated = $validator->validate($filterArray);
            
            $this->appliedFilters = array_merge($validated, [
                'categoryLabels' => $this->categoryLabels,
                'tagLabels' => $this->tagLabels,
            ]);
            
            $this->filterTriggered = true;

            $filter = ExpenseDetailsReportFilterData::fromArray($this->appliedFilters);
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
        ExpenseDetailsReportSnapshotService $snapshotService, 
        ExpenseDetailsReportQueryService $queryService
    ) {
        $filter = ExpenseDetailsReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->buildSorted($filter);

        $filename = "expense_details_{$filter->startDate}_{$filter->endDate}.xlsx";

        return Excel::download(new ExpenseDetailsReportExport($query, $filter, false), $filename);
    }

    public function exportCsv(
        ExpenseDetailsReportSnapshotService $snapshotService, 
        ExpenseDetailsReportQueryService $queryService
    ) {
        $filter = ExpenseDetailsReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->buildSorted($filter);

        $filename = "expense_details_{$filter->startDate}_{$filter->endDate}.csv";

        return Excel::download(new ExpenseDetailsReportExport($query, $filter, true), $filename, \Maatwebsite\Excel\Excel::CSV);
    }

    public function exportPdf(
        ExpenseDetailsReportSnapshotService $snapshotService, 
        ExpenseDetailsReportQueryService $queryService
    ) {
        $filter = ExpenseDetailsReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->buildSorted($filter);

        $filename = "expense_details_{$filter->startDate}_{$filter->endDate}.pdf";

        return Excel::download(new ExpenseDetailsReportExport($query, $filter, false), $filename, \Maatwebsite\Excel\Excel::DOMPDF);
    }

    public function render(ExpenseDetailsReportQueryService $queryService)
    {
        $groupedData = [];
        $grandTotal = 0.0;
        $transactionCount = 0;
        $paginator = null;
        
        if ($this->filterTriggered) {
            $filter = new ExpenseDetailsReportFilterData(
                startDate: $this->appliedFilters['startDate'] ?? $this->startDate,
                endDate: $this->appliedFilters['endDate'] ?? $this->endDate,
                scopeSettingId: $this->settingId,
                categoryIds: $this->appliedFilters['categoryIds'] ?? [],
                tagIds: $this->appliedFilters['tagIds'] ?? [],
                tagLogic: $this->appliedFilters['tagLogic'] ?? 'Salah satu',
                sortDirection: $this->appliedFilters['sortDirection'] ?? 'asc'
            );

            $query = $queryService->buildSorted($filter);

            // Group and summarize from the full matching result set so that
            // category subtotals, grand total, and transaction count are always
            // complete and mutually consistent. Pagination is then applied over
            // the resulting category groups, keeping each group's subtotal whole.
            $allExpenses = $query->get();
            $mapped = ExpenseDetailsReportQueryService::groupAndSummarize($allExpenses);
            $allGroups = $mapped['groups'];
            $grandTotal = $mapped['grandTotal'];
            $transactionCount = $mapped['transactionCount'];

            $perPage = 15;
            $page = Paginator::resolveCurrentPage();
            $pagedGroups = array_slice($allGroups, ($page - 1) * $perPage, $perPage);
            $groupedData = $pagedGroups;

            $paginator = new LengthAwarePaginator(
                $pagedGroups,
                count($allGroups),
                $perPage,
                $page,
                ['path' => Paginator::resolveCurrentPath()]
            );
        }

        return view('livewire.reports.expense-details-report', [
            'groupedData' => $groupedData,
            'grandTotal' => $grandTotal,
            'transactionCount' => $transactionCount,
            'paginator' => $paginator,
        ]);
    }
}
