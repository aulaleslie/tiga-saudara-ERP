<?php

namespace App\Livewire\Reports;

use App\Exports\InventoryValuationReportExport;
use App\Services\Reports\InventoryValuationReportFilterData;
use App\Services\Reports\InventoryValuationReportQueryService;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Maatwebsite\Excel\Facades\Excel;

class InventoryValuationReport extends Component
{
    use WithPagination;

    public $tanggalAwal;
    public $tanggalAkhir;
    public $periodPreset = '';
    public $categoryIds = [];
    public $categoryMatchMode = 'any';
    public $productIds = [];
    public $sortColumn = 'product_name';
    public $sortDirection = 'asc';
    
    public $filterTriggered = false;
    public $settingId;
    public $appliedFilters = [];
    
    public $expandedProducts = [];
    public $loadedProductDetails = [];
    
    // UI state
    public $categorySearch = '';
    public $categoryOptions = [];
    public $categoryLabels = [];

    public $productSearch = '';
    public $productOptions = [];
    public $productLabels = [];

    protected $paginationTheme = 'bootstrap';

    public function sortBy($field): void
    {
        $allowedFields = ['product_name', 'product_code'];

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
        $now = now();
        switch ($value) {
            case 'today':
                $this->tanggalAwal = $now->format('Y-m-d');
                $this->tanggalAkhir = $now->format('Y-m-d');
                break;
            case 'this_week':
                $this->tanggalAwal = $now->copy()->startOfWeek()->format('Y-m-d');
                $this->tanggalAkhir = $now->copy()->endOfWeek()->format('Y-m-d');
                break;
            case 'this_month':
                $this->tanggalAwal = $now->copy()->startOfMonth()->format('Y-m-d');
                $this->tanggalAkhir = $now->copy()->endOfMonth()->format('Y-m-d');
                break;
            case 'this_quarter':
                $this->tanggalAwal = $now->copy()->firstOfQuarter()->format('Y-m-d');
                $this->tanggalAkhir = $now->copy()->lastOfQuarter()->format('Y-m-d');
                break;
            case 'this_year':
                $this->tanggalAwal = $now->copy()->startOfYear()->format('Y-m-d');
                $this->tanggalAkhir = $now->copy()->endOfYear()->format('Y-m-d');
                break;
            case 'yesterday':
                $yesterday = $now->copy()->subDay();
                $this->tanggalAwal = $yesterday->format('Y-m-d');
                $this->tanggalAkhir = $yesterday->format('Y-m-d');
                break;
            case 'last_week':
                $lastWeek = $now->copy()->subWeek();
                $this->tanggalAwal = $lastWeek->startOfWeek()->format('Y-m-d');
                $this->tanggalAkhir = $lastWeek->copy()->endOfWeek()->format('Y-m-d');
                break;
            case 'last_month':
                $lastMonth = $now->copy()->subMonth();
                $this->tanggalAwal = $lastMonth->startOfMonth()->format('Y-m-d');
                $this->tanggalAkhir = $lastMonth->copy()->endOfMonth()->format('Y-m-d');
                break;
            case 'last_quarter':
                $lastQuarter = $now->copy()->subQuarter();
                $this->tanggalAwal = $lastQuarter->firstOfQuarter()->format('Y-m-d');
                $this->tanggalAkhir = $lastQuarter->copy()->lastOfQuarter()->format('Y-m-d');
                break;
            case 'last_year':
                $lastYear = $now->copy()->subYear();
                $this->tanggalAwal = $lastYear->startOfYear()->format('Y-m-d');
                $this->tanggalAkhir = $lastYear->copy()->endOfYear()->format('Y-m-d');
                break;
        }
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
        $this->periodPreset = 'this_month';
        $this->updatedPeriodPreset('this_month');
        $this->appliedFilters = $this->exportFilters();
    }

    public function cancelFilters(): void
    {
        if (!empty($this->appliedFilters)) {
            $this->tanggalAwal = $this->appliedFilters['tanggalAwal'] ?? $this->tanggalAwal;
            $this->tanggalAkhir = $this->appliedFilters['tanggalAkhir'] ?? $this->tanggalAkhir;
            $this->periodPreset = $this->appliedFilters['periodPreset'] ?? '';
            $this->categoryIds = $this->appliedFilters['categoryIds'] ?? [];
            $this->categoryLabels = $this->appliedFilters['categoryLabels'] ?? [];
            $this->categoryMatchMode = $this->appliedFilters['categoryMatchMode'] ?? 'any';
            $this->productIds = $this->appliedFilters['productIds'] ?? [];
            $this->productLabels = $this->appliedFilters['productLabels'] ?? [];
        }
        $this->categorySearch = '';
        $this->categoryOptions = [];
        $this->productSearch = '';
        $this->productOptions = [];
        
        $this->expandedProducts = [];
        $this->loadedProductDetails = [];
    }

