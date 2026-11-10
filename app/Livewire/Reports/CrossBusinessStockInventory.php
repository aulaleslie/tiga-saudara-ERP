<?php

namespace App\Livewire\Reports;

use App\Exports\CrossBusinessStockInventoryExport;
use App\Services\Reports\CrossBusinessStockInventoryFilterData;
use App\Services\Reports\CrossBusinessStockInventoryQueryService;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

class CrossBusinessStockInventory extends Component
{
    use WithPagination;
    use HasReportSettingScope;

    // Filters
    public string $search = '';
    public string $availability = 'all'; // 'all', 'available', 'non_available'
    public array $categoryIds = [];
    public array $categoryLabels = [];
    public array $brandIds = [];
    public array $brandLabels = [];

    // Quantity display mode: 'decimal' or 'conversion'
    public string $displayMode = 'decimal';

    // Filter drawer live-search state
    public string $categorySearch = '';
    public array $categoryOptions = [];
    public string $brandSearch = '';
    public array $brandOptions = [];

    // Expanded column state: map of setting_id => boolean
    public array $expandedBusinesses = [];

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

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('inventory.view_remaining_stock') ?? false, 403);

        // Default-selected businesses = all businesses visible to acting user
        $this->selectedSettingIds = $this->allAvailableSettingIds();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
        $this->dispatch('clear-search-inputs', target: 'search');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedAvailability(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedSettingIds(): void
    {
        $this->resetPage();
    }

    public function updatedDisplayMode($value): void
    {
        if (!in_array($value, ['decimal', 'conversion'], true)) {
            $this->displayMode = 'decimal';
        }
    }

    public function toggleBusinessExpand(int $settingId): void
    {
        $this->expandedBusinesses[$settingId] = !($this->expandedBusinesses[$settingId] ?? false);
    }

    // Category filter methods
    public function updatedCategorySearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->categoryOptions = [];
            return;
        }

        $this->categoryOptions = Category::query()
            ->whereRaw('LOWER(category_name) LIKE ?', ['%' . mb_strtolower($value) . '%'])
            ->limit(10)
            ->get(['id', 'category_name'])
            ->toArray();
    }

    public function selectCategory(int $id, string $name): void
    {
        if (!in_array($id, $this->categoryIds)) {
            $this->categoryIds[] = $id;
            $this->categoryLabels[$id] = $name;
        }
        $this->categorySearch = '';
        $this->categoryOptions = [];
        $this->resetPage();
        $this->dispatch('clear-search-inputs', target: 'category');
    }

    public function removeCategory(int $id): void
    {
        $this->categoryIds = array_values(array_diff($this->categoryIds, [$id]));
        unset($this->categoryLabels[$id]);
        $this->resetPage();
    }

    // Brand filter methods
    public function updatedBrandSearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->brandOptions = [];
            return;
        }

        $this->brandOptions = Brand::query()
            ->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($value) . '%'])
            ->limit(10)
            ->get(['id', 'name'])
            ->toArray();
    }

    public function selectBrand(int $id, string $name): void
    {
        if (!in_array($id, $this->brandIds)) {
            $this->brandIds[] = $id;
            $this->brandLabels[$id] = $name;
        }
        $this->brandSearch = '';
        $this->brandOptions = [];
        $this->resetPage();
        $this->dispatch('clear-search-inputs', target: 'brand');
    }

    public function removeBrand(int $id): void
    {
        $this->brandIds = array_values(array_diff($this->brandIds, [$id]));
        unset($this->brandLabels[$id]);
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->availability = 'all';
        $this->selectedSettingIds = $this->allAvailableSettingIds();
        $this->categoryIds = [];
        $this->categoryLabels = [];
        $this->brandIds = [];
        $this->brandLabels = [];
        $this->categorySearch = '';
        $this->categoryOptions = [];
        $this->brandSearch = '';
        $this->brandOptions = [];
        $this->expandedBusinesses = [];
        $this->resetPage();
        $this->dispatch('clear-search-inputs', target: 'all');
    }

    // Serial Dialog Actions
    public function openSerialDialog(
        int $productId,
        string $productName,
        int $settingId,
        string $businessName,
        ?int $locationId = null,
        string $locationName = 'Semua Lokasi',
        string $condition = 'good'
    ): void {
        abort_unless(auth()->user()?->can('inventory.view_remaining_stock') ?? false, 403);

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
        abort_unless(auth()->user()?->can('inventory.view_remaining_stock') ?? false, 403);
        $this->dialogPage = $page;
    }

    // Excel Export
    public function exportExcel(CrossBusinessStockInventoryQueryService $queryService)
    {
        abort_unless(auth()->user()?->can('inventory.view_remaining_stock') ?? false, 403);

        $filterData = new CrossBusinessStockInventoryFilterData(
            search: $this->search,
            businessIds: $this->getSanitizedSettingIds(),
            categoryIds: $this->categoryIds,
            brandIds: $this->brandIds,
            availability: $this->availability
        );

        $businesses = $queryService->getBusinessHierarchy($filterData->businessIds);
        $rows = $queryService->getRowGeneratorForExport($filterData);

        $filename = 'stok-persediaan-lintas-bisnis_' . now()->format('Y-m-d_His') . '.xlsx';

        return Excel::download(
            new CrossBusinessStockInventoryExport($rows, $businesses, $filterData, $this->displayMode),
            $filename
        );
    }

    public function render(CrossBusinessStockInventoryQueryService $queryService)
    {
        $filterData = new CrossBusinessStockInventoryFilterData(
            search: $this->search,
            businessIds: $this->getSanitizedSettingIds(),
            categoryIds: $this->categoryIds,
            brandIds: $this->brandIds,
            availability: $this->availability
        );

        $data = $queryService->getReportData($filterData, 15, $this->getPage());

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

        return view('livewire.reports.cross-business-stock-inventory', [
            'paginator' => $data['paginator'],
            'rows' => $data['rows'],
            'businesses' => $data['businesses'],
            'availableSettings' => $this->getAvailableSettings(),
            'dialogSerials' => $serialNumbersPaginator,
            'dialogDiscrepancy' => $dialogDiscrepancy,
            'displayMode' => $this->displayMode,
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
