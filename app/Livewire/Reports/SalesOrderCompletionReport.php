<?php

namespace App\Livewire\Reports;

use Livewire\Component;
use Livewire\WithPagination;
use App\Services\Reports\SalesOrderCompletionReportFilterData;
use App\Services\Reports\SalesOrderCompletionReportQueryService;
use Modules\People\Entities\Customer;
use Spatie\Tags\Tag;
use App\Services\Reports\SalesOrderCompletionReportValidator;
use App\Services\Reports\SalesOrderCompletionReportSnapshotService;
use App\Exports\SalesOrderCompletionReportExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Validation\ValidationException;

class SalesOrderCompletionReport extends Component
{
    use WithPagination;

    public $startDate, $endDate;
    public $settingId;
    public $sortField = 'date';
    public $sortDirection = 'desc';

    public $customerIds = [];
    public $tagIds = [];
    public $tagLogic = 'any';
    public $sourceStage = 'Pemesanan';
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
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->endOfMonth()->format('Y-m-d');
        $this->sourceStage = 'Pemesanan';
        $this->appliedFilters = $this->exportFilters();
        $this->filterTriggered = false;
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

    public function toggleSourceStage(string $stage): void
    {
        $this->sourceStage = $stage;
    }

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

    public function sortBy($field): void
    {
        $allowedFields = ['date', 'reference', 'customer_name', 'total_amount'];

        if (!in_array($field, $allowedFields)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function sortIcon($field): string
    {
        if ($field !== $this->sortField) return '';
        return $this->sortDirection === 'asc'
            ? '<i class="bi bi-caret-up-fill text-primary ms-1"></i>'
            : '<i class="bi bi-caret-down-fill text-primary ms-1"></i>';
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
            $this->tagLogic = $this->appliedFilters['tagLogic'] ?? 'any';
            $this->sourceStage = $this->appliedFilters['sourceStage'] ?? 'Pemesanan';
            $this->sortField = $this->appliedFilters['sortField'] ?? 'date';
            $this->sortDirection = $this->appliedFilters['sortDirection'] ?? 'desc';
        }
        $this->customerSearch = '';
        $this->customerOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
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
        $this->tagLogic = 'any';
        $this->sourceStage = 'Pemesanan';
        $this->sortField = 'date';
        $this->sortDirection = 'desc';
        $this->customerSearch = '';
        $this->customerOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
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
            'sourceStage' => $this->sourceStage,
            'sortField' => $this->sortField,
            'sortDirection' => $this->sortDirection,
        ];
    }

    public function applyFilters(
        SalesOrderCompletionReportValidator $validator,
        SalesOrderCompletionReportQueryService $queryService,
        SalesOrderCompletionReportSnapshotService $snapshotService
    ) {
        $filterArray = $this->exportFilters();
        
        try {
            $validated = $validator->validate($filterArray);
            
            $this->appliedFilters = array_merge($validated, [
                'periodPreset' => $this->periodPreset,
                'customerLabels' => $this->customerLabels,
                'tagLabels' => $this->tagLabels,
                'sortField' => $this->sortField,
                'sortDirection' => $this->sortDirection,
            ]);
            
            $this->filterTriggered = true;

            $applied = $this->appliedFilters;
            $applied['scopeSettingId'] = $this->settingId;
            $filter = SalesOrderCompletionReportFilterData::fromArray($applied);
            $count = $queryService->build($filter)->count();
            $snapshotService->createSnapshot($filter, $count);
            
            $this->resetPage();
        } catch (ValidationException $e) {
            $this->filterTriggered = false;
            throw $e;
        }
    }

    public function exportExcel(
        SalesOrderCompletionReportSnapshotService $snapshotService, 
        SalesOrderCompletionReportQueryService $queryService
    ) {
        if (!$this->filterTriggered || empty($this->appliedFilters)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan filter data terlebih dahulu sebelum melakukan ekspor.']);
            return null;
        }

        $applied = $this->appliedFilters;
        $applied['scopeSettingId'] = $this->settingId;
        $filter = SalesOrderCompletionReportFilterData::fromArray($applied);

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Filter telah diubah. Silakan klik tombol Filter kembali sebelum mengekspor.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $applied['sortField'] ?? 'date', $applied['sortDirection'] ?? 'desc');

        $filename = "sales_order_completion_{$filter->startDate}_{$filter->endDate}.xlsx";

        return Excel::download(new SalesOrderCompletionReportExport($query, $filter, false), $filename);
    }

    public function exportCsv(
        SalesOrderCompletionReportSnapshotService $snapshotService, 
        SalesOrderCompletionReportQueryService $queryService
    ) {
        if (!$this->filterTriggered || empty($this->appliedFilters)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan filter data terlebih dahulu sebelum melakukan ekspor.']);
            return null;
        }

        $applied = $this->appliedFilters;
        $applied['scopeSettingId'] = $this->settingId;
        $filter = SalesOrderCompletionReportFilterData::fromArray($applied);

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Filter telah diubah. Silakan klik tombol Filter kembali sebelum mengekspor.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $applied['sortField'] ?? 'date', $applied['sortDirection'] ?? 'desc');

        $filename = "sales_order_completion_{$filter->startDate}_{$filter->endDate}.csv";

        return Excel::download(new SalesOrderCompletionReportExport($query, $filter, true), $filename, \Maatwebsite\Excel\Excel::CSV);
    }

    public function render(SalesOrderCompletionReportQueryService $queryService)
    {
        $sales = collect();
        
        if ($this->filterTriggered) {
            $applied = $this->appliedFilters;
            $applied['scopeSettingId'] = $this->settingId;
            $filter = SalesOrderCompletionReportFilterData::fromArray($applied);

            $query = $queryService->build($filter);
            $queryService->applySort($query, $this->sortField, $this->sortDirection);

            $sales = $query->paginate(15);
        }

        return view('livewire.reports.sales-order-completion-report', [
            'sales' => $sales,
            'mapRow' => [$queryService, 'mapRow']
        ]);
    }
}
