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

        // Category filter (plain multi-select with implicit OR semantics)
        if (!empty($filters->categoryIds)) {
            $query->whereIn('category_id', $filters->categoryIds);
        }

        // Brand filter
        if (!empty($filters->brandIds)) {
            $query->whereIn('brand_id', $filters->brandIds);
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

        return Setting::query()
            ->whereIn('id', $businessIds)
            ->orderBy('company_name', 'asc')
            ->get()
            ->map(function (Setting $setting) {
                // Get locations belonging to this setting
                $locations = Location::query()
                    ->where('setting_id', $setting->id)
                    ->where('is_active', true)
                    ->orderBy('name', 'asc')
                    ->orderBy('id', 'asc')
                    ->get(['id', 'name', 'setting_id']);

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
        int $page = 1
    ): array {
        $businesses = $this->getBusinessHierarchy($filters->businessIds);
        $productsQuery = $this->getFilteredProductsQuery($filters);

        // Standard database pagination; availability filter is already applied at the SQL query level
        $paginator = $productsQuery->paginate($perPage, ['*'], 'page', $page);
        $products = collect($paginator->items());
        $productIds = $products->pluck('id')->all();
        $stockMatrix = $this->getStockMatrix($productIds, $filters->businessIds);

        $rows = $this->buildRows($products, $businesses, $stockMatrix);

        return [
            'paginator' => $paginator,
            'rows' => $rows,
            'businesses' => $businesses,
        ];
    }

    /**
     * Get all rows for Excel export (no pagination), respecting search, filters, and business visibility.
     */
    public function getAllRowsForExport(CrossBusinessStockInventoryFilterData $filters): array
    {
        $businesses = $this->getBusinessHierarchy($filters->businessIds);
        $productsQuery = $this->getFilteredProductsQuery($filters);
        $allProducts = $productsQuery->get();
        $allProductIds = $allProducts->pluck('id')->all();
        $stockMatrix = $this->getStockMatrix($allProductIds, $filters->businessIds);

        $rows = $this->buildRows($allProducts, $businesses, $stockMatrix);

        return [
            'rows' => $rows,
            'businesses' => $businesses,
        ];
    }

    /**
     * Build row view models from products, businesses, and stockMatrix.
     */
    private function buildRows(Collection $products, Collection $businesses, array $stockMatrix): Collection
    {
        return $products->map(function ($product) use ($businesses, $stockMatrix) {
            $productId = $product->id;
            $productStock = $stockMatrix[$productId] ?? null;

            $businessStockData = [];

            foreach ($businesses as $b) {
                $settingId = $b['setting_id'];
                $isPkp = $b['is_pkp'];
                $bData = $productStock['businesses'][$settingId] ?? null;

                $good = $bData['good'] ?? 0.0;
                $bad = $bData['bad'] ?? 0.0;
                $taxGood = $bData['tax_good'] ?? 0.0;
                $nonTaxGood = $bData['non_tax_good'] ?? 0.0;
                $taxBad = $bData['tax_bad'] ?? 0.0;
                $nonTaxBad = $bData['non_tax_bad'] ?? 0.0;

                $goodTooltip = self::computeTooltip($isPkp, $taxGood, $nonTaxGood);
                $badTooltip = self::computeTooltip($isPkp, $taxBad, $nonTaxBad);

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

                    $locationStockData[$locId] = [
                        'good' => $lGood,
                        'bad' => $lBad,
                        'good_tooltip' => $lGoodTooltip,
                        'bad_tooltip' => $lBadTooltip,
                    ];
                }

                $businessStockData[$settingId] = [
                    'good' => $good,
                    'bad' => $bad,
                    'good_tooltip' => $goodTooltip,
                    'bad_tooltip' => $badTooltip,
                    'locations' => $locationStockData,
                ];
            }

            return [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'barcode' => $product->barcode,
                'serial_number_required' => (bool) $product->serial_number_required,
                'category_name' => optional($product->category)->category_name ?? '-',
                'brand_name' => optional($product->brand)->name ?? '-',
                'total_good' => $productStock['total_good'] ?? 0.0,
                'total_bad' => $productStock['total_bad'] ?? 0.0,
                'businesses' => $businessStockData,
            ];
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
            $query->where('is_broken', true);
        } else {
            // Good condition: sellable filter combination
            $query->where('is_broken', false)
                ->where('is_in_return_process', false)
                ->whereNull('dispatch_detail_id')
                ->where(function ($q) {
                    $q->whereNull('status')
                        ->orWhereRaw('LOWER(status) != ?', ['returned']);
                });
        }

        if (!empty($searchQuery)) {
            $query->where('serial_number', 'like', '%' . trim($searchQuery) . '%');
        }

        return $query->orderBy('serial_number', 'asc')->paginate($perPage, ['*'], 'page', $page);
    }
}
