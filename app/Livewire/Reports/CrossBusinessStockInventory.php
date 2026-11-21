<?php

namespace App\Livewire\Reports;

use App\Exports\CrossBusinessStockInventoryExport;
use App\Services\Reports\CrossBusinessStockInventoryFilterData;
use App\Services\Reports\CrossBusinessStockInventoryQueryService;
use App\Services\Reports\SaleByProductCategoryTreeResolver;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;

class CrossBusinessStockInventory extends Component
{
    use WithPagination;
    use HasReportSettingScope;

    private const PER_PAGE = 15;

    // Draft filter criteria (do not affect table/export until Apply)
    public string $search = '';
    public string $availability = 'all'; // 'all', 'available', 'non_available'
    public array $categoryIds = [];
    public array $categoryLabels = [];
    public array $brandIds = [];
    public array $brandLabels = [];
    public array $productIds = [];
    public array $productLabels = [];

    // Canonical applied filter criteria
    #[Locked]
    public array $appliedFilters = [];

    // Independent presentation state (does not create pending criteria)
    public string $displayMode = 'decimal'; // 'decimal' or 'conversion'
    public bool $showPrices = false;
    public string $exportContent = CrossBusinessStockInventoryExport::CONTENT_STOCK;

    // Filter drawer live-search state (never triggers report aggregation)
    public string $categorySearch = '';
    public array $categoryOptions = [];
    public bool $hasMoreCategoryOptions = false;
    public string $brandSearch = '';
    public array $brandOptions = [];
    public bool $hasMoreBrandOptions = false;
    public string $productSearch = '';
    public array $productOptions = [];
    public bool $hasMoreProductOptions = false;

    // Expanded column state: map of setting_id => boolean
    public array $expandedBusinesses = [];

    // Bounded server-controlled loaded page state
    #[Locked]
    public array $loadedRows = [];
    #[Locked]
    public array $loadedBusinesses = [];
    #[Locked]
    public int $loadedTotalRows = 0;
    #[Locked]
    public int $loadedPage = 1;
    #[Locked]
    public string $loadedFingerprint = '';
    #[Locked]
    public array $loadedPrices = [];
    #[Locked]
    public string $loadedPricesFingerprint = '';

    // Serial Dialog state
    public bool $showSerialDialog = false;
    #[Locked]
    public ?int $dialogProductId = null;
    public string $dialogProductName = '';
    #[Locked]
    public ?int $dialogSettingId = null;
    public string $dialogBusinessName = '';
    #[Locked]
    public ?int $dialogLocationId = null;
    public string $dialogLocationName = 'Semua Lokasi';
    public string $dialogCondition = 'good'; // 'good' or 'bad'
    public string $dialogSearch = '';
    public int $dialogPage = 1;

    // Pagination theme
    protected $paginationTheme = 'bootstrap';

    private bool $suppressPaginationReload = false;

    public function mount(CrossBusinessStockInventoryQueryService $queryService): void
    {
        abort_unless($this->canViewStockReport(), 403);

        // Default-selected businesses = all businesses visible to acting user
        $this->selectedSettingIds = $this->allAvailableSettingIds();
        $this->appliedFilters = $this->buildDraftFilterSnapshot();

        if (!empty($this->appliedFilters['businessIds'])) {
            $this->loadAppliedPage($queryService, 1);
        }
    }

    public function canViewStockReport(): bool
    {
        return auth()->user()?->can('inventory.view_remaining_stock') ?? false;
    }

    public function canViewPrices(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->can('inventory.view_remaining_stock')
            && $user->can('products.view_prices');
    }

    public function updatedPaginators($page, $pageName): void
    {
        if ($pageName === 'page' && !$this->suppressPaginationReload) {
            abort_unless($this->canViewStockReport(), 403);
            $this->loadAppliedPage(app(CrossBusinessStockInventoryQueryService::class), max(1, (int) $page));
        }
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->dispatch('clear-search-inputs', target: 'search');
    }

