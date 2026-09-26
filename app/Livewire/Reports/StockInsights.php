<?php

namespace App\Livewire\Reports;

use App\Services\Reports\StockInsightsFilterData;
use App\Services\Reports\StockInsightsQueryService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;

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

    // Modal state for inline minimum stock update
    public bool $showMinimumModal = false;
    public ?int $modalProductId = null;
    public string $modalProductName = '';
    public string $modalProductCode = '';
    public ?string $modalBarcode = null;
    public float $modalGlobalGoodStock = 0.0;
    public float $modalTaxGood = 0.0;
    public float $modalNonTaxGood = 0.0;
    public float $modalTaxBroken = 0.0;
    public float $modalNonTaxBroken = 0.0;
    public float $modalSoldQuantity = 0.0;
    public float $modalSalesValue = 0.0;
    public ?string $modalLastSaleDate = null;
    public string $modalMinimumInput = '';
    public string $modalErrorMessage = '';
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

    public function openMinimumModal(int $productId): void
    {
        abort_unless(auth()->user()->can('stockInsights.access'), 403);

        $product = Product::query()
            ->where('id', $productId)
            ->where('is_active', true)
            ->whereNull('merged_into_id')
            ->where('stock_managed', true)
            ->firstOrFail();

        $queryService = app(StockInsightsQueryService::class);
        $stockMatrix = $queryService->getStockMatrix([$productId]);
        $stockBucket = $stockMatrix[$productId]['global'] ?? null;

        $today = Carbon::today()->format('Y-m-d');
        $financialAggregates = $queryService->getSalesAndFinancialAggregates([$productId], $this->startDate, $today);
        $financial = $financialAggregates[$productId] ?? [];

        $this->modalProductId = $productId;
        $this->modalProductName = (string) $product->product_name;
        $this->modalProductCode = (string) $product->product_code;
        $this->modalBarcode = $product->barcode ? (string) $product->barcode : null;

        $this->modalGlobalGoodStock = $stockBucket ? $stockBucket->totalGood : 0.0;
        $this->modalTaxGood = $stockBucket ? $stockBucket->taxGood : 0.0;
        $this->modalNonTaxGood = $stockBucket ? $stockBucket->nonTaxGood : 0.0;
        $this->modalTaxBroken = $stockBucket ? $stockBucket->taxBroken : 0.0;
        $this->modalNonTaxBroken = $stockBucket ? $stockBucket->nonTaxBroken : 0.0;

        $this->modalSoldQuantity = (float) ($financial['sold_quantity'] ?? 0.0);
        $this->modalSalesValue = (float) ($financial['sales_value'] ?? 0.0);
        $this->modalLastSaleDate = $financial['last_sale_date'] ?? null;

        $this->modalMinimumInput = (string) ($product->product_stock_alert ?? 0);
        $this->modalErrorMessage = '';
        $this->showMinimumModal = true;
    }

    public function closeMinimumModal(): void
    {
        $this->showMinimumModal = false;
        $this->modalProductId = null;
        $this->modalErrorMessage = '';
    }

    public function updateMinimumStock(int $productId, int $newMinimum): void
    {
        abort_unless(auth()->user()->can('stockInsights.access'), 403);

        $this->openMinimumModal($productId);
        $this->modalMinimumInput = (string) $newMinimum;
        $this->saveMinimumStock();
    }

    public function saveMinimumStock(): void
    {
        abort_unless(auth()->user()->can('stockInsights.access'), 403);

        if (!$this->modalProductId) {
            return;
        }

        $minInput = trim($this->modalMinimumInput);
        if (!ctype_digit($minInput) || (int) $minInput < 0) {
            $this->modalErrorMessage = 'Batas minimum stok harus berupa angka bulat positif (0 atau lebih).';
            return;
        }

        $newMinimum = (int) $minInput;

        try {
            DB::transaction(function () use ($newMinimum) {
                $product = Product::query()
                    ->where('id', $this->modalProductId)
                    ->where('is_active', true)
                    ->whereNull('merged_into_id')
                    ->where('stock_managed', true)
                    ->lockForUpdate()
                    ->firstOrFail();

                $oldAlert = (int) ($product->product_stock_alert ?? 0);
                $product->product_stock_alert = $newMinimum;
                $product->save();

                // Audit trail via OwenIt\Auditing or ActivityLog if available, or direct audit logging
                if (method_exists($product, 'audits')) {
                    // Standard auditable handles it via saving
                }
            });

            $savedName = $this->modalProductName;
            $productId = $this->modalProductId;
            $this->closeMinimumModal();

            $leavingNotice = '';
            if (!empty($this->statuses)) {
                // Check if product still matches any of active statuses
                $queryService = app(StockInsightsQueryService::class);
                $singleFilter = new StockInsightsFilterData(
                    statuses: $this->statuses,
                    today: Carbon::today()
                );
                // Check if product is still returned in base query with status filter
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

            $this->feedbackMessage = "Batas minimum stok untuk '{$savedName}' berhasil diperbarui menjadi {$newMinimum}.{$leavingNotice}";
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Gagal menyimpan batas minimum stok produk ' . ($this->modalProductId ?? 'unknown') . ': ' . $e->getMessage(), [
                'exception' => $e,
                'product_id' => $this->modalProductId,
                'minimum_input' => $this->modalMinimumInput,
            ]);
            $this->modalErrorMessage = 'Terjadi kesalahan saat menyimpan batas minimum stok. Silakan coba lagi.';
        }
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
