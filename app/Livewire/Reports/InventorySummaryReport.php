<?php

namespace App\Livewire\Reports;

use App\Exports\InventorySummaryReportExport;
use App\Services\Reports\InventorySummaryReportFilterData;
use App\Services\Reports\InventorySummaryReportQueryService;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Maatwebsite\Excel\Facades\Excel;

class InventorySummaryReport extends Component
{
    use WithPagination;

    public $asOfDate;
    public $periodPreset = '';
    public $stockStatus = '';
    public $categoryIds = [];
    public $categoryMatchMode = 'any';
    public $productIds = [];
    public $sortColumn = 'product_name';
    public $sortDirection = 'asc';
    
    public $filterTriggered = false;
    public $settingId;
    public $appliedFilters = [];
    
    // UI state
    public $categorySearch = '';
    public $categoryOptions = [];
    public $categoryLabels = [];

    public $productSearch = '';
    public $productOptions = [];
    public $productLabels = [];

    public $showInventoryAccount = false; // Deferred

    protected $paginationTheme = 'bootstrap';

    public function sortBy($field): void
    {
        $allowedFields = ['product_name', 'product_code', 'stock', 'average_cost', 'value'];

        if (!in_array($field, $allowedFields)) {
            return;
        }

        if ($this->sortColumn === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $field;
            $this->sortDirection = 'asc';
        }

        if ($this->filterTriggered) {
            $this->appliedFilters['sortColumn'] = $this->sortColumn;
            $this->appliedFilters['sortDirection'] = $this->sortDirection;
        }

        $this->resetPage();
    }

    public function sortIcon($field): string
    {
        if ($field !== $this->sortColumn) return '';
        return $this->sortDirection === 'asc'
            ? '<i class="bi bi-caret-up-fill text-primary ms-1"></i>'
            : '<i class="bi bi-caret-down-fill text-primary ms-1"></i>';
    }

    public function updatedPeriodPreset($value): void
    {
        match ($value) {
            'today'      => $this->asOfDate = now()->format('Y-m-d'),
            'this_week'  => $this->asOfDate = now()->endOfWeek()->format('Y-m-d'),
            'this_month' => $this->asOfDate = now()->endOfMonth()->format('Y-m-d'),
            'this_year'  => $this->asOfDate = now()->endOfYear()->format('Y-m-d'),
            'last_month' => $this->asOfDate = now()->subMonth()->endOfMonth()->format('Y-m-d'),
            'this_quarter' => $this->asOfDate = now()->lastOfMonth()->month((ceil(now()->month / 3) * 3))->format('Y-m-d'),
            'last_quarter' => $this->asOfDate = now()->subMonths(3)->lastOfMonth()->month((ceil(now()->subMonths(3)->month / 3) * 3))->format('Y-m-d'),
            'last_year' => $this->asOfDate = now()->subYear()->endOfYear()->format('Y-m-d'),
            default      => null,
        };
    }