    public function updatedDisplayMode($value): void
    {
        if (!in_array($value, ['decimal', 'conversion'], true)) {
            $this->displayMode = 'decimal';
        }
    }

    public function updatedExportContent($value): void
    {
        if (!in_array($value, [
            CrossBusinessStockInventoryExport::CONTENT_STOCK,
            CrossBusinessStockInventoryExport::CONTENT_PRICES,
            CrossBusinessStockInventoryExport::CONTENT_STOCK_AND_PRICES,
        ], true)) {
            $this->exportContent = CrossBusinessStockInventoryExport::CONTENT_STOCK;
            return;
        }

        if ($value !== CrossBusinessStockInventoryExport::CONTENT_STOCK && !$this->canViewPrices()) {
            $this->exportContent = CrossBusinessStockInventoryExport::CONTENT_STOCK;
        }
    }

    public function updatedShowPrices($value): void
    {
        $wantsPrices = (bool) $value;

        if (!$wantsPrices) {
            $this->showPrices = false;
            $this->loadedPrices = [];
            $this->loadedPricesFingerprint = '';
            return;
        }

        if (!$this->canViewPrices()) {
            $this->showPrices = false;
            $this->loadedPrices = [];
            $this->loadedPricesFingerprint = '';
            abort(403, 'Anda tidak memiliki izin untuk melihat harga produk.');
        }

        $this->showPrices = true;
        $this->loadPricesForCurrentPage(app(CrossBusinessStockInventoryQueryService::class));
    }

    public function toggleBusinessExpand(int $settingId): void
    {
        $this->expandedBusinesses[$settingId] = !($this->expandedBusinesses[$settingId] ?? false);
    }

    // --- Tokenized search helper (Sales by Product pattern) ---
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

