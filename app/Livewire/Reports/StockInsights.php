<?php

namespace App\Livewire\Reports;

use App\Services\Reports\StockInsightsFilterData;
use App\Services\Reports\StockInsightsQueryService;
use Carbon\Carbon;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;

class StockInsights extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    // Filters
    public string $search = '';
    public array $statuses = [];
    public array $categoryIds = [];
    public array $brandIds = [];
    public string $startDate = '';
    public string $preset = '7'; // '7', '30', '90', or 'custom'
    public string $sortColumn = 'default';
    public string $sortDirection = 'asc';
    public int $perPage = 25;

    // Hierarchy expansion state
    public bool $isGlobalExpanded = false;
    public array $expandedBusinesses = []; // settingId => bool

    public string $feedbackMessage = '';

    protected $queryString = [
        'search' => ['except' => ''],
        'sortColumn' => ['except' => 'default'],
        'sortDirection' => ['except' => 'asc'],
    ];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('stockInsights.access'), 403);

        $today = Carbon::today();
        $this->startDate = $today->copy()->subDays(6)->format('Y-m-d');
        $this->preset = '7';
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatuses(): void
    {
        $this->resetPage();
    }

    public function toggleStatus(string $status): void
    {
        if (in_array($status, $this->statuses, true)) {
            $this->statuses = array_values(array_diff($this->statuses, [$status]));
        } else {
            $this->statuses[] = $status;
        }
        $this->resetPage();
    }

    public function updatedCategoryIds(): void
    {
        $this->resetPage();
    }

    public function updatedBrandIds(): void
    {
        $this->resetPage();
    }

    public function updatedPreset($value): void
    {
        $today = Carbon::today();
        if (in_array((string) $value, ['7', '30', '90'], true)) {
            $days = (int) $value;
            $this->startDate = $today->copy()->subDays($days - 1)->format('Y-m-d');
            $this->resetPage();
        }
    }

    public function updatedStartDate($value): void
    {
        $today = Carbon::today();
        if (empty($value)) {
            $this->startDate = $today->copy()->subDays(6)->format('Y-m-d');
            $this->preset = '7';
            $this->resetPage();
            return;
        }

        $parsed = Carbon::parse($value)->startOfDay();
        if ($parsed->greaterThan($today)) {
            $this->addError('startDate', 'Tanggal mulai tidak boleh lebih dari hari ini.');
            return;
        }

        $this->resetErrorBag('startDate');
        $diff = (int) $parsed->diffInDays($today) + 1;
        if (in_array($diff, [7, 30, 90], true)) {
            $this->preset = (string) $diff;
        } else {
            $this->preset = 'custom';
        }
        $this->resetPage();

        $this->dispatch('sync-select2-preset', [
            'values' => $this->preset,
            'label' => "{$diff} Hari Terakhir",
        ]);
    }

    public function sortBy(string $column): void
    {
        if (!in_array($column, StockInsightsFilterData::ALLOWED_SORTS, true) || $column === 'default') {
            return;
        }

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = in_array($column, ['sold_quantity', 'sales_value', 'sold_cost', 'gross_profit'], true) ? 'desc' : 'asc';
        }

        $this->resetPage();
    }

    public function toggleGlobalExpansion(): void
    {
        $this->isGlobalExpanded = !$this->isGlobalExpanded;
    }

    public function toggleBusinessExpansion(int $settingId): void
    {
        $current = $this->expandedBusinesses[$settingId] ?? $this->expandedBusinesses[(string) $settingId] ?? false;
        $this->expandedBusinesses[$settingId] = !$current;
        $this->expandedBusinesses[(string) $settingId] = !$current;
    }

    #[On('stock-insights-minimum-saved')]
    public function onMinimumSaved(int $productId, string $productName, int $newMinimum): void
    {
        $leavingNotice = '';
        if (!empty($this->statuses)) {
            $queryService = app(StockInsightsQueryService::class);
            $singleFilter = new StockInsightsFilterData(
                statuses: $this->statuses,
                today: Carbon::today()
            );
            $stillMatches = $queryService->getEligibleProductsBaseQuery()
                ->where('products.id', $productId)
                ->where(function ($q) use ($singleFilter) {
                    $goodStockSql = StockInsightsQueryService::globalGoodStockSqlExpression();
                    $slowMovingSql = StockInsightsQueryService::slowMovingSqlExpression($singleFilter->endDate);
                    foreach ($singleFilter->statuses as $status) {
                        if ($status === StockInsightsFilterData::STATUS_OUT_OF_STOCK) {
                            $q->orWhereRaw("({$goodStockSql} <= 0)");
                        } elseif ($status === StockInsightsFilterData::STATUS_REORDER_REQUIRED) {
                            $q->orWhereRaw("(COALESCE(products.product_stock_alert, 0) > 0 AND {$goodStockSql} > 0 AND {$goodStockSql} <= products.product_stock_alert)");
                        } elseif ($status === StockInsightsFilterData::STATUS_MINIMUM_UNSET) {
                            $q->orWhereRaw("(COALESCE(products.product_stock_alert, 0) = 0)");
                        } elseif ($status === StockInsightsFilterData::STATUS_SLOW_MOVING) {
                            $q->orWhereRaw($slowMovingSql);
                        }
                    }
                })
                ->exists();

            if (!$stillMatches) {
                $leavingNotice = ' Produk tidak lagi memenuhi filter status yang aktif dan telah dikeluarkan dari tampilan saat ini.';
            }
        }

        $this->feedbackMessage = "Batas minimum stok untuk '{$productName}' berhasil diperbarui menjadi {$newMinimum}.{$leavingNotice}";
    }

    public function resetFilters(): void
    {
        $today = Carbon::today();
        $this->search = '';
        $this->statuses = [];
        $this->categoryIds = [];
        $this->brandIds = [];
        $this->startDate = $today->copy()->subDays(6)->format('Y-m-d');
        $this->preset = '7';
        $this->sortColumn = 'default';
        $this->sortDirection = 'asc';
        $this->resetPage();

        $this->dispatch('sync-select2-categoryIds', ['values' => []]);
        $this->dispatch('sync-select2-brandIds', ['values' => []]);
        $this->dispatch('sync-select2-preset', ['values' => '7']);
    }

    public function render()
    {
        abort_unless(auth()->user()->can('stockInsights.access'), 403);

        $queryService = app(StockInsightsQueryService::class);
        $today = Carbon::today();

        $effectiveStartDate = $this->startDate;
        if (!empty($effectiveStartDate)) {
            $parsed = Carbon::parse($effectiveStartDate)->startOfDay();
            if ($parsed->greaterThan($today)) {
                $effectiveStartDate = $today->copy()->subDays(6)->format('Y-m-d');
            }
        }

        $filter = new StockInsightsFilterData(
            search: $this->search,
            statuses: $this->statuses,
            categoryIds: $this->categoryIds,
            brandIds: $this->brandIds,
            startDate: $effectiveStartDate,
            sortColumn: $this->sortColumn,
            sortDirection: $this->sortDirection,
            perPage: $this->perPage,
            page: $this->getPage(),
            today: $today
        );

        $attentionCounts = $queryService->getAttentionCounts($filter);
        $paginatedRows = $queryService->paginate($filter);
        $hierarchy = $queryService->getBusinessHierarchy();
        $categories = Category::query()->orderBy('category_name')->get(['id', 'category_name']);
        $brands = Brand::query()->orderBy('name')->get(['id', 'name']);

        // Check if custom duration
        $durationDays = $filter->durationDays;
        $periodLabel = $filter->getPeriodLabel();

        return view('livewire.reports.stock-insights', [
            'rows' => $paginatedRows,
            'attentionCounts' => $attentionCounts,
            'hierarchy' => $hierarchy,
            'categories' => $categories,
            'brands' => $brands,
            'durationDays' => $durationDays,
            'periodLabel' => $periodLabel,
        ]);
    }
}
