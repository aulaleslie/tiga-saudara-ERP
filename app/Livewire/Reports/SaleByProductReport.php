<?php

namespace App\Livewire\Reports;

use Livewire\Component;
use Livewire\WithPagination;
use App\Services\Reports\SaleByProductReportFilterData;
use App\Services\Reports\SaleByProductReportQueryService;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Spatie\Tags\Tag;
use App\Services\Reports\SaleByProductReportValidator;
use App\Services\Reports\SaleByProductReportSnapshotService;
use App\Exports\SaleByProductReportExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Validation\ValidationException;

class SaleByProductReport extends Component
{
    use WithPagination;
    use HasReportSettingScope;

    public $startDate, $endDate;
    public $sortField = 'product_name';
    public $sortDirection = 'asc';

    public $customerIds = [];
    public $tagIds = [];
    public $tagLogic = 'Salah satu';
    public $categoryIds = [];
    public $categoryLogic = 'Salah satu';
    public $productIds = [];
    public $periodPreset = '';

    public $filterTriggered = false;
    public $appliedFilters = [];

    // Searchable filter states
    public $customerSearch = '';
    public $customerOptions = [];
    public $tagSearch = '';
    public $tagOptions = [];
    public $categorySearch = '';
    public $categoryOptions = [];
    public $productSearch = '';
    public $productOptions = [];

    // Selected labels for display pills
    public $customerLabels = [];
    public $tagLabels = [];
    public $categoryLabels = [];
    public $productLabels = [];

    protected $paginationTheme = 'bootstrap';

    public function mount()
    {
        $this->selectedSettingIds = [];
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->endOfMonth()->format('Y-m-d');
        $this->appliedFilters = $this->exportFilters();
        $this->filterTriggered = false;
    }

    public function updatedSelectedSettingIds(): void
    {
        $this->resetPage();
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

    public function selectProduct(int $id, string $name): void
    {
        if (!in_array($id, $this->productIds)) {
            $this->productIds[] = $id;
            $this->productLabels[$id] = $name;
        }
        $this->productSearch = '';
        $this->productOptions = [];
    }

    public function removeProduct(int $id): void
    {
        $this->productIds = array_values(array_diff($this->productIds, [$id]));
        unset($this->productLabels[$id]);
    }

    private function applyTokenizedSearch($query, string $value, array $columns): void
    {
        $tokens = array_filter(preg_split('/\s+/', trim($value)), fn($t) => $t !== '');
        if (empty($tokens)) {
            return;
        }

        $query->where(function ($q) use ($tokens, $columns) {
            foreach ($tokens as $token) {
                $q->where(function ($tokenQuery) use ($token, $columns) {
                    $lowerToken = '%' . mb_strtolower($token) . '%';
                    foreach ($columns as $column) {
                        $tokenQuery->orWhereRaw("LOWER({$column}) LIKE ?", [$lowerToken]);
                    }
                });
            }
        });
    }

    private function buildProductSearchQuery(string $value)
    {
        $query = Product::query();
        $this->applyTokenizedSearch($query, $value, ['product_name', 'product_code']);
        return $query;
    }

    public function selectAllMatchingProducts(): void
    {
        $value = trim($this->productSearch);
        if (strlen($value) < 2) {
            return;
        }

        $query = $this->buildProductSearchQuery($value);
        $totalMatches = (clone $query)->count();

        if ($totalMatches === 0) {
            return;
        }

        $ceiling = 500;
        $matchedProducts = $query->limit($ceiling)->get(['id', 'product_name', 'product_code']);

        foreach ($matchedProducts as $product) {
            if (!in_array($product->id, $this->productIds)) {
                $this->productIds[] = $product->id;
                $label = !empty($product->product_code)
                    ? "{$product->product_code} | {$product->product_name}"
                    : $product->product_name;
                $this->productLabels[$product->id] = $label;
            }
        }

        if ($totalMatches > $ceiling) {
            $this->dispatch('alert', [
                'type' => 'warning',
                'message' => "Pencarian menghasilkan {$totalMatches} produk. Hanya {$ceiling} produk pertama yang dipilih secara otomatis."
            ]);
        }
    }

    public function updatedCustomerSearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->customerOptions = [];
            return;
        }
        $query = Customer::query();
        $this->applyTokenizedSearch($query, $value, ['customer_name', 'contact_name']);

        $this->customerOptions = $query
            ->limit(10)->get(['id', 'customer_name', 'contact_name'])
            ->map(fn($c) => ['id' => $c->id, 'customer_name' => $c->canonical_name])->toArray();
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
        $query = Category::query();
        $this->applyTokenizedSearch($query, $value, ['category_name']);

