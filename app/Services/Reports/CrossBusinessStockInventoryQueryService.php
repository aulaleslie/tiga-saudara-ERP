<?php

namespace App\Services\Reports;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

class CrossBusinessStockInventoryQueryService
{
    /**
     * Build the query for products matching search, categories, and brands.
     * 
     * Search combines:
     * (1) Multi-token order-independent search on name, code, category name, brand name (reusing Product::scopeGlobalSearch token logic)
     * (2) Exact-match on products.barcode OR product_serial_numbers.serial_number
     */
    public function getFilteredProductsQuery(CrossBusinessStockInventoryFilterData $filters): Builder
    {
        $query = Product::query()->active()->with(['category', 'brand']);

        if (!empty($filters->search)) {
            $search = $filters->search;
            $tokens = array_filter(explode(' ', $search), 'strlen');

            $query->where(function (Builder $q) use ($search, $tokens) {
                // Path 1: Multi-token product identity search
                $q->where(function (Builder $tokenQuery) use ($tokens) {
                    foreach ($tokens as $token) {
                        $tokenQuery->where(function (Builder $sub) use ($token) {
                            $sub->where('product_name', 'like', '%' . $token . '%')
                                ->orWhere('product_code', 'like', '%' . $token . '%')
                                ->orWhere('barcode', 'like', '%' . $token . '%')
                                ->orWhereHas('category', function ($cat) use ($token) {
                                    $cat->where('category_name', 'like', '%' . $token . '%');
                                })
                                ->orWhereHas('brand', function ($brand) use ($token) {
                                    $brand->where('name', 'like', '%' . $token . '%');
                                });
                        });
                    }
                });

                // Path 2: Exact barcode match
                $q->orWhere('barcode', '=', $search);

                // Path 3: Exact serial number match resolving to product
                $q->orWhereHas('serialNumbers', function ($sn) use ($search) {
                    $sn->where('serial_number', '=', $search);
                });
            });
        }

        // Category filter (multi-select with OR semantics, including cycle-safe descendants)
        if (!empty($filters->categoryIds)) {
            $expandedCategoryIds = $this->categoryTreeResolver->expandWithDescendants($filters->categoryIds);
            if (!empty($expandedCategoryIds)) {
                $query->whereIn('category_id', $expandedCategoryIds);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // Brand filter (multi-select with OR semantics)
        if (!empty($filters->brandIds)) {
            $query->whereIn('brand_id', $filters->brandIds);
        }

        // Product filter (global product identity, multi-select with OR semantics)
        if (!empty($filters->productIds)) {
            $query->whereIn('products.id', $filters->productIds);
        }

        // Availability filter applied at SQL level before pagination:
        // Computes total stock (good + bad) across visible businesses and active locations.
        // This avoids pulling all products into PHP memory when filtering by availability.
        if ($filters->availability !== 'all' && !empty($filters->businessIds)) {
            $stockSubquery = DB::table('product_stocks')
                ->join('locations', 'product_stocks.location_id', '=', 'locations.id')
                ->where('locations.is_active', true)
                ->whereIn('locations.setting_id', $filters->businessIds)
                ->whereColumn('product_stocks.product_id', 'products.id')
                ->selectRaw('COALESCE(SUM(quantity_tax + quantity_non_tax + broken_quantity_tax + broken_quantity_non_tax), 0)');

            if ($filters->availability === 'available') {
                $query->whereRaw("({$stockSubquery->toSql()}) > 0", $stockSubquery->getBindings());
            } elseif ($filters->availability === 'non_available') {
                $query->whereRaw("({$stockSubquery->toSql()}) <= 0", $stockSubquery->getBindings());
            }
        }

        return $query->orderBy('product_name', 'asc')->orderBy('id', 'asc');
    }

    /**
     * Load settings and locations for the selected businesses.
     * Returns a collection of Setting models with their active locations.
     */
    public function getBusinessHierarchy(array $businessIds): Collection
    {
        if (empty($businessIds)) {
            return collect();
        }

        $settings = Setting::query()
            ->whereIn('id', $businessIds)
            ->orderBy('company_name', 'asc')
            ->get();

        // Fetch all relevant locations once and group them in memory to avoid
        // issuing one locations query per business.
        $locationsBySetting = Location::query()
            ->whereIn('setting_id', $businessIds)
            ->where('is_active', true)
            ->orderBy('name', 'asc')
            ->orderBy('id', 'asc')
            ->get(['id', 'name', 'setting_id'])
            ->groupBy('setting_id');

        return $settings->map(function (Setting $setting) use ($locationsBySetting) {
            $locations = $locationsBySetting->get($setting->id, collect());

            return [
                'setting_id' => $setting->id,
                'company_name' => $setting->company_name,
                'is_pkp' => (bool) $setting->is_pkp,
                'locations' => $locations->map(fn ($loc) => [
                    'id' => $loc->id,
                    'name' => $loc->name,
                    'setting_id' => $loc->setting_id,
                ])->all(),
            ];
        });
    }

    /**
     * Aggregate stock data for a given set of product IDs and business IDs.
     * 
     * Returns a nested array:
     * [
     *   productId => [
     *     'total_good' => float,
     *     'total_bad' => float,
     *     'businesses' => [
     *        settingId => [
     *          'good' => float,
     *          'bad' => float,
     *          'tax_good' => float,
     *          'non_tax_good' => float,
     *          'tax_bad' => float,
     *          'non_tax_bad' => float,
     *          'locations' => [
     *             locationId => [
     *               'good' => float,
     *               'bad' => float,
     *               'tax_good' => float,
     *               'non_tax_good' => float,
     *               'tax_bad' => float,
     *               'non_tax_bad' => float,
     *             ]
     *          ]
     *        ]
     *     ]
     *   ]
     * ]
     */
    public function getStockMatrix(array $productIds, array $businessIds): array
    {
        if (empty($productIds) || empty($businessIds)) {
            return [];
        }

        $rawStocks = DB::table('product_stocks')
            ->join('locations', 'product_stocks.location_id', '=', 'locations.id')
            ->where('locations.is_active', true)
            ->whereIn('product_stocks.product_id', $productIds)
            ->whereIn('locations.setting_id', $businessIds)
            ->select([
                'product_stocks.product_id',
                'locations.setting_id',
                'locations.id as location_id',
                DB::raw('COALESCE(SUM(product_stocks.quantity_tax), 0) as quantity_tax'),
                DB::raw('COALESCE(SUM(product_stocks.quantity_non_tax), 0) as quantity_non_tax'),
                DB::raw('COALESCE(SUM(product_stocks.broken_quantity_tax), 0) as broken_quantity_tax'),
                DB::raw('COALESCE(SUM(product_stocks.broken_quantity_non_tax), 0) as broken_quantity_non_tax'),
            ])
            ->groupBy('product_stocks.product_id', 'locations.setting_id', 'locations.id')
            ->get();

        $matrix = [];

        foreach ($rawStocks as $row) {
            $productId = (int) $row->product_id;
            $settingId = (int) $row->setting_id;
            $locationId = (int) $row->location_id;

            $qTax = (float) $row->quantity_tax;
            $qNonTax = (float) $row->quantity_non_tax;
            $bTax = (float) $row->broken_quantity_tax;
            $bNonTax = (float) $row->broken_quantity_non_tax;

            $good = $qTax + $qNonTax;
            $bad = $bTax + $bNonTax;

            if (!isset($matrix[$productId])) {
                $matrix[$productId] = [
                    'total_good' => 0.0,
                    'total_bad' => 0.0,
                    'businesses' => [],
                ];
            }

            if (!isset($matrix[$productId]['businesses'][$settingId])) {
                $matrix[$productId]['businesses'][$settingId] = [
                    'good' => 0.0,
                    'bad' => 0.0,
                    'tax_good' => 0.0,
                    'non_tax_good' => 0.0,
                    'tax_bad' => 0.0,
                    'non_tax_bad' => 0.0,
                    'locations' => [],
                ];
            }

            $matrix[$productId]['total_good'] += $good;
            $matrix[$productId]['total_bad'] += $bad;

            $matrix[$productId]['businesses'][$settingId]['good'] += $good;
            $matrix[$productId]['businesses'][$settingId]['bad'] += $bad;
            $matrix[$productId]['businesses'][$settingId]['tax_good'] += $qTax;
            $matrix[$productId]['businesses'][$settingId]['non_tax_good'] += $qNonTax;
            $matrix[$productId]['businesses'][$settingId]['tax_bad'] += $bTax;
            $matrix[$productId]['businesses'][$settingId]['non_tax_bad'] += $bNonTax;

            $matrix[$productId]['businesses'][$settingId]['locations'][$locationId] = [
                'good' => $good,
                'bad' => $bad,
                'tax_good' => $qTax,
                'non_tax_good' => $qNonTax,
                'tax_bad' => $bTax,
                'non_tax_bad' => $bNonTax,
            ];
        }

        return $matrix;
    }

    /**
     * Compute tooltip information based on is_pkp and stock buckets.
     * If is_pkp is true, unexpected is non_tax (non_tax_good or non_tax_bad).
     * If is_pkp is false, unexpected is tax (tax_good or tax_bad).
     * Returns tooltip string or null if no mismatch.
     */
    public static function computeTooltip(bool $isPkp, float $taxQty, float $nonTaxQty): ?string
    {
        if ($isPkp) {
            if ($nonTaxQty > 0) {
                return 'Non-tax: ' . (float) $nonTaxQty;
            }
        } else {
            if ($taxQty > 0) {
                return 'Tax: ' . (float) $taxQty;
            }
        }

        return null;
    }

    protected CrossBusinessInDeliveryStockService $inDeliveryService;
    protected SaleByProductCategoryTreeResolver $categoryTreeResolver;

    public function __construct(
        ?CrossBusinessInDeliveryStockService $inDeliveryService = null,
        ?SaleByProductCategoryTreeResolver $categoryTreeResolver = null
    ) {
        $this->inDeliveryService = $inDeliveryService ?? new CrossBusinessInDeliveryStockService();
        $this->categoryTreeResolver = $categoryTreeResolver ?? new SaleByProductCategoryTreeResolver();
    }

    /**
     * Bulk-load current stored business prices for a bounded set of products and authorized businesses.
     *
     * Queries product_prices directly (no legacy Product accessors, no fallback across businesses,
     * no default-to-zero coercion). Missing rows are omitted; null fields remain null; saved 0.00
     * remains numeric 0.0.
     *
     * @param  array<int>  $productIds
     * @param  array<int>  $businessIds
     * @return array<int, array<int, array{average_purchase_price: ?float, sale_price: ?float, tier_1_price: ?float, tier_2_price: ?float}>>
     */
    public function getPriceMatrix(array $productIds, array $businessIds): array
    {
        $productIds = CrossBusinessStockInventoryFilterData::normalizeIds($productIds);
        $businessIds = CrossBusinessStockInventoryFilterData::normalizeIds($businessIds);

        if (empty($productIds) || empty($businessIds)) {
            return [];
        }

        $rawPrices = DB::table('product_prices')
            ->whereIn('product_id', $productIds)
            ->whereIn('setting_id', $businessIds)
            ->get([
                'product_id',
                'setting_id',
                'average_purchase_price',
                'sale_price',
                'tier_1_price',
                'tier_2_price',
            ]);

        $matrix = [];

        foreach ($rawPrices as $row) {
            $productId = (int) $row->product_id;
            $settingId = (int) $row->setting_id;

            $matrix[$productId][$settingId] = [
                'average_purchase_price' => $row->average_purchase_price !== null ? (float) $row->average_purchase_price : null,
                'sale_price' => $row->sale_price !== null ? (float) $row->sale_price : null,
                'tier_1_price' => $row->tier_1_price !== null ? (float) $row->tier_1_price : null,
                'tier_2_price' => $row->tier_2_price !== null ? (float) $row->tier_2_price : null,
            ];
        }

        return $matrix;
    }

    /**
     * Build report rows with pagination and stock data.
     * When availability filter is active:
     * 'available' => total Good + Bad across visible businesses > 0
     * 'non_available' => total Good + Bad across visible businesses == 0
     * 'all' => no stock availability filtering
     */
    public function getReportData(
        CrossBusinessStockInventoryFilterData $filters,
        int $perPage = 15,
        int $page = 1,
        bool $includePrices = false
    ): array {
        $businesses = $this->getBusinessHierarchy($filters->businessIds);
        $productsQuery = $this->getFilteredProductsQuery($filters);

        // Standard database pagination; availability filter is already applied at the SQL query level
        $paginator = $productsQuery->paginate($perPage, ['*'], 'page', $page);
        $products = $paginator->getCollection();
        $productIds = $products->pluck('id')->all();

        // Eager load base unit and conversions for the current page only
        $products->loadMissing([
            'unit',
            'baseUnit',
            'conversions.unit',
        ]);

        $stockMatrix = $this->getStockMatrix($productIds, $filters->businessIds);
        $inDeliveryMatrix = $this->inDeliveryService->getInDeliveryMatrix($productIds, $filters->businessIds);
        $priceMatrix = $includePrices ? $this->getPriceMatrix($productIds, $filters->businessIds) : [];

        // Resolve serial marker context for on-screen display only
        $markerContext = $this->resolveSerialMarkerContext($filters->search, $filters->businessIds);

        $rows = $this->buildRows($products, $businesses, $stockMatrix, $inDeliveryMatrix, $markerContext, $priceMatrix, $includePrices, true);

        return [
            'paginator' => $paginator,
            'rows' => $rows,
            'businesses' => $businesses,
        ];
    }

    /**
     * Get all rows for Excel export (no pagination), respecting search, filters, and business visibility.
     */
    public function getAllRowsForExport(
        CrossBusinessStockInventoryFilterData $filters,
        int $chunkSize = 100,
        string $exportContent = 'stock'
    ): array {
        return [
            'rows' => collect($this->getRowGeneratorForExport($filters, $chunkSize, $exportContent)),
            'businesses' => $this->getBusinessHierarchy($filters->businessIds),
        ];
    }

    /**
     * Lazily yield export row view models in bounded batches, so the caller (the export
     * class) never needs a second fully materialized copy of the row data alongside its
     * own formatted spreadsheet rows.
     *
     * @param  string  $exportContent  'stock' | 'prices' | 'stock_and_prices'
     * @return \Generator<int, array>
     */
    public function getRowGeneratorForExport(
        CrossBusinessStockInventoryFilterData $filters,
        int $chunkSize = 100,
        string $exportContent = 'stock'
    ): \Generator {
        $includeStock = in_array($exportContent, ['stock', 'stock_and_prices'], true);
        $includePrices = in_array($exportContent, ['prices', 'stock_and_prices'], true);

        $businesses = $this->getBusinessHierarchy($filters->businessIds);
        $productsQuery = $this->getFilteredProductsQuery($filters);

        $relations = [
            'unit',
            'baseUnit',
            'category:id,category_name',
            'brand:id,name',
        ];
        if ($includeStock) {
            $relations[] = 'conversions.unit';
        }

        // Process in bounded batches of $chunkSize to release related records and matrix data
        foreach ($productsQuery->cursor()->chunk($chunkSize) as $lazyChunk) {
            $productsChunk = new \Illuminate\Database\Eloquent\Collection($lazyChunk->all());
            $productsChunk->loadMissing($relations);
            $chunkProductIds = $productsChunk->pluck('id')->all();

            $stockMatrix = $includeStock ? $this->getStockMatrix($chunkProductIds, $filters->businessIds) : [];
            $inDeliveryMatrix = $includeStock ? $this->inDeliveryService->getInDeliveryMatrix($chunkProductIds, $filters->businessIds) : [];
            $priceMatrix = $includePrices ? $this->getPriceMatrix($chunkProductIds, $filters->businessIds) : [];

            foreach ($this->buildRows($productsChunk, $businesses, $stockMatrix, $inDeliveryMatrix, null, $priceMatrix, $includePrices, $includeStock) as $row) {
                yield $row;
            }

            unset($productsChunk, $chunkProductIds, $stockMatrix, $inDeliveryMatrix, $priceMatrix);
        }
    }

    /**
     * Build row view models from products, businesses, stockMatrix, inDeliveryMatrix, and optional priceMatrix.
     */
    private function buildRows(
        Collection $products,
        Collection $businesses,
        array $stockMatrix,
        array $inDeliveryMatrix = [],
        ?array $markerContext = null,
        array $priceMatrix = [],
        bool $includePrices = false,
        bool $includeStock = true
    ): Collection {
        return $products->map(function ($product) use ($businesses, $stockMatrix, $inDeliveryMatrix, $markerContext, $priceMatrix, $includePrices, $includeStock) {
            $productId = $product->id;
            $productStock = $includeStock ? ($stockMatrix[$productId] ?? null) : null;
            $productInDelivery = $includeStock ? ($inDeliveryMatrix[$productId] ?? []) : [];
            $productPrices = $includePrices ? ($priceMatrix[$productId] ?? []) : [];

            // Determine if this product row matches the marker context
            $productMatchesMarker = $includeStock && $markerContext && (int) $markerContext['product_id'] === $productId;

            // Resolve base unit and largest valid unit conversion once per product
            $baseUnit = ($product->relationLoaded('baseUnit') ? $product->baseUnit : null)
                ?? ($product->relationLoaded('unit') ? $product->unit : null);
            $baseUnitName = $baseUnit ? ($baseUnit->short_name ?: $baseUnit->name) : null;

            $largestConversion = null;
            $conversionUnitName = null;
            $conversionFactor = null;

            if ($includeStock && $product->relationLoaded('conversions') && $product->conversions) {
                $validConversions = $product->conversions->filter(function ($conv) {
                    return (float) ($conv->conversion_factor ?? 0) > 0 && !empty($conv->unit);
                });

                if ($validConversions->isNotEmpty()) {
                    $largestConversion = $validConversions->sortByDesc(fn ($c) => (float) $c->conversion_factor)->first();
                    if ($largestConversion && $largestConversion->unit) {
                        $conversionUnitName = $largestConversion->unit->short_name ?: $largestConversion->unit->name;
                        $conversionFactor = (float) $largestConversion->conversion_factor;
                    }
                }
            }

            $businessStockData = [];
            $businessPriceData = [];
            $totalInDelivery = 0.0;

            foreach ($businesses as $b) {
                $settingId = $b['setting_id'];

                if ($includePrices) {
                    $pRow = $productPrices[$settingId] ?? null;
                    $businessPriceData[$settingId] = [
                        'average_purchase_price' => $pRow !== null ? $pRow['average_purchase_price'] : null,
                        'sale_price' => $pRow !== null ? $pRow['sale_price'] : null,
                        'tier_1_price' => $pRow !== null ? $pRow['tier_1_price'] : null,
                        'tier_2_price' => $pRow !== null ? $pRow['tier_2_price'] : null,
                    ];
                }

                if (!$includeStock) {
                    continue;
                }

                $isPkp = $b['is_pkp'];
                $bData = $productStock['businesses'][$settingId] ?? null;

                $good = $bData['good'] ?? 0.0;
                $bad = $bData['bad'] ?? 0.0;
                $taxGood = $bData['tax_good'] ?? 0.0;
                $nonTaxGood = $bData['non_tax_good'] ?? 0.0;
                $taxBad = $bData['tax_bad'] ?? 0.0;
                $nonTaxBad = $bData['non_tax_bad'] ?? 0.0;

                // Business-level in-delivery quantity (no location breakdown)
                $inDelivery = (float) ($productInDelivery[$settingId] ?? 0.0);
                $totalInDelivery += $inDelivery;

                $goodTooltip = self::computeTooltip($isPkp, $taxGood, $nonTaxGood);
                $badTooltip = self::computeTooltip($isPkp, $taxBad, $nonTaxBad);

                // Business-level marker: matches when product + setting + condition align
                $businessMatchesMarker = $productMatchesMarker
                    && (int) $markerContext['setting_id'] === $settingId;
                $markerGood = $businessMatchesMarker && $markerContext['condition'] === 'good';
                $markerBad = $businessMatchesMarker && $markerContext['condition'] === 'bad';

                $locationStockData = [];
                foreach ($b['locations'] as $loc) {
                    $locId = $loc['id'];
                    $locData = $bData['locations'][$locId] ?? null;

                    $lGood = $locData['good'] ?? 0.0;
                    $lBad = $locData['bad'] ?? 0.0;
                    $lTaxGood = $locData['tax_good'] ?? 0.0;
                    $lNonTaxGood = $locData['non_tax_good'] ?? 0.0;
                    $lTaxBad = $locData['tax_bad'] ?? 0.0;
                    $lNonTaxBad = $locData['non_tax_bad'] ?? 0.0;

                    $lGoodTooltip = self::computeTooltip($isPkp, $lTaxGood, $lNonTaxGood);
                    $lBadTooltip = self::computeTooltip($isPkp, $lTaxBad, $lNonTaxBad);

                    // Location-level marker: matches when product + setting + location + condition align
                    $locMatchesMarker = $businessMatchesMarker
                        && (int) $markerContext['location_id'] === $locId;

                    $locationStockData[$locId] = [
                        'good' => $lGood,
                        'bad' => $lBad,
                        'good_tooltip' => $lGoodTooltip,
                        'bad_tooltip' => $lBadTooltip,
                        'marker_good' => $locMatchesMarker && $markerContext['condition'] === 'good',
                        'marker_bad' => $locMatchesMarker && $markerContext['condition'] === 'bad',
                    ];
                }

                $businessStockData[$settingId] = [
                    'good' => $good,
                    'bad' => $bad,
                    'in_delivery' => $inDelivery,
                    'good_tooltip' => $goodTooltip,
                    'bad_tooltip' => $badTooltip,
                    'marker_good' => $markerGood,
                    'marker_bad' => $markerBad,
                    'locations' => $locationStockData,
                ];
            }

            $row = [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'barcode' => $product->barcode,
                'serial_number_required' => (bool) $product->serial_number_required,
                'category_name' => optional($product->category)->category_name ?? '-',
                'brand_name' => optional($product->brand)->name ?? '-',
                'base_unit_name' => $baseUnitName,
                'conversion_unit_name' => $conversionUnitName,
                'conversion_factor' => $conversionFactor,
                'total_good' => $includeStock ? ($productStock['total_good'] ?? 0.0) : 0.0,
                'total_bad' => $includeStock ? ($productStock['total_bad'] ?? 0.0) : 0.0,
                'total_in_delivery' => $includeStock ? $totalInDelivery : 0.0,
                'businesses' => $businessStockData,
            ];

            if ($includePrices) {
                $row['prices'] = $businessPriceData;
            }

            return $row;
        });
    }

    /**
     * Query serial numbers for the serial number dialog modal.
     * Scoped to business + optional location + condition (Good / Bad).
     * Reuses sellable filter logic for Good cells:
     * - is_broken = false
     * - is_in_return_process = false
     * - dispatch_detail_id IS NULL
     * - status != 'RETURNED' (or null)
     * For Bad cells:
     * - is_broken = true
     */
    public function getSerialNumbers(
        int $productId,
        int $settingId,
        ?int $locationId = null,
        string $condition = 'good',
        string $searchQuery = '',
        int $perPage = 10,
        int $page = 1
    ): LengthAwarePaginator {
        $query = ProductSerialNumber::query()
            ->where('product_id', $productId)
            ->whereHas('location', function ($loc) use ($settingId, $locationId) {
                $loc->where('setting_id', $settingId)
                    ->where('is_active', true);
                if ($locationId !== null) {
                    $loc->where('id', $locationId);
                }
            })
            ->with(['location']);

        if ($condition === 'bad') {
            $query->availableBroken();
        } else {
            $query->sellable();
        }

        if (!empty($searchQuery)) {
            $query->where('serial_number', 'like', '%' . trim($searchQuery) . '%');
        }

        return $query->orderBy('serial_number', 'asc')->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Resolve serial marker context for an exact serial-number search.
     *
     * Returns a compact array with product_id, setting_id, location_id, and condition
     * ('good' or 'bad') when the search value exactly matches a serial that is currently
     * in an operational Good or Bad state. Returns null otherwise.
     *
     * Uses the same sellable() and availableBroken() scopes as the serial dialog.
     */
    public function resolveSerialMarkerContext(string $search, array $visibleBusinessIds): ?array
    {
        $search = trim($search);
        if ($search === '' || empty($visibleBusinessIds)) {
            return null;
        }

        // Normalize the search value the same way serial numbers are normalized on write
        $normalized = ProductSerialNumber::normalize($search);

        // Find exact serial match
        $serial = ProductSerialNumber::query()
            ->where('serial_number', $normalized)
            ->with('location')
            ->first();

        if (!$serial || !$serial->location) {
            return null;
        }

        $locationSettingId = (int) $serial->location->setting_id;
        $locationId = (int) $serial->location->id;
        $productId = (int) $serial->product_id;

        // Serial's business must be among the visible businesses
        if (!in_array($locationSettingId, $visibleBusinessIds)) {
            return null;
        }

        // Location must be active
        if (!$serial->location->is_active) {
            return null;
        }

        // Check if the serial is operational Good (sellable)
        $isGood = ProductSerialNumber::query()
            ->where('id', $serial->id)
            ->sellable()
            ->exists();

        if ($isGood) {
            return [
                'product_id' => $productId,
                'setting_id' => $locationSettingId,
                'location_id' => $locationId,
                'condition' => 'good',
            ];
        }

        // Check if the serial is operational Bad (availableBroken)
        $isBad = ProductSerialNumber::query()
            ->where('id', $serial->id)
            ->availableBroken()
            ->exists();

        if ($isBad) {
            return [
                'product_id' => $productId,
                'setting_id' => $locationSettingId,
                'location_id' => $locationId,
                'condition' => 'bad',
            ];
        }

        // Serial exists but is not in an operational Good or Bad state
        return null;
    }
}
