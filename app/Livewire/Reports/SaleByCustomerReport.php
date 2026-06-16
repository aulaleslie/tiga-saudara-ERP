<?php

namespace App\Livewire\Reports;

use Livewire\Component;

use Livewire\WithPagination;
use App\Services\Reports\SaleByCustomerReportFilterData;
use App\Services\Reports\SaleByCustomerReportQueryService;

use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Spatie\Tags\Tag;

use App\Services\Reports\SaleByCustomerReportValidator;
use App\Services\Reports\SaleByCustomerReportSnapshotService;
use App\Exports\SaleByCustomerReportExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Validation\ValidationException;

class SaleByCustomerReport extends Component
{
    use WithPagination;

    public $startDate, $endDate;
    public $settingId;
    public $sortField = 'date';
    public $sortDirection = 'asc';

    public $customerIds = [];
    public $tagIds = [];
    public $tagLogic = 'Salah satu';
    public $categoryIds = [];
    public $categoryLogic = 'Salah satu';
    public $periodPreset = '';

    public $filterTriggered = false; // Determines if the report is actively rendering results
    
    public $appliedFilters = [];

    // Searchable filter states
    public $customerSearch = '';
    public $customerOptions = [];
    public $tagSearch = '';
    public $tagOptions = [];
    public $categorySearch = '';
    public $categoryOptions = [];

    // Selected labels for display pills
    public $customerLabels = [];
    public $tagLabels = [];
    public $categoryLabels = [];

    protected $paginationTheme = 'bootstrap';

    public function mount()
    {
        $this->settingId = session('setting_id');
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->endOfMonth()->format('Y-m-d');
        $this->appliedFilters = $this->exportFilters();
        $this->filterTriggered = false; // Require explicit Filter click to fetch data
    }

    public function updatedPeriodPreset($value): void
    {
        match ($value) {
            'today'      => [$this->startDate, $this->endDate] = [now()->format('Y-m-d'), now()->format('Y-m-d')],
            'this_week'  => [$this->startDate, $this->endDate] = [now()->startOfWeek()->format('Y-m-d'), now()->endOfWeek()->format('Y-m-d')],
            'this_month' => [$this->startDate, $this->endDate] = [now()->startOfMonth()->format('Y-m-d'), now()->endOfMonth()->format('Y-m-d')],
            'this_year'  => [$this->startDate, $this->endDate] = [now()->startOfYear()->format('Y-m-d'), now()->endOfYear()->format('Y-m-d')],
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
            ->limit(10)->get(['id', 'category_name'])->toArray();
    }

    public function cancelFilters(): void
    {
        if (!empty($this->appliedFilters)) {
            $this->startDate = $this->appliedFilters['startDate'] ?? $this->startDate;
            $this->endDate = $this->appliedFilters['endDate'] ?? $this->endDate;
            $this->periodPreset = $this->appliedFilters['periodPreset'] ?? '';
            $this->customerIds = $this->appliedFilters['customerIds'] ?? [];
            $this->customerLabels = $this->appliedFilters['customerLabels'] ?? [];
            $this->tagIds = $this->appliedFilters['tagIds'] ?? [];
            $this->tagLabels = $this->appliedFilters['tagLabels'] ?? [];
            $this->categoryIds = $this->appliedFilters['categoryIds'] ?? [];
            $this->categoryLabels = $this->appliedFilters['categoryLabels'] ?? [];
            $this->tagLogic = $this->appliedFilters['tagLogic'] ?? 'Salah satu';
            $this->categoryLogic = $this->appliedFilters['categoryLogic'] ?? 'Salah satu';
            $this->sortField = $this->appliedFilters['sortField'] ?? 'date';
            $this->sortDirection = $this->appliedFilters['sortDirection'] ?? 'asc';
        }
        $this->customerSearch = '';
        $this->customerOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
        $this->categorySearch = '';
        $this->categoryOptions = [];
    }

    public function resetFilters(): void
    {
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->endOfMonth()->format('Y-m-d');
        $this->periodPreset = '';
        $this->customerIds = [];
        $this->customerLabels = [];
        $this->tagIds = [];
        $this->tagLabels = [];
        $this->categoryIds = [];
        $this->categoryLabels = [];
        $this->tagLogic = 'Salah satu';
        $this->categoryLogic = 'Salah satu';
        $this->sortField = 'date';
        $this->sortDirection = 'asc';
        $this->customerSearch = '';
        $this->customerOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
        $this->categorySearch = '';
        $this->categoryOptions = [];
    }

    private function exportFilters(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'periodPreset' => $this->periodPreset,
            'customerIds' => $this->customerIds,
            'tagIds' => $this->tagIds,
            'tagLogic' => $this->tagLogic,
            'categoryIds' => $this->categoryIds,
            'categoryLogic' => $this->categoryLogic,
            'sortField' => $this->sortField,
            'sortDirection' => $this->sortDirection,
        ];
    }