    public function updatedCategorySearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->categoryOptions = [];
            return;
        }

        $this->categoryOptions = Category::query()
            ->where('setting_id', $this->settingId)
            ->whereRaw('LOWER(category_name) LIKE ?', ['%' . mb_strtolower($value) . '%'])
            ->limit(10)
            ->get(['id', 'category_name'])
            ->toArray();
    }

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

    public function updatedProductSearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->productOptions = [];
            return;
        }

        $this->productOptions = Product::query()
            ->where('setting_id', $this->settingId)
            ->whereRaw('LOWER(product_name) LIKE ?', ['%' . mb_strtolower($value) . '%'])
            ->limit(10)
            ->get(['id', 'product_name'])
            ->toArray();
    }

    public function selectProduct(int $id, string $name): void
    {
        if (!in_array($id, $this->productIds)) {
            $this->productIds[] = $id;
            $this->productLabels[$id] = $name;
        }
        $this->productSearch = '';
        $this->productOptions = [];
    }

    public function removeProduct(int $id): void
    {
        $this->productIds = array_values(array_diff($this->productIds, [$id]));
        unset($this->productLabels[$id]);
    }

    public function mount(): void
    {
        abort_unless(auth()->user()->can('inventoryValuationReports.access'), 403);
        $this->settingId = session('setting_id');
        $this->asOfDate = now()->format('Y-m-d');
        $this->appliedFilters = $this->exportFilters();
    }

    public function cancelFilters(): void
    {
        if (!empty($this->appliedFilters)) {
            $this->asOfDate = $this->appliedFilters['asOfDate'] ?? $this->asOfDate;
            $this->periodPreset = $this->appliedFilters['periodPreset'] ?? '';
            $this->stockStatus = $this->appliedFilters['stockStatus'] ?? '';
            $this->categoryIds = $this->appliedFilters['categoryIds'] ?? [];
            $this->categoryLabels = $this->appliedFilters['categoryLabels'] ?? [];
            $this->categoryMatchMode = $this->appliedFilters['categoryMatchMode'] ?? 'any';
            $this->productIds = $this->appliedFilters['productIds'] ?? [];
            $this->productLabels = $this->appliedFilters['productLabels'] ?? [];
            $this->showInventoryAccount = $this->appliedFilters['showInventoryAccount'] ?? false;
        }
        $this->categorySearch = '';
        $this->categoryOptions = [];
        $this->productSearch = '';
        $this->productOptions = [];
    }

    public function resetFilters(): void
    {
        $this->asOfDate = now()->format('Y-m-d');
        $this->periodPreset = '';
        $this->stockStatus = '';
        $this->categoryIds = [];
        $this->categoryLabels = [];
        $this->categoryMatchMode = 'any';
        $this->productIds = [];
        $this->productLabels = [];
        $this->showInventoryAccount = false;
        
        $this->categorySearch = '';
        $this->categoryOptions = [];
        $this->productSearch = '';
        $this->productOptions = [];
    }

    public function applyFilters(): void
    {
        $this->validate([
            'asOfDate' => 'required|date',
        ]);

        $this->appliedFilters = $this->exportFilters();
        $this->filterTriggered = true;
        $this->resetPage();
    }

    private function exportFilters(): array
    {
        return [
            'asOfDate' => $this->asOfDate,
            'periodPreset' => $this->periodPreset,
            'stockStatus' => $this->stockStatus,
            'categoryIds' => $this->categoryIds,
            'categoryLabels' => $this->categoryLabels,
            'categoryMatchMode' => $this->categoryMatchMode,
            'productIds' => $this->productIds,
            'productLabels' => $this->productLabels,
            'sortColumn' => $this->sortColumn,
            'sortDirection' => $this->sortDirection,
            'showInventoryAccount' => $this->showInventoryAccount,
        ];
    }

    public function exportExcel(InventorySummaryReportQueryService $queryService)
    {
        abort_unless(auth()->user()->can('inventoryValuationReports.access'), 403);
        
        if (!$this->filterTriggered || empty($this->appliedFilters)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan filter data terlebih dahulu sebelum melakukan ekspor.']);
            return null;
        }

        $filterData = InventorySummaryReportFilterData::fromArray($this->appliedFilters);
        $result = $queryService->getSummary($filterData, $this->settingId, 1, 1); // just to get all rows

        $dateFormatted = Carbon::parse($filterData->asOfDate)->format('d-m-Y');
        $fileName = sprintf('ringkasan-persediaan-barang_%s.xlsx', $dateFormatted);

        return Excel::download(
            new \App\Exports\InventorySummaryReportExport($result['allRows'], $filterData, false),
            $fileName
        );
    }

    public function exportCsv(InventorySummaryReportQueryService $queryService)
    {
        abort_unless(auth()->user()->can('inventoryValuationReports.access'), 403);
        
        if (!$this->filterTriggered || empty($this->appliedFilters)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan filter data terlebih dahulu sebelum melakukan ekspor.']);
            return null;
        }

        $filterData = InventorySummaryReportFilterData::fromArray($this->appliedFilters);
        $result = $queryService->getSummary($filterData, $this->settingId, 1, 1);

        $dateFormatted = Carbon::parse($filterData->asOfDate)->format('d-m-Y');
        $fileName = sprintf('ringkasan-persediaan-barang_%s.csv', $dateFormatted);

        return Excel::download(
            new \App\Exports\InventorySummaryReportExport($result['allRows'], $filterData, true),
            $fileName,
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    public function render(InventorySummaryReportQueryService $queryService)
    {
        $paginator = null;
        $totalItems = 0;
        $totalValue = 0.0;

        if ($this->filterTriggered) {
            $filterData = InventorySummaryReportFilterData::fromArray($this->appliedFilters);
            $result = $queryService->getSummary($filterData, $this->settingId, 15, $this->getPage());
            $paginator = $result['paginator'];
            $totalItems = $result['totalItems'];
            $totalValue = $result['totalValue'];
        }

        return view('livewire.reports.inventory-summary-report', [
            'paginator' => $paginator,
            'totalItems' => $totalItems,
            'totalValue' => $totalValue,
        ]);
    }
}
