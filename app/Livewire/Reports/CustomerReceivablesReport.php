<?php

namespace App\Livewire\Reports;

use Livewire\Component;
use Livewire\WithPagination;
use App\Services\Reports\CustomerReceivablesReportFilterData;
use App\Services\Reports\CustomerReceivablesReportQueryService;
use App\Services\Reports\CustomerReceivablesReportValidator;
use App\Services\Reports\CustomerReceivablesReportSnapshotService;
use App\Exports\CustomerReceivablesReportExport;
use Maatwebsite\Excel\Facades\Excel;
use Modules\People\Entities\Customer;
use Spatie\Tags\Tag;
use Illuminate\Validation\ValidationException;

class CustomerReceivablesReport extends Component
{
    use WithPagination;

    public $endDate;
    public $dueDateUntil;
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
        $this->endDate = now()->format('Y-m-d');
        $this->appliedFilters = $this->exportFilters();
        $this->filterTriggered = false;
    }

    public function updatedPeriodPreset($value): void
    {
        match ($value) {
            'today'      => $this->endDate = now()->format('Y-m-d'),
            'this_week'  => $this->endDate = now()->endOfWeek()->format('Y-m-d'),
            'this_month' => $this->endDate = now()->endOfMonth()->format('Y-m-d'),
            'this_year'  => $this->endDate = now()->endOfYear()->format('Y-m-d'),
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
            $this->endDate = $this->appliedFilters['endDate'] ?? $this->endDate;
            $this->dueDateUntil = $this->appliedFilters['dueDateUntil'] ?? $this->dueDateUntil;
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
        $this->endDate = now()->format('Y-m-d');
        $this->dueDateUntil = null;
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
            'endDate' => $this->endDate,
            'dueDateUntil' => $this->dueDateUntil,
            'periodPreset' => $this->periodPreset,
            'customerIds' => $this->customerIds,
            'tagIds' => $this->tagIds,
            'tagLogic' => $this->tagLogic,
            'sortField' => $this->sortField,
            'sortDirection' => $this->sortDirection,
        ];
    }

    public function applyFilters(
        CustomerReceivablesReportValidator $validator,
        CustomerReceivablesReportQueryService $queryService,
        CustomerReceivablesReportSnapshotService $snapshotService
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

            $filter = CustomerReceivablesReportFilterData::fromArray($this->appliedFilters);
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
        CustomerReceivablesReportSnapshotService $snapshotService, 
        CustomerReceivablesReportQueryService $queryService
    ) {
        $filter = CustomerReceivablesReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

        $filename = "piutang_pelanggan_{$filter->endDate}.xlsx";

        return Excel::download(new CustomerReceivablesReportExport($query, $filter, false), $filename);
    }

    public function exportCsv(
        CustomerReceivablesReportSnapshotService $snapshotService, 
        CustomerReceivablesReportQueryService $queryService
    ) {
        $filter = CustomerReceivablesReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

        $filename = "piutang_pelanggan_{$filter->endDate}.csv";

        return Excel::download(new CustomerReceivablesReportExport($query, $filter, true), $filename, \Maatwebsite\Excel\Excel::CSV);
    }

    public function exportPdf(
        CustomerReceivablesReportSnapshotService $snapshotService, 
        CustomerReceivablesReportQueryService $queryService
    ) {
        $filter = CustomerReceivablesReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

        $filename = "piutang_pelanggan_{$filter->endDate}.pdf";

        return Excel::download(new CustomerReceivablesReportExport($query, $filter, false), $filename, \Maatwebsite\Excel\Excel::DOMPDF);
    }

    public function render(CustomerReceivablesReportQueryService $queryService)
    {
        $sales = collect();
        
        if ($this->filterTriggered) {
            $filter = new CustomerReceivablesReportFilterData(
                endDate: $this->appliedFilters['endDate'] ?? $this->endDate,
                scopeSettingId: $this->settingId,
                dueDateUntil: $this->appliedFilters['dueDateUntil'] ?? null,
                customerIds: $this->appliedFilters['customerIds'] ?? [],
                tagIds: $this->appliedFilters['tagIds'] ?? [],
                tagLogic: $this->appliedFilters['tagLogic'] ?? 'Salah satu',
                sortField: $this->appliedFilters['sortField'] ?? 'customer_name',
                sortDirection: $this->appliedFilters['sortDirection'] ?? 'asc'
            );

            $query = $queryService->build($filter);
            $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

            $baseQuery = clone $query;

            $sales = $query->paginate(15);

            // Compute running totals per customer
            $runningTotals = [];
            $runningJumlahTotals = [];
            $previousPageLastCustomerId = null;
            $previousRows = collect();
            
            if ($sales->currentPage() > 1) {
                $offset = ($sales->currentPage() - 1) * $sales->perPage();
                $previousQuery = clone $baseQuery;
                $previousQuery->setEagerLoads([]);
                
                // For sub_total calculation from previous pages
                $previousRows = $previousQuery->limit($offset)->get();
                
                if ($previousRows->isNotEmpty()) {
                    $previousPageLastCustomerId = $previousRows->last()->customer_id;
                }
                
                foreach ($previousRows as $row) {
                    $customerId = $row->customer_id;
                    if ($customerId) {
                        $runningTotals[$customerId] = ($runningTotals[$customerId] ?? 0) + $row->sisa_piutang;
                        $runningJumlahTotals[$customerId] = ($runningJumlahTotals[$customerId] ?? 0) + $row->total_amount;
                    }
                }
            }
            
            $currentPageCustomerIds = $sales->pluck('customer_id')->unique()->toArray();
            $currentPageTotalSales = [];
            if (!empty($currentPageCustomerIds)) {
                $salesCountQuery = clone $baseQuery;
                $salesCountQuery->setEagerLoads([]);
                $salesCountQuery->getQuery()->columns = [];
                $salesCountQuery->getQuery()->orders = [];

                $currentPageTotalSales = $salesCountQuery->select('sales.customer_id', \Illuminate\Support\Facades\DB::raw('count(*) as total'))
                    ->whereIn('sales.customer_id', $currentPageCustomerIds)
                    ->groupBy('sales.customer_id')
                    ->pluck('total', 'customer_id')
                    ->toArray();
            }

            $currentCustomerRowsProcessed = [];
            foreach ($currentPageCustomerIds as $cId) {
                if (isset($runningTotals[$cId])) {
                    $currentCustomerRowsProcessed[$cId] = $previousRows->where('customer_id', $cId)->count();
                } else {
                    $currentCustomerRowsProcessed[$cId] = 0;
                }
            }

            $sales->getCollection()->transform(function ($sale) use (&$runningTotals, &$runningJumlahTotals, &$currentCustomerRowsProcessed, $currentPageTotalSales) {
                $customerId = $sale->customer_id;
                if (!isset($runningTotals[$customerId])) {
                    $runningTotals[$customerId] = 0;
                    $runningJumlahTotals[$customerId] = 0;
                }

                $sale->previous_running_total = $runningTotals[$customerId];
                $sale->previous_running_jumlah = $runningJumlahTotals[$customerId];
                $currentCustomerRowsProcessed[$customerId]++;
                $isLastSaleInCustomer = $currentCustomerRowsProcessed[$customerId] == ($currentPageTotalSales[$customerId] ?? 1);
                
                $sale->is_last_detail = $isLastSaleInCustomer;
                $runningTotals[$customerId] += $sale->sisa_piutang;
                $runningJumlahTotals[$customerId] += $sale->total_amount;

                return $sale;
            });

            $grandTotal = 0;
            $grandTotalJumlah = 0;
            if ($sales->total() > 0) {
                $totalQuery = clone $baseQuery;
                $totalQuery->setEagerLoads([]);
                $totals = \Illuminate\Support\Facades\DB::table(\Illuminate\Support\Facades\DB::raw("({$totalQuery->toSql()}) as sub"))
                    ->mergeBindings($totalQuery->getQuery())
                    ->select(
                        \Illuminate\Support\Facades\DB::raw('SUM(sub.sisa_piutang) as total_sisa'),
                        \Illuminate\Support\Facades\DB::raw('SUM(sub.total_amount) as total_jumlah')
                    )
                    ->first();
                $grandTotal = round($totals->total_sisa ?? 0, 2);
                $grandTotalJumlah = round($totals->total_jumlah ?? 0, 2);
            }
            
            $nextPageFirstCustomerId = null;
            if ($sales->hasMorePages()) {
                $nextOffset = $sales->currentPage() * $sales->perPage();
                $nextQuery = clone $baseQuery;
                $nextQuery->setEagerLoads([]);
                $nextRow = $nextQuery->skip($nextOffset)->first();
                if ($nextRow) {
                    $nextPageFirstCustomerId = $nextRow->customer_id;
                }
            }
        }

        return view('livewire.reports.customer-receivables-report', [
            'sales' => $sales,
            'previousPageLastCustomerId' => $previousPageLastCustomerId ?? null,
            'nextPageFirstCustomerId' => $nextPageFirstCustomerId ?? null,
            'grandTotal' => $grandTotal ?? 0,
            'grandTotalJumlah' => $grandTotalJumlah ?? 0,
        ]);
    }
}
