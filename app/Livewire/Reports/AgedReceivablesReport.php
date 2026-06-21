<?php

namespace App\Livewire\Reports;

use Livewire\Component;
use Livewire\WithPagination;
use App\Services\Reports\AgedReceivablesReportFilterData;
use App\Services\Reports\AgedReceivablesReportQueryService;
use App\Services\Reports\AgedReceivablesReportValidator;
use App\Services\Reports\AgedReceivablesReportSnapshotService;
use App\Exports\AgedReceivablesReportExport;
use Maatwebsite\Excel\Facades\Excel;
use Modules\People\Entities\Customer;
use Spatie\Tags\Tag;
use Illuminate\Validation\ValidationException;

class AgedReceivablesReport extends Component
{
    use WithPagination;

    public $asOfDate;
    public $settingId;
    public $sortField = 'customer_name';
    public $sortDirection = 'asc';

    public $customerIds = [];
    public $tagIds = [];
    public $tagLogic = 'Salah satu';
    public $periodPreset = '';

    public $filterTriggered = false;
    
    public $appliedFilters = [];

    // Searchable filter states
    public $customerSearch = '';
    public $customerOptions = [];
    public $tagSearch = '';
    public $tagOptions = [];

    // Selected labels for display pills
    public $customerLabels = [];
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
            default      => null,
        };
    }

    // Select/Remove Methods
    public function selectCustomer(int $id, string $name): void
    {
        if (!in_array($id, $this->customerIds)) {
            $this->customerIds[] = $id;
            $this->customerLabels[$id] = $name;
        }
        $this->customerSearch = '';
        $this->customerOptions = [];
    }

    public function removeCustomer(int $id): void
    {
        $this->customerIds = array_values(array_diff($this->customerIds, [$id]));
        unset($this->customerLabels[$id]);
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
    public function updatedCustomerSearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->customerOptions = [];
            return;
        }
        $this->customerOptions = Customer::query()
            ->where('setting_id', $this->settingId)
            ->whereRaw('LOWER(customer_name) LIKE ?', ['%' . mb_strtolower($value) . '%'])
            ->limit(10)->get(['id', 'customer_name'])->toArray();
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
            $this->customerIds = $this->appliedFilters['customerIds'] ?? [];
            $this->customerLabels = $this->appliedFilters['customerLabels'] ?? [];
            $this->tagIds = $this->appliedFilters['tagIds'] ?? [];
            $this->tagLabels = $this->appliedFilters['tagLabels'] ?? [];
            $this->tagLogic = $this->appliedFilters['tagLogic'] ?? 'Salah satu';
            $this->sortField = $this->appliedFilters['sortField'] ?? 'customer_name';
            $this->sortDirection = $this->appliedFilters['sortDirection'] ?? 'asc';
        }
        $this->customerSearch = '';
        $this->customerOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    public function resetFilters(): void
    {
        $this->asOfDate = now()->format('Y-m-d');
        $this->periodPreset = '';
        $this->customerIds = [];
        $this->customerLabels = [];
        $this->tagIds = [];
        $this->tagLabels = [];
        $this->tagLogic = 'Salah satu';
        $this->sortField = 'customer_name';
        $this->sortDirection = 'asc';
        $this->customerSearch = '';
        $this->customerOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    private function exportFilters(): array
    {
        return [
            'asOfDate' => $this->asOfDate,
            'periodPreset' => $this->periodPreset,
            'customerIds' => $this->customerIds,
            'tagIds' => $this->tagIds,
            'tagLogic' => $this->tagLogic,
            'sortField' => $this->sortField,
            'sortDirection' => $this->sortDirection,
        ];
    }

    public function applyFilters(
        AgedReceivablesReportValidator $validator,
        AgedReceivablesReportQueryService $queryService,
        AgedReceivablesReportSnapshotService $snapshotService
    ) {
        $filterArray = $this->exportFilters();
        
        try {
            $validated = $validator->validate($filterArray);
            
            $this->appliedFilters = array_merge($validated, [
                'periodPreset' => $this->periodPreset,
                'customerLabels' => $this->customerLabels,
                'tagLabels' => $this->tagLabels,
            ]);
            
            $this->filterTriggered = true;

            $filter = AgedReceivablesReportFilterData::fromArray($this->appliedFilters);
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
        AgedReceivablesReportSnapshotService $snapshotService, 
        AgedReceivablesReportQueryService $queryService
    ) {
        $filter = AgedReceivablesReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

        $filename = "usia_piutang_{$filter->asOfDate}.xlsx";

        return Excel::download(new AgedReceivablesReportExport($query, $filter, false), $filename);
    }

    public function exportCsv(
        AgedReceivablesReportSnapshotService $snapshotService, 
        AgedReceivablesReportQueryService $queryService
    ) {
        $filter = AgedReceivablesReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

        $filename = "usia_piutang_{$filter->asOfDate}.csv";

        return Excel::download(new AgedReceivablesReportExport($query, $filter, true), $filename, \Maatwebsite\Excel\Excel::CSV);
    }

    public function exportPdf(
        AgedReceivablesReportSnapshotService $snapshotService, 
        AgedReceivablesReportQueryService $queryService
    ) {
        $filter = AgedReceivablesReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

        $filename = "usia_piutang_{$filter->asOfDate}.pdf";

        return Excel::download(new AgedReceivablesReportExport($query, $filter, false), $filename, \Maatwebsite\Excel\Excel::DOMPDF);
    }

    public function render(AgedReceivablesReportQueryService $queryService)
    {
        $sales = collect();
        $grandTotals = null;
        
        if ($this->filterTriggered) {
            $filter = new AgedReceivablesReportFilterData(
                asOfDate: $this->appliedFilters['asOfDate'] ?? $this->asOfDate,
                scopeSettingId: $this->settingId,
                customerIds: $this->appliedFilters['customerIds'] ?? [],
                tagIds: $this->appliedFilters['tagIds'] ?? [],
                tagLogic: $this->appliedFilters['tagLogic'] ?? 'Salah satu',
                sortField: $this->appliedFilters['sortField'] ?? 'customer_name',
                sortDirection: $this->appliedFilters['sortDirection'] ?? 'asc'
            );

            $query = $queryService->build($filter);
            $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

            $sales = $query->paginate(15);

            if ($sales->total() > 0) {
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

        return view('livewire.reports.aged-receivables-report', [
            'sales' => $sales,
            'grandTotals' => $grandTotals,
        ]);
    }
}
