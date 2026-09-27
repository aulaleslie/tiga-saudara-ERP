<?php

namespace App\Livewire\Reports;

use App\Services\Reports\WarehouseStockQuantityReportFilterData;
use App\Services\Reports\WarehouseStockQuantityReportQueryService;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Setting\Entities\Location;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\WarehouseStockQuantityReportExport;

class WarehouseStockQuantityReport extends Component
{
    use WithPagination;

    public $asOfDate;
    public $periodPreset = '';
    public $warehouseIds = [];
    public $sortColumn = 'product_name';
    public $sortDirection = 'asc';
    
    public $filterTriggered = false;
    public $settingId;
    public $appliedFilters = [];
    
    public $availableWarehouses = [];

    protected $paginationTheme = 'bootstrap';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('stockMutationReports.access'), 403);
        $this->settingId = session('setting_id');
        $this->asOfDate = now()->format('Y-m-d');
        
        $this->availableWarehouses = Location::where('setting_id', $this->settingId)
            ->get(['id', 'name'])
            ->toArray();
            
        $this->appliedFilters = $this->exportFilters();
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
        }
    }

    public function resetFilters(): void
    {
        $this->asOfDate = now()->format('Y-m-d');
        $this->periodPreset = '';
        $this->warehouseIds = [];
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
            'sortField' => $this->sortColumn,
            'sortDirection' => $this->sortDirection,
        ];
    }

    public function exportExcel(WarehouseStockQuantityReportQueryService $queryService)
    {
        abort_unless(auth()->user()->can('stockMutationReports.access'), 403);
        
        if (!$this->filterTriggered || empty($this->appliedFilters)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan filter data terlebih dahulu sebelum melakukan ekspor.']);
            return null;
        }

        $filterData = WarehouseStockQuantityReportFilterData::fromArray($this->appliedFilters);
        $result = $queryService->build($filterData);

        $dateFormatted = Carbon::parse($filterData->asOfDate)->format('d-m-Y');
        $fileName = sprintf('kuantitas-stok-gudang_%s.xlsx', $dateFormatted);

        return Excel::download(
            new WarehouseStockQuantityReportExport($result, $filterData, $this->availableWarehouses, false),
            $fileName
        );
    }

    public function exportCsv(WarehouseStockQuantityReportQueryService $queryService)
    {
        abort_unless(auth()->user()->can('stockMutationReports.access'), 403);
        
        if (!$this->filterTriggered || empty($this->appliedFilters)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan filter data terlebih dahulu sebelum melakukan ekspor.']);
            return null;
        }

        $filterData = WarehouseStockQuantityReportFilterData::fromArray($this->appliedFilters);
        $result = $queryService->build($filterData);

        $dateFormatted = Carbon::parse($filterData->asOfDate)->format('d-m-Y');
        $fileName = sprintf('kuantitas-stok-gudang_%s.csv', $dateFormatted);

        return Excel::download(
            new WarehouseStockQuantityReportExport($result, $filterData, $this->availableWarehouses, true),
            $fileName,
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    public function render(WarehouseStockQuantityReportQueryService $queryService)
    {
        $paginator = null;

        if ($this->filterTriggered) {
            $filterData = WarehouseStockQuantityReportFilterData::fromArray($this->appliedFilters);
            $filterData->page = $this->getPage();
            $paginator = $queryService->paginate($filterData);
        }

        return view('livewire.reports.warehouse-stock-quantity-report', [
            'paginator' => $paginator,
        ]);
    }
}
