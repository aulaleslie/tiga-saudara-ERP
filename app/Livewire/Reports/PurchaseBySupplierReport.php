<?php

namespace App\Livewire\Reports;

use Livewire\Component;

use Livewire\WithPagination;
use App\Services\Reports\PurchaseBySupplierReportFilterData;
use App\Services\Reports\PurchaseBySupplierReportQueryService;

use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Spatie\Tags\Tag;

use App\Services\Reports\PurchaseBySupplierReportValidator;
use Illuminate\Validation\ValidationException;

class PurchaseBySupplierReport extends Component
{
    use WithPagination;

    public $startDate, $endDate;
    public $settingId;
    public $sortField = 'date';
    public $sortDirection = 'desc';

    public $supplierIds = [];
    public $tagIds = [];
    public $tagLogic = 'Salah satu';
    public $categoryIds = [];
    public $categoryLogic = 'Salah satu';
    public $periodPreset = '';

    public $filterTriggered = false; // Determines if the report is actively rendering results
    
    public $appliedFilters = [];

    // Searchable filter states
    public $supplierSearch = '';
    public $supplierOptions = [];
    public $tagSearch = '';
    public $tagOptions = [];
    public $categorySearch = '';
    public $categoryOptions = [];

    // Selected labels for display pills
    public $supplierLabels = [];
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
            $this->supplierIds = $this->appliedFilters['supplierIds'] ?? [];
            $this->supplierLabels = $this->appliedFilters['supplierLabels'] ?? [];
            $this->tagIds = $this->appliedFilters['tagIds'] ?? [];
            $this->tagLabels = $this->appliedFilters['tagLabels'] ?? [];
            $this->categoryIds = $this->appliedFilters['categoryIds'] ?? [];
            $this->categoryLabels = $this->appliedFilters['categoryLabels'] ?? [];
            $this->tagLogic = $this->appliedFilters['tagLogic'] ?? 'Salah satu';
            $this->categoryLogic = $this->appliedFilters['categoryLogic'] ?? 'Salah satu';
            $this->sortField = $this->appliedFilters['sortField'] ?? 'date';
            $this->sortDirection = $this->appliedFilters['sortDirection'] ?? 'desc';
        }
        $this->supplierSearch = '';
        $this->supplierOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
        $this->categorySearch = '';
        $this->categoryOptions = [];
    }

    public function resetFilters(): void
    {
        $this->supplierIds = [];
        $this->supplierLabels = [];
        $this->tagIds = [];
        $this->tagLabels = [];
        $this->categoryIds = [];
        $this->categoryLabels = [];
        $this->tagLogic = 'Salah satu';
        $this->categoryLogic = 'Salah satu';
        $this->sortField = 'date';
        $this->sortDirection = 'desc';
        $this->supplierSearch = '';
        $this->supplierOptions = [];
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
            'supplierIds' => $this->supplierIds,
            'tagIds' => $this->tagIds,
            'tagLogic' => $this->tagLogic,
            'categoryIds' => $this->categoryIds,
            'categoryLogic' => $this->categoryLogic,
            'sortField' => $this->sortField,
            'sortDirection' => $this->sortDirection,
        ];
    }

    public function applyFilters(PurchaseBySupplierReportValidator $validator)
    {
        $filterArray = $this->exportFilters();
        
        try {
            $validated = $validator->validate($filterArray);
            
            $this->appliedFilters = array_merge($validated, [
                'supplierLabels' => $this->supplierLabels,
                'tagLabels' => $this->tagLabels,
                'categoryLabels' => $this->categoryLabels,
            ]);
            
            $this->filterTriggered = true;
            $this->resetPage();
        } catch (ValidationException $e) {
            $this->filterTriggered = false;
            throw $e;
        }
    }

    public function render(PurchaseBySupplierReportQueryService $queryService)
    {
        $purchases = collect();
        
        if ($this->filterTriggered) {
            $filter = new PurchaseBySupplierReportFilterData(
                startDate: $this->appliedFilters['startDate'] ?? $this->startDate,
                endDate: $this->appliedFilters['endDate'] ?? $this->endDate,
                scopeSettingId: $this->settingId,
                supplierIds: $this->appliedFilters['supplierIds'] ?? [],
                tagIds: $this->appliedFilters['tagIds'] ?? [],
                tagLogic: $this->appliedFilters['tagLogic'] ?? 'Salah satu',
                categoryIds: $this->appliedFilters['categoryIds'] ?? [],
                categoryLogic: $this->appliedFilters['categoryLogic'] ?? 'Salah satu',
                sortField: $this->appliedFilters['sortField'] ?? 'date',
                sortDirection: $this->appliedFilters['sortDirection'] ?? 'desc'
            );

            $query = $queryService->build($filter);
            $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

            $baseQuery = clone $query;

            $purchases = $query->paginate(15);

            // Compute running totals for the displayed rows
            $runningTotals = [];
            
            if ($purchases->currentPage() > 1) {
                $offset = ($purchases->currentPage() - 1) * $purchases->perPage();
                $previousQuery = clone $baseQuery;
                $previousQuery->setEagerLoads([]);
                $previousQuery->select('purchases.supplier_id', 'purchase_details.sub_total');
                $previousRows = $previousQuery->limit($offset)->get();
                
                foreach ($previousRows as $row) {
                    $supplierId = $row->supplier_id;
                    if ($supplierId) {
                        $runningTotals[$supplierId] = ($runningTotals[$supplierId] ?? 0) + $row->sub_total;
                    }
                }
            }
            
            // Calculate sequential running totals down the displayed rows for each supplier.
            $purchases->getCollection()->transform(function ($detail) use (&$runningTotals) {
                $supplierId = $detail->purchase->supplier_id;
                if (!isset($runningTotals[$supplierId])) {
                    $runningTotals[$supplierId] = 0;
                }
                $runningTotals[$supplierId] += $detail->sub_total;
                
                $detail->running_total = $runningTotals[$supplierId];
                return $detail;
            });
        }

        return view('livewire.reports.purchase-by-supplier-report', [
            'purchases' => $purchases
        ]);
    }
}
