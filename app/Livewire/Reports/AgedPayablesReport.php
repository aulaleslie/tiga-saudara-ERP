<?php

namespace App\Livewire\Reports;

use Livewire\Component;
use Livewire\WithPagination;
use App\Services\Reports\AgedPayablesReportFilterData;
use App\Services\Reports\AgedPayablesReportQueryService;
use App\Services\Reports\AgedPayablesReportValidator;
use App\Services\Reports\AgedPayablesReportSnapshotService;
use App\Exports\AgedPayablesReportExport;
use Maatwebsite\Excel\Facades\Excel;
use Modules\People\Entities\Supplier;
use Spatie\Tags\Tag;
use Illuminate\Validation\ValidationException;

class AgedPayablesReport extends Component
{
    use WithPagination;

    public $asOfDate;
    public $settingId;
    public $sortField = 'supplier_name';
    public $sortDirection = 'asc';

    public $agingBasis = 'Tanggal Transaksi';
    public $supplierIds = [];
    public $tagIds = [];
    public $tagLogic = 'Salah satu';
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

    public function mount()
    {
        $this->settingId = session('setting_id');
        $this->asOfDate = now()->format('Y-m-d');
        $this->appliedFilters = $this->exportFilters();
        $this->filterTriggered = false;
    }

    public function updatedPeriodPreset($value): void
    {
        match ($value) {
            'today'      => $this->asOfDate = now()->format('Y-m-d'),
            'this_week'  => $this->asOfDate = now()->endOfWeek()->format('Y-m-d'),
            'this_month' => $this->asOfDate = now()->endOfMonth()->format('Y-m-d'),
            'this_year'  => $this->asOfDate = now()->endOfYear()->format('Y-m-d'),
            'yesterday'  => $this->asOfDate = now()->subDay()->format('Y-m-d'),
            'last_week'  => $this->asOfDate = now()->subWeek()->endOfWeek()->format('Y-m-d'),
            'last_month' => $this->asOfDate = now()->subMonth()->endOfMonth()->format('Y-m-d'),
            'last_year'  => $this->asOfDate = now()->subYear()->endOfYear()->format('Y-m-d'),
            default      => null,
        };
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

    public function cancelFilters(): void
    {
        if (!empty($this->appliedFilters)) {
            $this->asOfDate = $this->appliedFilters['asOfDate'] ?? $this->asOfDate;
            $this->periodPreset = $this->appliedFilters['periodPreset'] ?? '';
            $this->agingBasis = $this->appliedFilters['agingBasis'] ?? 'Tanggal Transaksi';
            $this->supplierIds = $this->appliedFilters['supplierIds'] ?? [];
            $this->supplierLabels = $this->appliedFilters['supplierLabels'] ?? [];
            $this->tagIds = $this->appliedFilters['tagIds'] ?? [];
            $this->tagLabels = $this->appliedFilters['tagLabels'] ?? [];
            $this->tagLogic = $this->appliedFilters['tagLogic'] ?? 'Salah satu';
            $this->sortField = $this->appliedFilters['sortField'] ?? 'supplier_name';
            $this->sortDirection = $this->appliedFilters['sortDirection'] ?? 'asc';
        }
        $this->supplierSearch = '';
        $this->supplierOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    public function resetFilters(): void
    {
        $this->asOfDate = now()->format('Y-m-d');
        $this->periodPreset = '';
        $this->agingBasis = 'Tanggal Transaksi';
        $this->supplierIds = [];
        $this->supplierLabels = [];
        $this->tagIds = [];
        $this->tagLabels = [];
        $this->tagLogic = 'Salah satu';
        $this->sortField = 'supplier_name';
        $this->sortDirection = 'asc';
        $this->supplierSearch = '';
        $this->supplierOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    private function exportFilters(): array
    {
        return [
            'asOfDate' => $this->asOfDate,
            'periodPreset' => $this->periodPreset,
            'agingBasis' => $this->agingBasis,
            'supplierIds' => $this->supplierIds,
            'tagIds' => $this->tagIds,
            'tagLogic' => $this->tagLogic,
            'sortField' => $this->sortField,
            'sortDirection' => $this->sortDirection,
        ];
    }

    public function applyFilters(
        AgedPayablesReportValidator $validator,
        AgedPayablesReportQueryService $queryService,
        AgedPayablesReportSnapshotService $snapshotService
    ) {
        $filterArray = $this->exportFilters();
        
        try {
            $validated = $validator->validate($filterArray);
            
            $this->appliedFilters = array_merge($validated, [
                'periodPreset' => $this->periodPreset,
                'supplierLabels' => $this->supplierLabels,
                'tagLabels' => $this->tagLabels,
            ]);
            
            $this->filterTriggered = true;

            $filter = AgedPayablesReportFilterData::fromArray($this->appliedFilters);
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
        AgedPayablesReportSnapshotService $snapshotService, 
        AgedPayablesReportQueryService $queryService
    ) {
        $filter = AgedPayablesReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

        $filename = "usia_utang_{$filter->asOfDate}.xlsx";

        return Excel::download(new AgedPayablesReportExport($query, $filter, false), $filename);
    }

    public function exportCsv(
        AgedPayablesReportSnapshotService $snapshotService, 
        AgedPayablesReportQueryService $queryService
    ) {
        $filter = AgedPayablesReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

        $filename = "usia_utang_{$filter->asOfDate}.csv";

        return Excel::download(new AgedPayablesReportExport($query, $filter, true), $filename, \Maatwebsite\Excel\Excel::CSV);
    }

    public function exportPdf(
        AgedPayablesReportSnapshotService $snapshotService, 
        AgedPayablesReportQueryService $queryService
    ) {
        $filter = AgedPayablesReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

        $filename = "usia_utang_{$filter->asOfDate}.pdf";

        return Excel::download(new AgedPayablesReportExport($query, $filter, false), $filename, \Maatwebsite\Excel\Excel::DOMPDF);
    }

    public function render(AgedPayablesReportQueryService $queryService)
    {
        $purchases = collect();
        $grandTotals = null;
        
        if ($this->filterTriggered) {
            $filter = new AgedPayablesReportFilterData(
                asOfDate: $this->appliedFilters['asOfDate'] ?? $this->asOfDate,
                agingBasis: $this->appliedFilters['agingBasis'] ?? 'Tanggal Transaksi',
                scopeSettingId: $this->settingId,
                supplierIds: $this->appliedFilters['supplierIds'] ?? [],
                tagIds: $this->appliedFilters['tagIds'] ?? [],
                tagLogic: $this->appliedFilters['tagLogic'] ?? 'Salah satu',
                sortField: $this->appliedFilters['sortField'] ?? 'supplier_name',
                sortDirection: $this->appliedFilters['sortDirection'] ?? 'asc'
            );

            $query = $queryService->build($filter);
            $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

            $purchases = $query->paginate(15);

            if ($purchases->total() > 0) {
                $totalQuery = clone $query;
                $totalQuery->setEagerLoads([]);
                $totalQuery->getQuery()->orders = [];
                
                $totals = \Illuminate\Support\Facades\DB::query()
                    ->fromSub($totalQuery, 'sub')
                    ->select(
                        \Illuminate\Support\Facades\DB::raw('SUM(sub.total_balance) as grand_total'),
                        \Illuminate\Support\Facades\DB::raw('SUM(sub.bucket_1) as grand_bucket_1'),
                        \Illuminate\Support\Facades\DB::raw('SUM(sub.bucket_2) as grand_bucket_2'),
                        \Illuminate\Support\Facades\DB::raw('SUM(sub.bucket_3) as grand_bucket_3'),
                        \Illuminate\Support\Facades\DB::raw('SUM(sub.bucket_4) as grand_bucket_4')
                    )
                    ->first();
                
                $grandTotals = [
                    'Total' => $totals->grand_total ?? 0,
                    '1 - 30 Hari' => $totals->grand_bucket_1 ?? 0,
                    '31 - 60 Hari' => $totals->grand_bucket_2 ?? 0,
                    '61 - 90 Hari' => $totals->grand_bucket_3 ?? 0,
                    '> 90 Hari' => $totals->grand_bucket_4 ?? 0,
                ];
            }
        }

        return view('livewire.reports.aged-payables-report', [
            'purchases' => $purchases,
            'grandTotals' => $grandTotals,
        ]);
    }
}