    public function resetFilters(): void
    {
        $this->periodPreset = 'this_month';
        $this->updatedPeriodPreset('this_month');
        $this->categoryIds = [];
        $this->categoryLabels = [];
        $this->categoryMatchMode = 'any';
        $this->productIds = [];
        $this->productLabels = [];
        
        $this->categorySearch = '';
        $this->categoryOptions = [];
        $this->productSearch = '';
        $this->productOptions = [];
        
        $this->expandedProducts = [];
        $this->loadedProductDetails = [];
    }

    public function applyFilters(): void
    {
        $this->validate([
            'tanggalAwal' => 'required|date',
            'tanggalAkhir' => 'required|date|after_or_equal:tanggalAwal',
        ]);

        $this->appliedFilters = $this->exportFilters();
        $this->filterTriggered = true;
        
        $this->expandedProducts = [];
        $this->loadedProductDetails = [];
        
        $this->resetPage();
    }

    private function exportFilters(): array
    {
        return [
            'tanggalAwal' => $this->tanggalAwal,
            'tanggalAkhir' => $this->tanggalAkhir,
            'periodPreset' => $this->periodPreset,
            'categoryIds' => $this->categoryIds,
            'categoryLabels' => $this->categoryLabels,
            'categoryMatchMode' => $this->categoryMatchMode,
            'productIds' => $this->productIds,
            'productLabels' => $this->productLabels,
            'sortColumn' => $this->sortColumn,
            'sortDirection' => $this->sortDirection,
        ];
    }

    public function exportExcel(InventoryValuationReportQueryService $queryService)
    {
        abort_unless(auth()->user()->can('inventoryValuationReports.access'), 403);
        
        if (!$this->filterTriggered || empty($this->appliedFilters)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan filter data terlebih dahulu sebelum melakukan ekspor.']);
            return null;
        }

        $filterData = InventoryValuationReportFilterData::fromArray($this->appliedFilters);
        $result = $queryService->getReport($filterData, $this->settingId, 1, 1);

        $dateStart = Carbon::parse($filterData->tanggalAwal)->format('d-m-Y');
        $dateEnd = Carbon::parse($filterData->tanggalAkhir)->format('d-m-Y');
        $fileName = sprintf('nilai-persediaan-barang_%s_sd_%s.xlsx', $dateStart, $dateEnd);

        return Excel::download(
            new \App\Exports\InventoryValuationReportExport($result['allRows'], $result['totalValue'], $filterData, false),
            $fileName
        );
    }

    public function exportCsv(InventoryValuationReportQueryService $queryService)
    {
        abort_unless(auth()->user()->can('inventoryValuationReports.access'), 403);
        
        if (!$this->filterTriggered || empty($this->appliedFilters)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan filter data terlebih dahulu sebelum melakukan ekspor.']);
            return null;
        }

        $filterData = InventoryValuationReportFilterData::fromArray($this->appliedFilters);
        $result = $queryService->getReport($filterData, $this->settingId, 1, 1);

        $dateStart = Carbon::parse($filterData->tanggalAwal)->format('d-m-Y');
        $dateEnd = Carbon::parse($filterData->tanggalAkhir)->format('d-m-Y');
        $fileName = sprintf('nilai-persediaan-barang_%s_sd_%s.csv', $dateStart, $dateEnd);

        return Excel::download(
            new \App\Exports\InventoryValuationReportExport($result['allRows'], $result['totalValue'], $filterData, true),
            $fileName,
            \Maatwebsite\Excel\Excel::CSV
        );
    }
    
    public function toggleProduct($productId): void
    {
        if (in_array($productId, $this->expandedProducts)) {
            $this->expandedProducts = array_diff($this->expandedProducts, [$productId]);
        } else {
            $this->expandedProducts[] = $productId;
            
            if (!isset($this->loadedProductDetails[$productId])) {
                $queryService = app(InventoryValuationReportQueryService::class);
                $filterData = InventoryValuationReportFilterData::fromArray($this->appliedFilters);
                $this->loadedProductDetails[$productId] = $queryService->getProductDetail($filterData, $this->settingId, $productId);
            }
        }
    }

    public function render(InventoryValuationReportQueryService $queryService)
    {
        $paginator = null;
        $totalValue = 0.0;

        if ($this->filterTriggered) {
            $filterData = InventoryValuationReportFilterData::fromArray($this->appliedFilters);
            $result = $queryService->getSummary($filterData, $this->settingId, 15, $this->getPage());
            $paginator = $result['paginator'];
            $totalValue = $result['totalValue'];
        }

        return view('livewire.reports.inventory-valuation-report', [
            'paginator' => $paginator,
            'totalValue' => $totalValue,
        ]);
    }
}