    // --- Category checkbox multi-select ---
    public function updatedCategorySearch($value): void
    {
        $value = trim((string) $value);
        if (strlen($value) < 2) {
            $this->categoryOptions = [];
            $this->hasMoreCategoryOptions = false;
            return;
        }

        $query = Category::query()->orderBy('category_name');
        $this->applyTokenizedSearch($query, $value, ['category_name']);

        $raw = $query->limit(26)->get(['id', 'category_name', 'parent_id']);
        $this->hasMoreCategoryOptions = $raw->count() > 25;
        $matched = $raw->take(25);
        $paths = (new SaleByProductCategoryTreeResolver())->buildPathLabels($matched->pluck('id')->all());

        $this->categoryOptions = $matched->map(fn ($cat) => [
            'id' => $cat->id,
            'category_name' => $cat->category_name,
            'display_path' => $paths[$cat->id] ?? $cat->category_name,
        ])->toArray();
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

    // --- Brand checkbox multi-select ---
    public function updatedBrandSearch($value): void
    {
        $value = trim((string) $value);
        if (strlen($value) < 2) {
            $this->brandOptions = [];
            $this->hasMoreBrandOptions = false;
            return;
        }

        $query = Brand::query()->orderBy('name');
        $this->applyTokenizedSearch($query, $value, ['name']);

        $raw = $query->limit(26)->get(['id', 'name']);
        $this->hasMoreBrandOptions = $raw->count() > 25;
        $this->brandOptions = $raw->take(25)->toArray();
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

    // --- Product checkbox multi-select (global product identity, 25-cap + 500 bulk-select) ---
    private function buildProductSearchQuery(string $value)
    {
        $query = Product::query()->active()->orderBy('product_name');
        $this->applyTokenizedSearch($query, $value, ['product_name', 'product_code']);

        return $query;
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
            ->limit(26)
            ->get(['id', 'product_name', 'product_code']);

        $this->hasMoreProductOptions = $raw->count() > 25;
        $this->productOptions = $raw->take(25)->toArray();
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
        $existingIds = array_map('intval', $this->productIds);

        foreach ($matchedProducts as $product) {
            if (!in_array((int) $product->id, $existingIds, true)) {
                $existingIds[] = (int) $product->id;
                $label = !empty($product->product_code)
                    ? "{$product->product_code} | {$product->product_name}"
                    : $product->product_name;
                $this->productLabels[$product->id] = $label;
            }
        }
        $this->productIds = array_values(array_unique($existingIds));

        if ($totalMatches > $ceiling) {
            $this->dispatch('alert', [
                'type' => 'warning',
                'message' => "Pencarian menghasilkan {$totalMatches} produk. Hanya {$ceiling} produk pertama yang dipilih secara otomatis.",
            ]);
        }
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

    // --- Draft / Applied lifecycle ---
    private function buildDraftFilterSnapshot(): array
    {
        $sanitizedBusinessIds = $this->getSanitizedSettingIds($this->selectedSettingIds);

        return [
            'search' => trim($this->search),
            'businessIds' => CrossBusinessStockInventoryFilterData::normalizeIds($sanitizedBusinessIds),
            'categoryIds' => CrossBusinessStockInventoryFilterData::normalizeIds($this->categoryIds),
            'categoryLabels' => $this->categoryLabels,
            'brandIds' => CrossBusinessStockInventoryFilterData::normalizeIds($this->brandIds),
            'brandLabels' => $this->brandLabels,
            'productIds' => CrossBusinessStockInventoryFilterData::normalizeIds($this->productIds),
            'productLabels' => $this->productLabels,
            'availability' => in_array($this->availability, ['all', 'available', 'non_available'], true) ? $this->availability : 'all',
        ];
    }

    public function getHasPendingFilterChangesProperty(): bool
    {
        if (empty($this->appliedFilters)) {
            return false;
        }

        $draftHash = CrossBusinessStockInventoryFilterData::fromArray($this->buildDraftFilterSnapshot())->hash();
        $appliedHash = CrossBusinessStockInventoryFilterData::fromArray($this->appliedFilters)->hash();

        return $draftHash !== $appliedHash;
    }

    public function applyFilters(CrossBusinessStockInventoryQueryService $queryService): void
    {
        abort_unless($this->canViewStockReport(), 403);
        $this->resetErrorBag();

        $sanitizedBusinessIds = $this->getSanitizedSettingIds($this->selectedSettingIds);
        if (empty($sanitizedBusinessIds)) {
            $this->addError('selectedSettingIds', 'Pilih minimal satu bisnis yang diizinkan.');
            return;
        }

        $this->selectedSettingIds = $sanitizedBusinessIds;
        $this->appliedFilters = $this->buildDraftFilterSnapshot();

        $this->suppressPaginationReload = true;
        try {
            $this->resetPage();
        } finally {
            $this->suppressPaginationReload = false;
        }

        $this->loadAppliedPage($queryService, 1);
    }

    public function cancelFilters(): void
    {
        $this->resetErrorBag();

        if (!empty($this->appliedFilters)) {
            $this->search = (string) ($this->appliedFilters['search'] ?? '');
            $this->selectedSettingIds = (array) ($this->appliedFilters['businessIds'] ?? []);
            $this->availability = (string) ($this->appliedFilters['availability'] ?? 'all');
            $this->categoryIds = (array) ($this->appliedFilters['categoryIds'] ?? []);
            $this->categoryLabels = (array) ($this->appliedFilters['categoryLabels'] ?? []);
            $this->brandIds = (array) ($this->appliedFilters['brandIds'] ?? []);
            $this->brandLabels = (array) ($this->appliedFilters['brandLabels'] ?? []);
            $this->productIds = (array) ($this->appliedFilters['productIds'] ?? []);
            $this->productLabels = (array) ($this->appliedFilters['productLabels'] ?? []);
        }

        $this->clearSelectorSearches();
        $this->dispatch('sync-business-selector');
    }

    public function resetFilters(CrossBusinessStockInventoryQueryService $queryService): void
    {
        abort_unless($this->canViewStockReport(), 403);
        $this->resetErrorBag();

        $this->search = '';
        $this->availability = 'all';
        $this->selectedSettingIds = $this->allAvailableSettingIds();
        $this->categoryIds = [];
        $this->categoryLabels = [];
        $this->brandIds = [];
        $this->brandLabels = [];
        $this->productIds = [];
        $this->productLabels = [];
        $this->expandedBusinesses = [];
        $this->clearSelectorSearches();

        $this->appliedFilters = $this->buildDraftFilterSnapshot();

        $this->suppressPaginationReload = true;
        try {
            $this->resetPage();
        } finally {
            $this->suppressPaginationReload = false;
        }

        if (!empty($this->appliedFilters['businessIds'])) {
            $this->loadAppliedPage($queryService, 1);
        } else {
            $this->clearLoadedState();
        }

        $this->dispatch('clear-search-inputs', target: 'all');
        $this->dispatch('sync-business-selector');
    }

    private function clearSelectorSearches(): void
    {
        $this->categorySearch = '';
        $this->categoryOptions = [];
        $this->hasMoreCategoryOptions = false;
        $this->brandSearch = '';
        $this->brandOptions = [];
        $this->hasMoreBrandOptions = false;
        $this->productSearch = '';
        $this->productOptions = [];
        $this->hasMoreProductOptions = false;
    }

    private function clearLoadedState(): void
    {
        $this->loadedRows = [];
        $this->loadedBusinesses = [];
        $this->loadedTotalRows = 0;
        $this->loadedPage = 1;
        $this->loadedFingerprint = '';
        $this->loadedPrices = [];
        $this->loadedPricesFingerprint = '';
    }

    private function computePageFingerprint(CrossBusinessStockInventoryFilterData $filter, int $page): string
    {
        return hash('sha256', json_encode([
            'filter' => $filter->hash(),
            'page' => $page,
            'authorized' => CrossBusinessStockInventoryFilterData::normalizeIds($this->allAvailableSettingIds()),
        ]));
    }

    private function loadAppliedPage(CrossBusinessStockInventoryQueryService $queryService, int $page = 1): void
    {
        $sanitizedBusinessIds = $this->getSanitizedSettingIds($this->appliedFilters['businessIds'] ?? []);

        if (empty($sanitizedBusinessIds)) {
            $this->appliedFilters['businessIds'] = [];
            $this->clearLoadedState();
            return;
        }

        $this->appliedFilters['businessIds'] = CrossBusinessStockInventoryFilterData::normalizeIds($sanitizedBusinessIds);
        $filterData = CrossBusinessStockInventoryFilterData::fromArray($this->appliedFilters);

        $includePrices = $this->showPrices && $this->canViewPrices();
        if (!$this->canViewPrices()) {
            $this->showPrices = false;
        }

        $data = $queryService->getReportData($filterData, self::PER_PAGE, max(1, $page), $includePrices);

        /** @var LengthAwarePaginator $paginator */
        $paginator = $data['paginator'];
        $rows = $data['rows']->values()->all();

        $pricesByProduct = [];
        $strippedRows = [];
        foreach ($rows as $row) {
            if ($includePrices && array_key_exists('prices', $row)) {
                $pricesByProduct[$row['id']] = $row['prices'];
                unset($row['prices']);
            }
            $strippedRows[] = $row;
        }

        $this->loadedPage = $paginator->currentPage();
        $this->loadedTotalRows = $paginator->total();
        $this->loadedRows = $strippedRows;
        $this->loadedBusinesses = $data['businesses']->values()->all();
        $this->loadedFingerprint = $this->computePageFingerprint($filterData, $this->loadedPage);

        if ($includePrices) {
            $this->loadedPrices = $pricesByProduct;
            $this->loadedPricesFingerprint = $this->loadedFingerprint;
        } else {
            $this->loadedPrices = [];
            $this->loadedPricesFingerprint = '';
        }
    }

    private function loadPricesForCurrentPage(CrossBusinessStockInventoryQueryService $queryService): void
    {
        if (!$this->showPrices || !$this->canViewPrices()) {
            $this->loadedPrices = [];
            $this->loadedPricesFingerprint = '';
            return;
        }

        $sanitizedBusinessIds = $this->getSanitizedSettingIds($this->appliedFilters['businessIds'] ?? []);
        $productIds = array_column($this->loadedRows, 'id');

        if (empty($sanitizedBusinessIds) || empty($productIds)) {
            $this->loadedPrices = [];
            $this->loadedPricesFingerprint = $this->loadedFingerprint;
            return;
        }

        $priceMatrix = $queryService->getPriceMatrix($productIds, $sanitizedBusinessIds);
        $pricesByProduct = [];
        foreach ($productIds as $pid) {
            $pid = (int) $pid;
            foreach ($sanitizedBusinessIds as $sid) {
                $pRow = $priceMatrix[$pid][$sid] ?? null;
                $pricesByProduct[$pid][$sid] = [
                    'average_purchase_price' => $pRow !== null ? $pRow['average_purchase_price'] : null,
                    'sale_price' => $pRow !== null ? $pRow['sale_price'] : null,
                    'tier_1_price' => $pRow !== null ? $pRow['tier_1_price'] : null,
                    'tier_2_price' => $pRow !== null ? $pRow['tier_2_price'] : null,
                ];
            }
        }

        $this->loadedPrices = $pricesByProduct;
        $this->loadedPricesFingerprint = $this->loadedFingerprint;
    }

    // --- Serial Dialog Actions ---
    public function openSerialDialog(
        int $productId,
        string $productName,
        int $settingId,
        string $businessName,
        ?int $locationId = null,
        string $locationName = 'Semua Lokasi',
        string $condition = 'good'
    ): void {
        abort_unless($this->canViewStockReport(), 403);

        $sanitizedIds = $this->getSanitizedSettingIds([$settingId]);
        abort_unless(in_array($settingId, $sanitizedIds, true), 403);
        abort_unless($this->locationBelongsToSetting($locationId, $settingId), 403);

        $this->dialogProductId = $productId;
        $this->dialogProductName = $productName;
        $this->dialogSettingId = $settingId;
        $this->dialogBusinessName = $businessName;
        $this->dialogLocationId = $locationId;
        $this->dialogLocationName = $locationName;
        $this->dialogCondition = $condition;
        $this->dialogSearch = '';
        $this->dialogPage = 1;
        $this->showSerialDialog = true;
    }

    public function closeSerialDialog(): void
    {
        $this->showSerialDialog = false;
        $this->dialogProductId = null;
        $this->dialogSettingId = null;
        $this->dialogLocationId = null;
        $this->dialogSearch = '';
    }

    public function updatedDialogSearch(): void
    {
        $this->dialogPage = 1;
    }

    public function setDialogPage(int $page): void
    {
        abort_unless($this->canViewStockReport(), 403);
        $this->dialogPage = $page;
    }

    // --- Excel Export ---
    public function exportExcel(CrossBusinessStockInventoryQueryService $queryService, ?string $content = null)
    {
        abort_unless($this->canViewStockReport(), 403);

        $mode = $content ?? $this->exportContent;
        $allowedModes = [
            CrossBusinessStockInventoryExport::CONTENT_STOCK,
            CrossBusinessStockInventoryExport::CONTENT_PRICES,
            CrossBusinessStockInventoryExport::CONTENT_STOCK_AND_PRICES,
        ];

        if (!in_array($mode, $allowedModes, true)) {
            throw ValidationException::withMessages([
                'exportContent' => 'Pilihan konten ekspor tidak valid.',
            ]);
        }

        if ($mode !== CrossBusinessStockInventoryExport::CONTENT_STOCK) {
            abort_unless($this->canViewPrices(), 403, 'Anda tidak memiliki izin untuk mengekspor harga produk.');
        }

        if ($this->hasPendingFilterChanges) {
            $this->addError('export', 'Filter telah diubah. Klik Terapkan Filter terlebih dahulu sebelum mengekspor.');
            $this->dispatch('alert', [
                'type' => 'error',
                'message' => 'Filter telah diubah. Klik Terapkan Filter terlebih dahulu sebelum mengekspor.',
            ]);
            return null;
        }

        $sanitizedBusinessIds = $this->getSanitizedSettingIds($this->appliedFilters['businessIds'] ?? []);
        if (empty($sanitizedBusinessIds)) {
            $this->addError('selectedSettingIds', 'Pilih minimal satu bisnis yang diizinkan sebelum mengekspor.');
            return null;
        }

        $filterData = CrossBusinessStockInventoryFilterData::fromArray(array_merge(
            $this->appliedFilters,
            ['businessIds' => $sanitizedBusinessIds]
        ));

        $businesses = $queryService->getBusinessHierarchy($filterData->businessIds);
        $rows = $queryService->getRowGeneratorForExport($filterData, 100, $mode);

        $prefix = match ($mode) {
            CrossBusinessStockInventoryExport::CONTENT_PRICES => 'harga-produk-lintas-bisnis_',
            CrossBusinessStockInventoryExport::CONTENT_STOCK_AND_PRICES => 'stok-dan-harga-lintas-bisnis_',
            default => 'stok-persediaan-lintas-bisnis_',
        };
        $filename = $prefix . now()->format('Y-m-d_His') . '.xlsx';

        return Excel::download(
            new CrossBusinessStockInventoryExport($rows, $businesses, $filterData, $this->displayMode, $mode),
            $filename
        );
    }

    private function buildAppliedSummary($availableSettings): array
    {
        if (empty($this->appliedFilters)) {
            return [];
        }

        $appliedSettingIds = $this->getSanitizedSettingIds($this->appliedFilters['businessIds'] ?? []);
        $scopeLabel = $this->getScopeLabel($availableSettings, $appliedSettingIds);

        $availabilityLabels = [
            'all' => 'Semua Stok',
            'available' => 'Tersedia (> 0)',
            'non_available' => 'Kosong (<= 0)',
        ];

        $resolveLabels = function (array $ids, array $labels): array {
            $out = [];
            foreach ($ids as $id) {
                $out[] = $labels[$id] ?? ('ID:' . $id);
            }
            return $out;
        };

        return [
            'scope' => $scopeLabel,
            'search' => (string) ($this->appliedFilters['search'] ?? ''),
            'availability' => $availabilityLabels[$this->appliedFilters['availability'] ?? 'all'] ?? 'Semua Stok',
            'categories' => $resolveLabels($this->appliedFilters['categoryIds'] ?? [], $this->appliedFilters['categoryLabels'] ?? []),
            'brands' => $resolveLabels($this->appliedFilters['brandIds'] ?? [], $this->appliedFilters['brandLabels'] ?? []),
            'products' => $resolveLabels($this->appliedFilters['productIds'] ?? [], $this->appliedFilters['productLabels'] ?? []),
        ];
    }

    public function render(CrossBusinessStockInventoryQueryService $queryService)
    {
        abort_unless($this->canViewStockReport(), 403);

        // Revalidate price permission before reusing any state
        $canViewPrices = $this->canViewPrices();
        if (!$canViewPrices) {
            $this->showPrices = false;
            $this->loadedPrices = [];
            $this->loadedPricesFingerprint = '';
            if ($this->exportContent !== CrossBusinessStockInventoryExport::CONTENT_STOCK) {
                $this->exportContent = CrossBusinessStockInventoryExport::CONTENT_STOCK;
            }
        }

        // Revalidate business scope against currently authorized businesses before reusing loaded page
        $sanitizedAppliedBusinessIds = $this->getSanitizedSettingIds($this->appliedFilters['businessIds'] ?? []);
        if (empty($sanitizedAppliedBusinessIds)) {
            $this->appliedFilters['businessIds'] = [];
            $this->clearLoadedState();
        } else {
            $filterData = CrossBusinessStockInventoryFilterData::fromArray(array_merge(
                $this->appliedFilters,
                ['businessIds' => $sanitizedAppliedBusinessIds]
            ));
            $expectedFingerprint = $this->computePageFingerprint($filterData, $this->loadedPage);

            if ($this->loadedFingerprint !== $expectedFingerprint) {
                $this->appliedFilters['businessIds'] = $filterData->businessIds;
                $this->loadAppliedPage($queryService, $this->loadedPage);
            } elseif ($this->showPrices && $canViewPrices && $this->loadedPricesFingerprint !== $this->loadedFingerprint) {
                $this->loadPricesForCurrentPage($queryService);
            }
        }

        $paginator = new LengthAwarePaginator(
            collect($this->loadedRows),
            $this->loadedTotalRows,
            self::PER_PAGE,
            $this->loadedPage,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );

        $serialNumbersPaginator = null;
        $dialogDiscrepancy = null;
        $dialogSettingAllowed = $this->dialogSettingId !== null
            && in_array($this->dialogSettingId, $this->getSanitizedSettingIds([$this->dialogSettingId]), true);
        $dialogLocationAllowed = $dialogSettingAllowed
            && $this->locationBelongsToSetting($this->dialogLocationId, $this->dialogSettingId);

        if ($this->showSerialDialog && $this->dialogProductId && $dialogSettingAllowed && $dialogLocationAllowed) {
            $serialNumbersPaginator = $queryService->getSerialNumbers(
                productId: $this->dialogProductId,
                settingId: $this->dialogSettingId,
                locationId: $this->dialogLocationId,
                condition: $this->dialogCondition,
                searchQuery: $this->dialogSearch,
                perPage: 10,
                page: $this->dialogPage
            );

            // Compute discrepancy without mutating balances
            $stockQuery = \Modules\Product\Entities\ProductStock::query()
                ->where('product_id', $this->dialogProductId)
                ->whereHas('location', function ($loc) {
                    $loc->where('setting_id', $this->dialogSettingId)
                        ->where('is_active', true);
                    if ($this->dialogLocationId !== null) {
                        $loc->where('id', $this->dialogLocationId);
                    }
                });

            $bucketTotal = $this->dialogCondition === 'bad'
                ? (float) ($stockQuery->sum('broken_quantity_tax') + $stockQuery->sum('broken_quantity_non_tax'))
                : (float) ($stockQuery->sum('quantity_tax') + $stockQuery->sum('quantity_non_tax'));

            $serialCount = (int) $serialNumbersPaginator->total();

            if (empty($this->dialogSearch) && (int) round($bucketTotal) !== $serialCount) {
                $dialogDiscrepancy = [
                    'bucket_total' => (int) round($bucketTotal),
                    'serial_count' => $serialCount,
                    'condition' => $this->dialogCondition === 'bad' ? 'Rusak' : 'Bagus (Siap Jual)',
                ];
            }
        }

        $availableSettings = $this->getAvailableSettings();

        return view('livewire.reports.cross-business-stock-inventory', [
            'paginator' => $paginator,
            'rows' => collect($this->loadedRows),
            'businesses' => collect($this->loadedBusinesses),
            'loadedPrices' => ($this->showPrices && $canViewPrices) ? $this->loadedPrices : [],
            'canViewPrices' => $canViewPrices,
            'showPrices' => $this->showPrices && $canViewPrices,
            'availableSettings' => $availableSettings,
            'dialogSerials' => $serialNumbersPaginator,
            'dialogDiscrepancy' => $dialogDiscrepancy,
            'displayMode' => $this->displayMode,
            'hasPendingFilterChanges' => $this->hasPendingFilterChanges,
            'appliedSummary' => $this->buildAppliedSummary($availableSettings),
        ]);
    }

    private function locationBelongsToSetting(?int $locationId, ?int $settingId): bool
    {
        if ($locationId === null) {
            return true;
        }

        if ($settingId === null) {
            return false;
        }

        return Location::query()
            ->where('id', $locationId)
            ->where('setting_id', $settingId)
            ->where('is_active', true)
            ->exists();
    }
}
