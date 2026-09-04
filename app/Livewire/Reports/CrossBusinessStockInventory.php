<?php

namespace App\Livewire\Reports;

use App\Exports\CrossBusinessStockInventoryExport;
use App\Services\Reports\CrossBusinessStockInventoryFilterData;
use App\Services\Reports\CrossBusinessStockInventoryQueryService;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use Modules\Setting\Entities\Setting;

class CrossBusinessStockInventory extends Component
{
    use WithPagination;

    // Filters
    public string $search = '';
    public array $selectedSettingIds = [];
    public string $availability = 'all'; // 'all', 'available', 'non_available'
    public array $categoryIds = [];
    public array $categoryLabels = [];
    public array $brandIds = [];
    public array $brandLabels = [];

    // Filter drawer live-search state
    public string $categorySearch = '';
    public array $categoryOptions = [];
    public string $brandSearch = '';
    public array $brandOptions = [];

    // Expanded column state: map of setting_id => boolean
    public array $expandedBusinesses = [];

    // Serial Dialog state
    public bool $showSerialDialog = false;
    public ?int $dialogProductId = null;
    public string $dialogProductName = '';
    public ?int $dialogSettingId = null;
    public string $dialogBusinessName = '';
    public ?int $dialogLocationId = null;
    public string $dialogLocationName = 'Semua Lokasi';
    public string $dialogCondition = 'good'; // 'good' or 'bad'
    public string $dialogSearch = '';
    public int $dialogPage = 1;

    // Pagination theme
    protected $paginationTheme = 'bootstrap';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('inventory.view_remaining_stock'), 403);

        // Default-selected businesses = all businesses visible to acting user
        $availableSettings = $this->getAvailableSettings();
        $this->selectedSettingIds = array_map('intval', array_column($availableSettings, 'id'));
    }

    /**
     * Local component-scoped method returning available businesses:
     * Super Admin sees all settings, else auth()->user()->settings(),
     * mirroring BusinessSelector.php:23-39
     */
    public function getAvailableSettings(): array
    {
        $user = auth()->user();

        if ($user->hasRole('Super Admin')) {
            return Setting::query()
                ->orderBy('company_name', 'asc')
                ->get()
                ->map(fn ($s) => ['id' => $s->id, 'company_name' => $s->company_name])
                ->toArray();
        }

        return $user->settings()
            ->orderBy('company_name', 'asc')
            ->get()
            ->map(fn ($s) => ['id' => $s->id, 'company_name' => $s->company_name])
            ->toArray();
    }

    /**
     * Ensure only businesses visible to acting user can be selected.
     */
    public function getSanitizedSelectedSettingIds(): array
    {
        $allowedIds = array_map('intval', array_column($this->getAvailableSettings(), 'id'));
        $selected = array_map('intval', $this->selectedSettingIds);

        return array_values(array_intersect($selected, $allowedIds));
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
        $availableSettings = $this->getAvailableSettings();
        $this->selectedSettingIds = array_map('intval', array_column($availableSettings, 'id'));
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
        $this->dialogPage = $page;
    }

    // Excel Export
    public function exportExcel(CrossBusinessStockInventoryQueryService $queryService)
    {
        abort_unless(auth()->user()->can('inventory.view_remaining_stock'), 403);

        $filterData = new CrossBusinessStockInventoryFilterData(
            search: $this->search,
            businessIds: $this->getSanitizedSelectedSettingIds(),
            categoryIds: $this->categoryIds,
            brandIds: $this->brandIds,
            availability: $this->availability
        );

        $exportData = $queryService->getAllRowsForExport($filterData);

        $filename = 'stok-persediaan-lintas-bisnis_' . now()->format('Y-m-d_His') . '.xlsx';

        return Excel::download(
            new CrossBusinessStockInventoryExport($exportData['rows'], $exportData['businesses'], $filterData),
            $filename
        );
    }

    public function render(CrossBusinessStockInventoryQueryService $queryService)
    {
        $filterData = new CrossBusinessStockInventoryFilterData(
            search: $this->search,
            businessIds: $this->getSanitizedSelectedSettingIds(),
            categoryIds: $this->categoryIds,
            brandIds: $this->brandIds,
            availability: $this->availability
        );

        $data = $queryService->getReportData($filterData, 15, $this->getPage());

        $serialNumbersPaginator = null;
        if ($this->showSerialDialog && $this->dialogProductId && $this->dialogSettingId) {
            $serialNumbersPaginator = $queryService->getSerialNumbers(
                productId: $this->dialogProductId,
                settingId: $this->dialogSettingId,
                locationId: $this->dialogLocationId,
                condition: $this->dialogCondition,
                searchQuery: $this->dialogSearch,
                perPage: 10,
                page: $this->dialogPage
            );
        }

        return view('livewire.reports.cross-business-stock-inventory', [
            'paginator' => $data['paginator'],
            'rows' => $data['rows'],
            'businesses' => $data['businesses'],
            'availableSettings' => $this->getAvailableSettings(),
            'dialogSerials' => $serialNumbersPaginator,
        ]);
    }
}
