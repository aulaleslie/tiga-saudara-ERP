<?php

namespace App\Livewire\Reports;

use App\Exports\SaleByProductReportExport;
use App\Services\Reports\SaleByProductCategoryTreeResolver;
use App\Services\Reports\SaleByProductReportFilterData;
use App\Services\Reports\SaleByProductReportQueryService;
use App\Services\Reports\SaleByProductReportSnapshotService;
use App\Services\Reports\SaleByProductReportValidator;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Spatie\Tags\Tag;

class SaleByProductReport extends Component
{
    use WithPagination;
    use HasReportSettingScope;

    public const REPORT_TIMEZONE = 'Asia/Makassar';

    public $startDate, $endDate;
    public $sortField = 'product_name';
    public $sortDirection = 'asc';

    public $customerIds = [];
    public $tagIds = [];
    public $tagLogic = 'Salah satu';
    public $categoryIds = [];
    public $categoryLogic = 'Salah satu';
    public $brandIds = [];
    public $productIds = [];
    public $periodPreset = 'this_month';

    public $filterTriggered = false;
    public $appliedFilters = [];

    // Searchable filter states
    public $customerSearch = '';
    public $customerOptions = [];
    public $hasMoreCustomerOptions = false;
    public $tagSearch = '';
    public $tagOptions = [];
    public $categorySearch = '';
    public $categoryOptions = [];
    public $brandSearch = '';
    public $brandOptions = [];
    public $productSearch = '';
    public $productOptions = [];
    public $hasMoreProductOptions = false;

    // Selected labels for display pills
    public $customerLabels = [];
    public $tagLabels = [];
    public $categoryLabels = [];
    public $brandLabels = [];
    public $productLabels = [];

    protected $paginationTheme = 'bootstrap';

    /** @var array<int, array<string, mixed>> */
    public array $loadedRows = [];
    public int $loadedTotalRows = 0;
    public float $loadedGrandTotalSold = 0.0;
    public int $loadedPage = 1;
    private const PER_PAGE = 15;
    private bool $suppressPaginationReload = false;

    public function mount()
    {
        $this->selectedSettingIds = $this->defaultSettingSelection();
        $this->periodPreset = 'this_month';
        $this->applyPeriodPresetDates('this_month');
        $this->appliedFilters = $this->exportFilters();
        $this->filterTriggered = false;
    }

    public function updatedSelectedSettingIds(): void
    {
        // Draft business selection does not mutate the currently applied page until Apply.
    }

    public function updatedPaginators($page, $pageName): void
    {
        if ($pageName === 'page' && $this->filterTriggered && !$this->suppressPaginationReload) {
            $this->loadAppliedPage(app(SaleByProductReportQueryService::class), (int) $page);
        }
    }

    public function updatedPeriodPreset($value): void
    {
        if ($value === 'custom' || $value === '' || $value === null) {
            return;
        }

        $this->applyPeriodPresetDates((string) $value);
    }

    public function updatedStartDate(): void
    {
        $this->syncPeriodPresetToDates();
    }

    public function updatedEndDate(): void
    {
        $this->syncPeriodPresetToDates();
    }

    private function syncPeriodPresetToDates(): void
    {
        $ranges = $this->periodDateRanges();
        $dates = [$this->startDate, $this->endDate];

        // Retain the selected preset if calendar periods happen to share boundaries.
        if (isset($ranges[$this->periodPreset]) && $ranges[$this->periodPreset] === $dates) {
            return;
        }

        $this->periodPreset = array_search($dates, $ranges, true) ?: 'custom';
    }

    private function applyPeriodPresetDates(string $preset): void
    {
        $range = $this->periodDateRanges()[$preset] ?? null;

        if ($range !== null) {
            [$this->startDate, $this->endDate] = $range;
        }
    }

