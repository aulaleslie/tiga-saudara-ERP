<?php

namespace App\Livewire\Reports;

use App\Exports\WarehouseStockQuantityReportExport;
use App\Services\Reports\WarehouseStockQuantityReportFilterData;
use App\Services\Reports\WarehouseStockQuantityReportQueryService;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Setting\Entities\Location;

class WarehouseStockQuantityReport extends Component
{
    use WithPagination;
    use HasReportSettingScope;

    public $asOfDate;
    public $periodPreset = '';
    public $warehouseIds = [];
    public $sortColumn = 'product_name';
    public $sortDirection = 'asc';
    
    public $filterTriggered = false;
    public $settingId;
    public $appliedFilters = [];
    
    public $availableWarehouses = [];
    public array $expandedBusinesses = [];

    protected $paginationTheme = 'bootstrap';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('stockMutationReports.access') ?? false, 403);
        $this->selectedSettingIds = $this->defaultSettingSelection();
        $this->asOfDate = now()->format('Y-m-d');
        
        $this->refreshAvailableWarehouses();
        $this->appliedFilters = $this->exportFilters();
    }

    public function refreshAvailableWarehouses(): void
    {
        $sanitizedSettingIds = $this->getSanitizedSettingIds($this->selectedSettingIds);
        $locations = Location::query()
            ->whereIn('setting_id', $sanitizedSettingIds)
            ->where('is_active', true)
            ->with('setting:id,company_name')
            ->orderBy('name')
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
    }

    public function updatedSelectedSettingIds(): void
    {
        $this->refreshAvailableWarehouses();
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
        $allowedFields = ['product_name', 'product_code', 'total_quantity'];

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

    public function cancelFilters(): void
    {
        if (!empty($this->appliedFilters)) {
            $this->asOfDate = $this->appliedFilters['asOfDate'] ?? $this->asOfDate;
            $this->periodPreset = $this->appliedFilters['periodPreset'] ?? '';
            $this->warehouseIds = $this->appliedFilters['warehouseIds'] ?? [];
            $this->refreshAvailableWarehouses();
            $validWarehouseIds = array_column($this->availableWarehouses, 'id');
            $this->warehouseIds = array_values(array_intersect($this->warehouseIds, $validWarehouseIds));
        }
    }

    public function resetFilters(): void
    {
        $this->asOfDate = now()->format('Y-m-d');
        $this->selectedSettingIds = $this->defaultSettingSelection();
        $this->refreshAvailableWarehouses();
        $this->periodPreset = '';
        $this->warehouseIds = [];
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
            'sortField' => $this->sortColumn,
            'sortDirection' => $this->sortDirection,
        ];
    }

    public function exportExcel(WarehouseStockQuantityReportQueryService $queryService)
    {
        abort_unless(auth()->user()?->can('stockMutationReports.access') ?? false, 403);
        
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
        $filterData = WarehouseStockQuantityReportFilterData::fromArray($appliedFilters);
        $result = $queryService->build($filterData);

        $dateFormatted = Carbon::parse($filterData->asOfDate)->format('d-m-Y');
        $fileName = sprintf('kuantitas-stok-gudang_%s.xlsx', $dateFormatted);

        return Excel::download(
            new WarehouseStockQuantityReportExport($result, $filterData, $this->availableWarehouses, false, $sanitizedSettingIds),
            $fileName
        );
    }

    public function exportCsv(WarehouseStockQuantityReportQueryService $queryService)
    {
        abort_unless(auth()->user()?->can('stockMutationReports.access') ?? false, 403);
        
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
        $filterData = WarehouseStockQuantityReportFilterData::fromArray($appliedFilters);
        $result = $queryService->build($filterData);

        $dateFormatted = Carbon::parse($filterData->asOfDate)->format('d-m-Y');
        $fileName = sprintf('kuantitas-stok-gudang_%s.csv', $dateFormatted);

        return Excel::download(
            new WarehouseStockQuantityReportExport($result, $filterData, $this->availableWarehouses, true, $sanitizedSettingIds),
            $fileName,
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    public function render(WarehouseStockQuantityReportQueryService $queryService)
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

        if ($this->filterTriggered && !empty($activeSettingIds)) {
            $appliedFilters = $this->appliedFilters;
            $appliedFilters['selectedSettingIds'] = $activeSettingIds;
            $filterData = WarehouseStockQuantityReportFilterData::fromArray($appliedFilters);
            $filterData->page = $this->getPage();
            $paginator = $queryService->paginate($filterData);
        }

        return view('livewire.reports.warehouse-stock-quantity-report', [
            'paginator' => $paginator,
            'availableSettings' => $availableSettings,
            'selectedSettings' => $selectedSettings,
            'sanitizedSettingIds' => $sanitizedSettingIds,
            'activeSettingIds' => $activeSettingIds,
            'isMultiBusiness' => $isMultiBusiness,
        ]);
    }
}
