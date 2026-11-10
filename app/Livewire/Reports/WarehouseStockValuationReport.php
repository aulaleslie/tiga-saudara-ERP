<?php

namespace App\Livewire\Reports;

use App\Exports\WarehouseStockValuationReportExport;
use App\Services\Reports\WarehouseStockValuationReportFilterData;
use App\Services\Reports\WarehouseStockValuationReportQueryService;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Setting\Entities\Location;
use Modules\Product\Entities\Category;
use Maatwebsite\Excel\Facades\Excel;

class WarehouseStockValuationReport extends Component
{
    use WithPagination;
    use HasReportSettingScope;

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
    public array $expandedBusinesses = [];

    protected $paginationTheme = 'bootstrap';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('inventoryValuationReports.access') ?? false, 403);
        $this->selectedSettingIds = $this->defaultSettingSelection();
        $this->asOfDate = now()->format('Y-m-d');
        
        $this->refreshAvailableOptions();
        $this->appliedFilters = $this->exportFilters();
    }

    public function refreshAvailableOptions(): void
    {
        $sanitizedSettingIds = $this->getSanitizedSettingIds($this->selectedSettingIds);
        $locations = Location::query()
            ->whereIn('setting_id', $sanitizedSettingIds)
            ->where('is_active', true)
            ->with('setting:id,company_name')
            ->orderBy('name', $this->warehouseNameOrder)
            ->get();

        $isMulti = count($sanitizedSettingIds) > 1;
        $this->availableWarehouses = $locations->map(function ($loc) use ($isMulti) {
            return [
                'id' => (int) $loc->id,
                'name' => $isMulti ? "{$loc->name} — {$loc->setting?->company_name}" : (string) $loc->name,
                'raw_name' => (string) $loc->name,
                'setting_id' => (int) $loc->setting_id,
            ];
        })->toArray();

        $this->availableCategories = Category::query()
            ->orderBy('category_name')
            ->get(['id', 'category_name'])
            ->toArray();
    }

    public function updatedSelectedSettingIds(): void
    {
        $this->refreshAvailableOptions();
        $validWarehouseIds = array_column($this->availableWarehouses, 'id');
        $this->warehouseIds = array_values(array_intersect($this->warehouseIds, $validWarehouseIds));
        $this->resetPage();
    }

    public function toggleBusiness(int $settingId): void
    {
        if (in_array($settingId, $this->expandedBusinesses)) {
            $this->expandedBusinesses = array_values(array_diff($this->expandedBusinesses, [$settingId]));
        } else {
            $this->expandedBusinesses[] = $settingId;
        }
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
        $this->refreshAvailableOptions();
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
            $validWarehouseIds = array_column($this->availableWarehouses, 'id');
            $this->warehouseIds = array_values(array_intersect($this->warehouseIds, $validWarehouseIds));
        }
    }

    public function resetFilters(): void
    {
        $this->asOfDate = now()->format('Y-m-d');
        $this->selectedSettingIds = $this->defaultSettingSelection();
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

        $this->appliedFilters = $this->exportFilters($this->getSanitizedSettingIds($this->selectedSettingIds));
        $this->filterTriggered = true;
        $this->resetPage();
    }

    private function exportFilters(?array $settingIds = null): array
    {
        return [
            'asOfDate' => $this->asOfDate,
            'selectedSettingIds' => $settingIds ?? $this->activeSettingIds(),
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
        abort_unless(auth()->user()?->can('inventoryValuationReports.access') ?? false, 403);
        
        if (!$this->filterTriggered || empty($this->appliedFilters)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan filter data terlebih dahulu sebelum melakukan ekspor.']);
            return null;
        }

        $sanitizedSettingIds = $this->activeSettingIds();
        if (empty($sanitizedSettingIds)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Pilih minimal satu bisnis.']);
            return null;
        }

        $appliedFilters = $this->appliedFilters;
        $appliedFilters['selectedSettingIds'] = $sanitizedSettingIds;
        $filterData = WarehouseStockValuationReportFilterData::fromArray($appliedFilters);
        $result = $queryService->build($filterData);

        $dateFormatted = Carbon::parse($filterData->asOfDate)->format('d-m-Y');
        $fileName = sprintf('nilai-stok-gudang_%s.xlsx', $dateFormatted);

        return Excel::download(
            new WarehouseStockValuationReportExport($result, $filterData, $this->availableWarehouses, false, $sanitizedSettingIds),
            $fileName
        );
    }

    public function exportCsv(WarehouseStockValuationReportQueryService $queryService)
    {
        abort_unless(auth()->user()?->can('inventoryValuationReports.access') ?? false, 403);
        
        if (!$this->filterTriggered || empty($this->appliedFilters)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan filter data terlebih dahulu sebelum melakukan ekspor.']);
            return null;
        }

        $sanitizedSettingIds = $this->activeSettingIds();
        if (empty($sanitizedSettingIds)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Pilih minimal satu bisnis.']);
            return null;
        }

        $appliedFilters = $this->appliedFilters;
        $appliedFilters['selectedSettingIds'] = $sanitizedSettingIds;
        $filterData = WarehouseStockValuationReportFilterData::fromArray($appliedFilters);
        $result = $queryService->build($filterData);

        $dateFormatted = Carbon::parse($filterData->asOfDate)->format('d-m-Y');
        $fileName = sprintf('nilai-stok-gudang_%s.csv', $dateFormatted);

        return Excel::download(
            new WarehouseStockValuationReportExport($result, $filterData, $this->availableWarehouses, true, $sanitizedSettingIds),
            $fileName,
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    public function render(WarehouseStockValuationReportQueryService $queryService)
    {
        $paginator = null;
        $availableSettings = $this->getAvailableSettings();
        $sanitizedSettingIds = $this->getSanitizedSettingIds($this->selectedSettingIds);
        $activeSettingIds = $this->activeSettingIds();
        $isMultiBusiness = $this->isMultiBusiness($activeSettingIds);
        $selectedSettings = collect($availableSettings)
            ->whereIn('id', $activeSettingIds)
            ->map(fn($s) => (object) $s)
            ->values();

        $businessTotals = [];
        foreach ($activeSettingIds as $sId) {
            $businessTotals[$sId] = 0.0;
        }

        if ($this->filterTriggered && !empty($activeSettingIds)) {
            $appliedFilters = $this->appliedFilters;
            $appliedFilters['selectedSettingIds'] = $activeSettingIds;
            $filterData = WarehouseStockValuationReportFilterData::fromArray($appliedFilters);
            $filterData->page = $this->getPage();
            $paginator = $queryService->paginate($filterData, $this->grandTotalValue);

            if ($isMultiBusiness) {
                $all = $queryService->build($filterData);
                foreach ($activeSettingIds as $sId) {
                    $businessTotals[$sId] = (float) $all->sum("val_business_{$sId}");
                }
            }
        }

        $displayWarehouses = $this->availableWarehouses;
        if (!empty($this->appliedFilters['warehouseIds'])) {
            $displayWarehouses = array_filter($this->availableWarehouses, fn($w) => in_array($w['id'], $this->appliedFilters['warehouseIds']));
        }

        return view('livewire.reports.warehouse-stock-valuation-report', [
            'paginator' => $paginator,
            'displayWarehouses' => $displayWarehouses,
            'availableSettings' => $availableSettings,
            'selectedSettings' => $selectedSettings,
            'sanitizedSettingIds' => $sanitizedSettingIds,
            'activeSettingIds' => $activeSettingIds,
            'isMultiBusiness' => $isMultiBusiness,
            'businessTotals' => $businessTotals,
        ]);
    }
}