    public function applyFilters(
        SaleByCustomerReportValidator $validator,
        SaleByCustomerReportQueryService $queryService,
        SaleByCustomerReportSnapshotService $snapshotService
    ) {
        $filterArray = $this->exportFilters();
        
        try {
            $validated = $validator->validate($filterArray);
            
            $this->appliedFilters = array_merge($validated, [
                'periodPreset' => $this->periodPreset,
                'customerLabels' => $this->customerLabels,
                'tagLabels' => $this->tagLabels,
                'categoryLabels' => $this->categoryLabels,
            ]);
            
            $this->filterTriggered = true;

            $filter = SaleByCustomerReportFilterData::fromArray($this->appliedFilters);
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
        SaleByCustomerReportSnapshotService $snapshotService, 
        SaleByCustomerReportQueryService $queryService
    ) {
        $filter = SaleByCustomerReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

        $filename = "sales_by_customer_{$filter->startDate}_{$filter->endDate}.xlsx";

        return Excel::download(new SaleByCustomerReportExport($query, $filter, false), $filename);
    }

    public function exportCsv(
        SaleByCustomerReportSnapshotService $snapshotService, 
        SaleByCustomerReportQueryService $queryService
    ) {
        $filter = SaleByCustomerReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

        $filename = "sales_by_customer_{$filter->startDate}_{$filter->endDate}.csv";

        return Excel::download(new SaleByCustomerReportExport($query, $filter, true), $filename, \Maatwebsite\Excel\Excel::CSV);
    }

    public function render(SaleByCustomerReportQueryService $queryService)
    {
        $sales = collect();
        
        if ($this->filterTriggered) {
            $filter = new SaleByCustomerReportFilterData(
                startDate: $this->appliedFilters['startDate'] ?? $this->startDate,
                endDate: $this->appliedFilters['endDate'] ?? $this->endDate,
                scopeSettingId: $this->settingId,
                customerIds: $this->appliedFilters['customerIds'] ?? [],
                tagIds: $this->appliedFilters['tagIds'] ?? [],
                tagLogic: $this->appliedFilters['tagLogic'] ?? 'Salah satu',
                categoryIds: $this->appliedFilters['categoryIds'] ?? [],
                categoryLogic: $this->appliedFilters['categoryLogic'] ?? 'Salah satu',
                sortField: $this->appliedFilters['sortField'] ?? 'date',
                sortDirection: $this->appliedFilters['sortDirection'] ?? 'asc'
            );

            $query = $queryService->build($filter);
            $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

            $baseQuery = clone $query;

            $sales = $query->paginate(15);

            // Compute running totals for the displayed rows
            $runningTotals = [];
            
            $previousPageLastCustomerId = null;
            
            if ($sales->currentPage() > 1) {
                $offset = ($sales->currentPage() - 1) * $sales->perPage();
                $previousQuery = clone $baseQuery;
                $previousQuery->setEagerLoads([]);
                $previousQuery->select('sales.customer_id', 'sale_details.sub_total', 'sale_details.product_tax_amount', 'sales.is_tax_included');
                $previousRows = $previousQuery->limit($offset)->get();
                
                if ($previousRows->isNotEmpty()) {
                    $previousPageLastCustomerId = $previousRows->last()->customer_id;
                }
                
                foreach ($previousRows as $row) {
                    $customerId = $row->customer_id;
                    if ($customerId) {
                        $tax = $row->is_tax_included ? 0 : ($row->product_tax_amount ?? 0);
                        $runningTotals[$customerId] = ($runningTotals[$customerId] ?? 0) + $row->sub_total + $tax;
                    }
                }
            }
            
            $sales->getCollection()->transform(function ($detail) use (&$runningTotals) {
                $customerId = $detail->sale->customer_id;
                if (!isset($runningTotals[$customerId])) {
                    $runningTotals[$customerId] = 0;
                }

                $detail->previous_running_total = $runningTotals[$customerId];

                $tax = $detail->sale->is_tax_included ? 0 : ($detail->product_tax_amount ?? 0);
                $runningTotals[$customerId] += $detail->sub_total + $tax;

                return $detail;
            });

            $grandTotal = 0;
            if ($sales->total() > 0) {
                $totalQuery = clone $baseQuery;
                $totalQuery->setEagerLoads([]);
                $totalQuery->select('sale_details.sub_total', 'sale_details.product_tax_amount', 'sales.is_tax_included');
                $grandTotal = $totalQuery->get()->sum(function($row) {
                    $tax = $row->is_tax_included ? 0 : ($row->product_tax_amount ?? 0);
                    return $row->sub_total + $tax;
                });
            }
            $nextPageFirstCustomerId = null;
            if ($sales->hasMorePages()) {
                $nextOffset = $sales->currentPage() * $sales->perPage();
                $nextQuery = clone $baseQuery;
                $nextQuery->setEagerLoads([]);
                $nextQuery->select('sales.customer_id');
                $nextRow = $nextQuery->skip($nextOffset)->first();
                if ($nextRow) {
                    $nextPageFirstCustomerId = $nextRow->customer_id;
                }
            }
        }

        return view('livewire.reports.sale-by-customer-report', [
            'sales' => $sales,
            'previousPageLastCustomerId' => $previousPageLastCustomerId ?? null,
            'nextPageFirstCustomerId' => $nextPageFirstCustomerId ?? null,
            'grandTotal' => $grandTotal ?? 0,
        ]);
    }
}
