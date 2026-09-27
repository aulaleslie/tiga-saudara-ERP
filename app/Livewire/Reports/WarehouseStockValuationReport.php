<?php

namespace App\Livewire\Reports;

use App\Services\Reports\WarehouseStockValuationReportFilterData;
use App\Services\Reports\WarehouseStockValuationReportQueryService;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Setting\Entities\Location;
use Modules\Product\Entities\Category;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\WarehouseStockValuationReportExport;

class WarehouseStockValuationReport extends Component
{
    use WithPagination;

    public $asOfDate;
    public $periodPreset = '';
    public $warehouseIds = [];
    public $productStockStatus = '';
    public $categoryIds = [];
    public $categoryMatchMode = 'any';
    public $warehouseNameOrder = 'asc';
    
    public $sortColumn = 'product_name';
    public $sortDirection = 'asc';
    
    public $filterTriggered = false;
    public $settingId;
    public $appliedFilters = [];
    public $grandTotalValue = 0;
    
    public $availableWarehouses = [];
    public $availableCategories = [];

    protected $paginationTheme = 'bootstrap';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('inventoryValuationReports.access'), 403);
        $this->settingId = session('setting_id');
        $this->asOfDate = now()->format('Y-m-d');
        
        $this->availableWarehouses = Location::where('setting_id', $this->settingId)
            ->orderBy('name', $this->warehouseNameOrder)
            ->get(['id', 'name'])
            ->toArray();
            
        $this->availableCategories = Category::where('setting_id', $this->settingId)
            ->get(['id', 'category_name'])
            ->toArray();
            
        $this->appliedFilters = $this->exportFilters();
    }

    public function sortBy($field): void
    {
        $allowedFields = ['product_name', 'product_code', 'qty', 'stock_value', 'average_cost'];

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
            $this->appliedFilters['sortField'] = $this->sortColumn;
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
            'Hari ini'      => $this->asOfDate = now()->format('Y-m-d'),
            'Pekan ini'  => $this->asOfDate = now()->endOfWeek()->format('Y-m-d'),
            'Bulan ini' => $this->asOfDate = now()->endOfMonth()->format('Y-m-d'),
            'Tahun ini'  => $this->asOfDate = now()->endOfYear()->format('Y-m-d'),
            'Kemarin' => $this->asOfDate = now()->subDay()->format('Y-m-d'),
            'Pekan lalu' => $this->asOfDate = now()->subWeek()->endOfWeek()->format('Y-m-d'),
            'Bulan lalu' => $this->asOfDate = now()->subMonth()->endOfMonth()->format('Y-m-d'),
            'Kuartal ini' => $this->asOfDate = now()->endOfQuarter()->format('Y-m-d'),
            'Kuartal lalu' => $this->asOfDate = now()->subQuarter()->endOfQuarter()->format('Y-m-d'),
            'Tahun lalu' => $this->asOfDate = now()->subYear()->endOfYear()->format('Y-m-d'),
            default      => null,
        };
    }

    public function updatedWarehouseNameOrder(): void
    {
        $this->availableWarehouses = Location::where('setting_id', $this->settingId)
            ->orderBy('name', $this->warehouseNameOrder)
            ->get(['id', 'name'])
            ->toArray();
    }

    public function cancelFilters(): void
    {
        if (!empty($this->appliedFilters)) {
            $this->asOfDate = $this->appliedFilters['asOfDate'] ?? $this->asOfDate;
            $this->periodPreset = $this->appliedFilters['periodPreset'] ?? '';
            $this->warehouseIds = $this->appliedFilters['warehouseIds'] ?? [];
            $this->productStockStatus = $this->appliedFilters['productStockStatus'] ?? '';
            $this->categoryIds = $this->appliedFilters['categoryIds'] ?? [];
            $this->categoryMatchMode = $this->appliedFilters['categoryMatchMode'] ?? 'any';
            $this->warehouseNameOrder = $this->appliedFilters['warehouseNameOrder'] ?? 'asc';
            
            $this->updatedWarehouseNameOrder();
        }
    }

    public function resetFilters(): void
    {
        $this->asOfDate = now()->format('Y-m-d');
        $this->periodPreset = '';
        $this->warehouseIds = [];
        $this->productStockStatus = '';
        $this->categoryIds = [];
        $this->categoryMatchMode = 'any';
        $this->warehouseNameOrder = 'asc';
        
        $this->updatedWarehouseNameOrder();
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
            'warehouseIds' => $this->warehouseIds,
            'productStockStatus' => $this->productStockStatus,
            'categoryIds' => $this->categoryIds,
            'categoryMatchMode' => $this->categoryMatchMode,
            'warehouseNameOrder' => $this->warehouseNameOrder,
            'sortField' => $this->sortColumn,
            'sortDirection' => $this->sortDirection,
        ];
    }

    public function exportExcel(WarehouseStockValuationReportQueryService $queryService)
    {
        abort_unless(auth()->user()->can('inventoryValuationReports.access'), 403);
        
        if (!$this->filterTriggered || empty($this->appliedFilters)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan filter data terlebih dahulu sebelum melakukan ekspor.']);
            return null;
        }

        $filterData = WarehouseStockValuationReportFilterData::fromArray($this->appliedFilters);
        $result = $queryService->build($filterData);

        $dateFormatted = Carbon::parse($filterData->asOfDate)->format('d-m-Y');
        $fileName = sprintf('nilai-stok-gudang_%s.xlsx', $dateFormatted);

        return Excel::download(
            new WarehouseStockValuationReportExport($result, $filterData, $this->availableWarehouses, false),
            $fileName
        );
    }

    public function exportCsv(WarehouseStockValuationReportQueryService $queryService)
    {
        abort_unless(auth()->user()->can('inventoryValuationReports.access'), 403);
        
        if (!$this->filterTriggered || empty($this->appliedFilters)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan filter data terlebih dahulu sebelum melakukan ekspor.']);
            return null;
        }

        $filterData = WarehouseStockValuationReportFilterData::fromArray($this->appliedFilters);
        $result = $queryService->build($filterData);

        $dateFormatted = Carbon::parse($filterData->asOfDate)->format('d-m-Y');
        $fileName = sprintf('nilai-stok-gudang_%s.csv', $dateFormatted);

        return Excel::download(
            new WarehouseStockValuationReportExport($result, $filterData, $this->availableWarehouses, true),
            $fileName,
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    public function render(WarehouseStockValuationReportQueryService $queryService)
    {
        $paginator = null;

        if ($this->filterTriggered) {
            $filterData = WarehouseStockValuationReportFilterData::fromArray($this->appliedFilters);
            $filterData->page = $this->getPage();
            $paginator = $queryService->paginate($filterData, $this->grandTotalValue);
        }

        // Apply ordering to display available warehouses in the table header
        $displayWarehouses = $this->availableWarehouses;
        if (!empty($this->appliedFilters['warehouseIds'])) {
            $displayWarehouses = array_filter($this->availableWarehouses, fn($w) => in_array($w['id'], $this->appliedFilters['warehouseIds']));
        }

        return view('livewire.reports.warehouse-stock-valuation-report', [
            'paginator' => $paginator,
            'displayWarehouses' => $displayWarehouses
        ]);
    }
}