    /** @return array<string, array{string, string}> */
    private function periodDateRanges(): array
    {
        $now = Carbon::now(self::REPORT_TIMEZONE);

        $ranges = [
            'today' => [
                $now->copy()->format('Y-m-d'),
                $now->copy()->format('Y-m-d'),
            ],
            'this_week' => [
                $now->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d'),
                $now->copy()->endOfWeek(Carbon::SUNDAY)->format('Y-m-d'),
            ],
            'last_week' => [
                $now->copy()->subWeek()->startOfWeek(Carbon::MONDAY)->format('Y-m-d'),
                $now->copy()->subWeek()->endOfWeek(Carbon::SUNDAY)->format('Y-m-d'),
            ],
            'this_month' => [
                $now->copy()->startOfMonth()->format('Y-m-d'),
                $now->copy()->endOfMonth()->format('Y-m-d'),
            ],
            'last_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'),
                $now->copy()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d'),
            ],
            'this_year' => [
                $now->copy()->startOfYear()->format('Y-m-d'),
                $now->copy()->endOfYear()->format('Y-m-d'),
            ],
            'last_year' => [
                $now->copy()->subYearNoOverflow()->startOfYear()->format('Y-m-d'),
                $now->copy()->subYearNoOverflow()->endOfYear()->format('Y-m-d'),
            ],
        ];

        return $ranges;
    }

    public function toggleCustomer(int $id, string $name = ''): void
    {
        $ids = array_map('intval', $this->customerIds);
        if (in_array($id, $ids, true)) {
            $this->removeCustomer($id);
        } else {
            $this->customerIds = array_values(array_unique(array_merge($ids, [$id])));
            if ($name !== '') {
                $this->customerLabels[$id] = $name;
            } else {
                $customer = Customer::query()->find($id, ['id', 'customer_name', 'contact_name']);
                $this->customerLabels[$id] = $customer ? $customer->canonical_name : 'ID:' . $id;
            }
        }
    }

    public function selectCustomer(int $id, string $name): void
    {
        $ids = array_map('intval', $this->customerIds);
        if (!in_array($id, $ids, true)) {
            $this->customerIds[] = $id;
            $this->customerLabels[$id] = $name;
        }
        $this->customerSearch = '';
        $this->customerOptions = [];
        $this->hasMoreCustomerOptions = false;
    }

    public function removeCustomer(int $id): void
    {
        $this->customerIds = array_values(array_diff(array_map('intval', $this->customerIds), [$id]));
        unset($this->customerLabels[$id]);
    }

    public function clearCustomers(): void
    {
        $this->customerIds = [];
        $this->customerLabels = [];
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

    public function toggleCategory(int $id, string $name = ''): void
    {
        $ids = array_map('intval', $this->categoryIds);
        if (in_array($id, $ids, true)) {
            $this->removeCategory($id);
        } else {
            $this->categoryIds = array_values(array_unique(array_merge($ids, [$id])));
            if ($name !== '') {
                $this->categoryLabels[$id] = $name;
            } else {
                $paths = (new SaleByProductCategoryTreeResolver())->buildPathLabels([$id]);
                $this->categoryLabels[$id] = $paths[$id] ?? 'ID:' . $id;
            }
        }
    }

    public function selectCategory(int $id, string $name): void
    {
        $ids = array_map('intval', $this->categoryIds);
        if (!in_array($id, $ids, true)) {
            $this->categoryIds[] = $id;
            $this->categoryLabels[$id] = $name;
        }
        $this->categorySearch = '';
        $this->categoryOptions = [];
    }

    public function removeCategory(int $id): void
    {
        $this->categoryIds = array_values(array_diff(array_map('intval', $this->categoryIds), [$id]));
        unset($this->categoryLabels[$id]);
    }

    public function clearCategories(): void
    {
        $this->categoryIds = [];
        $this->categoryLabels = [];
    }

    public function toggleBrand(int $id, string $name = ''): void
    {
        $ids = array_map('intval', $this->brandIds);
        if (in_array($id, $ids, true)) {
            $this->removeBrand($id);
        } else {
            $this->brandIds = array_values(array_unique(array_merge($ids, [$id])));
            if ($name !== '') {
                $this->brandLabels[$id] = $name;
            } else {
                $brand = Brand::query()->find($id, ['id', 'name']);
                $this->brandLabels[$id] = $brand ? $brand->name : 'ID:' . $id;
            }
        }
    }

    public function selectBrand(int $id, string $name): void
    {
        $ids = array_map('intval', $this->brandIds);
        if (!in_array($id, $ids, true)) {
            $this->brandIds[] = $id;
            $this->brandLabels[$id] = $name;
        }
        $this->brandSearch = '';
        $this->brandOptions = [];
    }

    public function removeBrand(int $id): void
    {
        $this->brandIds = array_values(array_diff(array_map('intval', $this->brandIds), [$id]));
        unset($this->brandLabels[$id]);
    }

    public function clearBrands(): void
    {
        $this->brandIds = [];
        $this->brandLabels = [];
    }

    public function toggleProduct(int $id, string $label = ''): void
    {
        $ids = array_map('intval', $this->productIds);
        if (in_array($id, $ids, true)) {
            $this->removeProduct($id);
        } else {
            $this->productIds = array_values(array_unique(array_merge($ids, [$id])));
            if ($label !== '') {
                $this->productLabels[$id] = $label;
            } else {
                $product = Product::query()->find($id, ['id', 'product_name', 'product_code']);
                $this->productLabels[$id] = $product
                    ? (!empty($product->product_code) ? "{$product->product_code} | {$product->product_name}" : $product->product_name)
                    : 'ID:' . $id;
            }
        }
    }

    public function selectProduct(int $id, string $name): void
    {
        $ids = array_map('intval', $this->productIds);
        if (!in_array($id, $ids, true)) {
            $this->productIds[] = $id;
            $this->productLabels[$id] = $name;
        }
        $this->productSearch = '';
        $this->productOptions = [];
        $this->hasMoreProductOptions = false;
    }

    public function removeProduct(int $id): void
    {
        $this->productIds = array_values(array_diff(array_map('intval', $this->productIds), [$id]));
        unset($this->productLabels[$id]);
    }

    public function clearProducts(): void
    {
        $this->productIds = [];
        $this->productLabels = [];
    }

    private function applyTokenizedSearch($query, string $value, array $columns): void
    {
        $tokens = array_filter(preg_split('/\s+/', trim($value)), fn ($t) => $t !== '');
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
        $query = Product::query()->orderBy('product_name');
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
        $value = trim((string) $value);
        if (strlen($value) < 2) {
            $this->customerOptions = [];
            $this->hasMoreCustomerOptions = false;
            return;
        }
        $query = Customer::query()->orderBy('customer_name');
        $this->applyTokenizedSearch($query, $value, ['customer_name', 'contact_name']);

        $raw = $query->limit(26)->get(['id', 'customer_name', 'contact_name']);
        $this->hasMoreCustomerOptions = $raw->count() > 25;
        $this->customerOptions = $raw->take(25)
            ->map(fn ($c) => [
                'id' => $c->id,
                'customer_name' => $c->canonical_name,
                'contact_name' => $c->contact_name,
            ])->toArray();
    }

    public function updatedTagSearch($value): void
    {
        $value = trim((string) $value);
        if (strlen($value) < 2) {
            $this->tagOptions = [];
            return;
        }
        $locale = app()->getLocale();
        $this->tagOptions = Tag::query()
            ->where(fn ($q) => $q->containing($value, $locale)->orWhere(fn ($sq) => $sq->containing($value, 'en')))
            ->limit(10)->get(['id', 'name'])->toArray();
    }

    public function updatedCategorySearch($value): void
    {
        $value = trim((string) $value);
        if (strlen($value) < 2) {
            $this->categoryOptions = [];
            return;
        }
        $query = Category::query()->orderBy('category_name');
        $this->applyTokenizedSearch($query, $value, ['category_name']);

        $matched = $query->limit(25)->get(['id', 'category_name', 'parent_id']);
        $paths = (new SaleByProductCategoryTreeResolver())->buildPathLabels($matched->pluck('id')->all());

        $this->categoryOptions = $matched->map(fn ($cat) => [
            'id' => $cat->id,
            'category_name' => $cat->category_name,
            'display_path' => $paths[$cat->id] ?? $cat->category_name,
        ])->toArray();
    }

    public function updatedBrandSearch($value): void
    {
        $value = trim((string) $value);
        if (strlen($value) < 2) {
            $this->brandOptions = [];
            return;
        }
        $query = Brand::query()->orderBy('name');
        $this->applyTokenizedSearch($query, $value, ['name']);

        $this->brandOptions = $query
            ->limit(25)->get(['id', 'name'])->toArray();
    }

    public function updatedProductSearch($value): void
    {
        $value = trim((string) $value);
        if (strlen($value) < 2) {
            $this->productOptions = [];
            $this->hasMoreProductOptions = false;
            return;
        }

        $raw = $this->buildProductSearchQuery($value)
            ->limit(26)->get(['id', 'product_name', 'product_code']);

        $this->hasMoreProductOptions = $raw->count() > 25;
        $this->productOptions = $raw->take(25)->toArray();
    }

    public function getHasPendingFilterChangesProperty(): bool
    {
        if (!$this->filterTriggered || empty($this->appliedFilters)) {
            return false;
        }

        $currentHash = SaleByProductReportFilterData::fromArray($this->exportFilters())->hash();
        $appliedHash = SaleByProductReportFilterData::fromArray($this->appliedFilters)->hash();

        return $currentHash !== $appliedHash;
    }

    public function cancelFilters(): void
    {
        if (!empty($this->appliedFilters)) {
            $this->startDate = $this->appliedFilters['startDate'] ?? $this->startDate;
            $this->endDate = $this->appliedFilters['endDate'] ?? $this->endDate;
            $this->selectedSettingIds = $this->appliedFilters['scopeSettingIds'] ?? $this->selectedSettingIds;
            $this->periodPreset = $this->appliedFilters['periodPreset'] ?? 'custom';
            $this->customerIds = $this->appliedFilters['customerIds'] ?? [];
            $this->customerLabels = $this->appliedFilters['customerLabels'] ?? [];
            $this->tagIds = $this->appliedFilters['tagIds'] ?? [];
            $this->tagLabels = $this->appliedFilters['tagLabels'] ?? [];
            $this->categoryIds = $this->appliedFilters['categoryIds'] ?? [];
            $this->categoryLabels = $this->appliedFilters['categoryLabels'] ?? [];
            $this->brandIds = $this->appliedFilters['brandIds'] ?? [];
            $this->brandLabels = $this->appliedFilters['brandLabels'] ?? [];
            $this->productIds = $this->appliedFilters['productIds'] ?? [];
            $this->productLabels = $this->appliedFilters['productLabels'] ?? [];
            $this->tagLogic = $this->appliedFilters['tagLogic'] ?? 'Salah satu';
            $this->categoryLogic = 'Salah satu';
            $this->sortField = $this->appliedFilters['sortField'] ?? 'product_name';
            $this->sortDirection = $this->appliedFilters['sortDirection'] ?? 'asc';
        }
        $this->customerSearch = '';
        $this->customerOptions = [];
        $this->hasMoreCustomerOptions = false;
        $this->tagSearch = '';
        $this->tagOptions = [];
        $this->categorySearch = '';
        $this->categoryOptions = [];
        $this->brandSearch = '';
        $this->brandOptions = [];
        $this->productSearch = '';
        $this->productOptions = [];
        $this->hasMoreProductOptions = false;
    }

    public function resetFilters(): void
    {
        $this->selectedSettingIds = $this->defaultSettingSelection();
        $this->periodPreset = 'this_month';
        $this->applyPeriodPresetDates('this_month');
        $this->customerIds = [];
        $this->customerLabels = [];
        $this->tagIds = [];
        $this->tagLabels = [];
        $this->categoryIds = [];
        $this->categoryLabels = [];
        $this->brandIds = [];
        $this->brandLabels = [];
        $this->productIds = [];
        $this->productLabels = [];
        $this->tagLogic = 'Salah satu';
        $this->categoryLogic = 'Salah satu';
        $this->sortField = 'product_name';
        $this->sortDirection = 'asc';

        $this->customerSearch = '';
        $this->customerOptions = [];
        $this->hasMoreCustomerOptions = false;
        $this->tagSearch = '';
        $this->tagOptions = [];
        $this->categorySearch = '';
        $this->categoryOptions = [];
        $this->brandSearch = '';
        $this->brandOptions = [];
        $this->productSearch = '';
        $this->productOptions = [];
        $this->hasMoreProductOptions = false;

        $this->dispatch('sync-business-selector');
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
            'categoryLogic' => 'Salah satu',
            'brandIds' => $this->brandIds,
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
                'brandLabels' => $this->brandLabels,
                'productLabels' => $this->productLabels,
            ]);

            $this->filterTriggered = true;
            $this->suppressPaginationReload = true;
            try {
                $this->resetPage();
            } finally {
                $this->suppressPaginationReload = false;
            }

            $filter = SaleByProductReportFilterData::fromArray($this->appliedFilters);
            $this->loadAppliedPage($queryService, 1, $filter);
            $snapshotService->createSnapshot($filter, $this->loadedTotalRows);
        } catch (ValidationException $e) {
            $this->filterTriggered = false;
            throw $e;
        }
    }

    private function loadAppliedPage(
        SaleByProductReportQueryService $queryService,
        int $page = 1,
        ?SaleByProductReportFilterData $filter = null
    ): void {
        $availableSettings = $this->getAvailableSettings();
        $effectiveSettingIds = $this->validateSettingIds($this->getEffectiveSettingIds(), $availableSettings);

        if ($filter === null) {
            $appliedSettingIds = $this->validateSettingIds(
                $this->appliedFilters['scopeSettingIds'] ?? $effectiveSettingIds,
                $availableSettings
            );

            $filter = SaleByProductReportFilterData::fromArray(array_merge($this->appliedFilters, [
                'scopeSettingIds' => $appliedSettingIds,
            ]));
        }

        $paginated = $queryService->paginate($filter, self::PER_PAGE, max(1, $page));
        $paginator = $paginated['paginator'];
        $totals = $paginated['totals'];

        $this->loadedPage = $paginator->currentPage();
        $this->loadedTotalRows = (int) $totals['row_count'];
        $this->loadedGrandTotalSold = (float) $totals['sold_value'];
        $this->loadedRows = $paginator->getCollection()
            ->map(fn (object $row) => [
                'product_id' => $row->product_id,
                'product_code' => $row->product_code,
                'product_name' => $row->product_name,
                'unit_name' => $row->unit_name,
                'sold_quantity' => $row->sold_quantity,
                'sold_value' => $row->sold_value,
                'average_sales_value' => $row->average_sales_value,
            ])
            ->values()
            ->all();
    }

    public function exportExcel(
        SaleByProductReportSnapshotService $snapshotService,
        SaleByProductReportQueryService $queryService
    ) {
        if (!$this->filterTriggered) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $currentFilter = SaleByProductReportFilterData::fromArray($this->exportFilters());

        if (!$snapshotService->isValidForExport($currentFilter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Kondisi filter telah berubah. Silakan terapkan filter kembali sebelum mengekspor.']);
            return;
        }

        $dataset = $queryService->getDataset($currentFilter);
        $filename = "sale_by_product_{$currentFilter->startDate}_{$currentFilter->endDate}.xlsx";

        return Excel::download(new SaleByProductReportExport($dataset, $currentFilter, false), $filename);
    }

    public function exportCsv(
        SaleByProductReportSnapshotService $snapshotService,
        SaleByProductReportQueryService $queryService
    ) {
        if (!$this->filterTriggered) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $currentFilter = SaleByProductReportFilterData::fromArray($this->exportFilters());

        if (!$snapshotService->isValidForExport($currentFilter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Kondisi filter telah berubah. Silakan terapkan filter kembali sebelum mengekspor.']);
            return;
        }

        $dataset = $queryService->getDataset($currentFilter);
        $filename = "sale_by_product_{$currentFilter->startDate}_{$currentFilter->endDate}.csv";

        return Excel::download(new SaleByProductReportExport($dataset, $currentFilter, true), $filename, \Maatwebsite\Excel\Excel::CSV);
    }

    private function buildAppliedSummary($availableSettings): array
    {
        if (!$this->filterTriggered || empty($this->appliedFilters)) {
            return [];
        }

        $appliedSettingIds = $this->validateSettingIds(
            $this->appliedFilters['scopeSettingIds'] ?? [],
            $availableSettings
        );
        $scopeLabel = $this->getScopeLabel($availableSettings, $appliedSettingIds);

        $presetLabels = [
            'today' => 'Hari Ini',
            'this_week' => 'Minggu Ini',
            'last_week' => 'Minggu Lalu',
            'this_month' => 'Bulan Ini',
            'last_month' => 'Bulan Lalu',
            'this_year' => 'Tahun Ini',
            'last_year' => 'Tahun Lalu',
            'custom' => 'Rentang khusus',
        ];

        $presetKey = $this->appliedFilters['periodPreset'] ?? 'custom';
        $start = $this->appliedFilters['startDate'] ?? '';
        $end = $this->appliedFilters['endDate'] ?? '';

        return [
            'scope' => $scopeLabel,
            'period_label' => $presetLabels[$presetKey] ?? 'Rentang khusus',
            'date_range' => ($start && $end) ? "{$start} s/d {$end}" : '',
            'customers' => $this->resolveAppliedLabels($this->appliedFilters['customerIds'] ?? [], $this->appliedFilters['customerLabels'] ?? []),
            'categories' => $this->resolveAppliedLabels($this->appliedFilters['categoryIds'] ?? [], $this->appliedFilters['categoryLabels'] ?? []),
            'brands' => $this->resolveAppliedLabels($this->appliedFilters['brandIds'] ?? [], $this->appliedFilters['brandLabels'] ?? []),
            'products' => $this->resolveAppliedLabels($this->appliedFilters['productIds'] ?? [], $this->appliedFilters['productLabels'] ?? []),
            'tags' => $this->resolveAppliedLabels($this->appliedFilters['tagIds'] ?? [], $this->appliedFilters['tagLabels'] ?? []),
        ];
    }

    private function resolveAppliedLabels(array $ids, array $labels): array
    {
        $resolved = [];
        foreach ($ids as $id) {
            $resolved[] = $labels[$id] ?? ('ID:' . $id);
        }
        return $resolved;
    }

    public function render()
    {
        $availableSettings = $this->getAvailableSettings();
        $effectiveSettingIds = $this->validateSettingIds($this->getEffectiveSettingIds(), $availableSettings);
        $scopeLabel = $this->getScopeLabel($availableSettings, $effectiveSettingIds);

        $products = collect();
        $grandTotalSold = 0.0;

        if ($this->filterTriggered) {
            $slice = collect($this->loadedRows)->map(fn (array $row) => (object) $row);
            $products = new \Illuminate\Pagination\LengthAwarePaginator(
                $slice,
                $this->loadedTotalRows,
                self::PER_PAGE,
                $this->loadedPage,
                ['path' => \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPath()]
            );
            $grandTotalSold = $this->loadedGrandTotalSold;
        }

        return view('livewire.reports.sale-by-product-report', [
            'availableSettings' => $availableSettings,
            'selectedSettingIds' => $this->selectedSettingIds,
            'scopeLabel' => $scopeLabel,
            'products' => $products,
            'grandTotalSold' => $grandTotalSold,
            'hasPendingFilterChanges' => $this->hasPendingFilterChanges,
            'appliedSummary' => $this->buildAppliedSummary($availableSettings),
        ]);
    }
}