        $this->categoryOptions = $query
            ->limit(10)->get(['id', 'category_name'])->toArray();
    }

    public function updatedProductSearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->productOptions = [];
            return;
        }
        $this->productOptions = $this->buildProductSearchQuery($value)
            ->limit(10)->get(['id', 'product_name', 'product_code'])->toArray();
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
            $this->productIds = $this->appliedFilters['productIds'] ?? [];
            $this->productLabels = $this->appliedFilters['productLabels'] ?? [];
            $this->tagLogic = $this->appliedFilters['tagLogic'] ?? 'Salah satu';
            $this->categoryLogic = $this->appliedFilters['categoryLogic'] ?? 'Salah satu';
            $this->sortField = $this->appliedFilters['sortField'] ?? 'product_name';
            $this->sortDirection = $this->appliedFilters['sortDirection'] ?? 'asc';
        }
        $this->customerSearch = '';
        $this->customerOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
        $this->categorySearch = '';
        $this->categoryOptions = [];
        $this->productSearch = '';
        $this->productOptions = [];
    }

    public function resetFilters(): void
    {
        $this->selectedSettingIds = [];
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->endOfMonth()->format('Y-m-d');
        $this->periodPreset = '';
        $this->customerIds = [];
        $this->customerLabels = [];
        $this->tagIds = [];
        $this->tagLabels = [];
        $this->categoryIds = [];
        $this->categoryLabels = [];
        $this->productIds = [];
        $this->productLabels = [];
        $this->tagLogic = 'Salah satu';
        $this->categoryLogic = 'Salah satu';
        $this->sortField = 'product_name';
        $this->sortDirection = 'asc';
        
        $this->customerSearch = '';
        $this->customerOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
        $this->categorySearch = '';
        $this->categoryOptions = [];
        $this->productSearch = '';
        $this->productOptions = [];

        $this->dispatch('sync-select2-saleByProductSettingIds', ['values' => []]);
    }

    private function exportFilters(): array
    {
        $availableSettings = $this->getAvailableSettings();
        $validatedScopeIds = $this->validateSettingIds($this->getEffectiveSettingIds(), $availableSettings);

        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'scopeSettingIds' => $validatedScopeIds,
            'periodPreset' => $this->periodPreset,
            'customerIds' => $this->customerIds,
            'tagIds' => $this->tagIds,
            'tagLogic' => $this->tagLogic,
            'categoryIds' => $this->categoryIds,
            'categoryLogic' => $this->categoryLogic,
            'productIds' => $this->productIds,
            'sortField' => $this->sortField,
            'sortDirection' => $this->sortDirection,
        ];
    }

    public function applyFilters(
        SaleByProductReportValidator $validator,
        SaleByProductReportQueryService $queryService,
        SaleByProductReportSnapshotService $snapshotService
    ) {
        $filterArray = $this->exportFilters();
        
        try {
            $validated = $validator->validate($filterArray);
            
            $this->appliedFilters = array_merge($validated, [
                'periodPreset' => $this->periodPreset,
                'customerLabels' => $this->customerLabels,
                'tagLabels' => $this->tagLabels,
                'categoryLabels' => $this->categoryLabels,
                'productLabels' => $this->productLabels,
            ]);
            
            $this->filterTriggered = true;

            $filter = SaleByProductReportFilterData::fromArray($this->appliedFilters);
            $count = $queryService->build($filter)->count();
            $snapshotService->createSnapshot($filter, $count);
            
            $this->resetPage();
        } catch (ValidationException $e) {
            $this->filterTriggered = false;
            throw $e;
        }
    }

    public function exportExcel(
        SaleByProductReportSnapshotService $snapshotService, 
        SaleByProductReportQueryService $queryService
    ) {
        if (!$this->filterTriggered) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $filter = SaleByProductReportFilterData::fromArray($this->appliedFilters);

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Kondisi filter telah berubah. Silakan terapkan filter kembali sebelum mengekspor.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

        $filename = "sale_by_product_{$filter->startDate}_{$filter->endDate}.xlsx";

        return Excel::download(new SaleByProductReportExport($query, $filter, false), $filename);
    }

    public function exportCsv(
        SaleByProductReportSnapshotService $snapshotService, 
        SaleByProductReportQueryService $queryService
    ) {
        if (!$this->filterTriggered) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $filter = SaleByProductReportFilterData::fromArray($this->appliedFilters);

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Kondisi filter telah berubah. Silakan terapkan filter kembali sebelum mengekspor.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

        $filename = "sale_by_product_{$filter->startDate}_{$filter->endDate}.csv";

        return Excel::download(new SaleByProductReportExport($query, $filter, true), $filename, \Maatwebsite\Excel\Excel::CSV);
    }

    public function render(SaleByProductReportQueryService $queryService)
    {
        $availableSettings = $this->getAvailableSettings();
        $effectiveSettingIds = $this->validateSettingIds($this->getEffectiveSettingIds(), $availableSettings);
        $scopeLabel = $this->getScopeLabel($availableSettings, $effectiveSettingIds);

        $products = collect();
        $grandTotalSold = 0;
        $grandTotalReturn = 0;

        if ($this->filterTriggered) {
            $filter = SaleByProductReportFilterData::fromArray($this->appliedFilters);

            $query = $queryService->build($filter);
            $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

            $products = $query->paginate(15);
            $totals = $queryService->calculateGrandTotal($filter);
            
            $grandTotalSold = $totals['sold_value'];
            $grandTotalReturn = $totals['return_value'];
        }

        return view('livewire.reports.sale-by-product-report', [
            'availableSettings' => $availableSettings,
            'selectedSettingIds' => $this->selectedSettingIds,
            'scopeLabel' => $scopeLabel,
            'products' => $products,
            'grandTotalSold' => $grandTotalSold,
            'grandTotalReturn' => $grandTotalReturn,
        ]);
    }
}
