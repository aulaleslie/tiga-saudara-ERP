<?php

namespace App\Services\Reports;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

class StockInsightsQueryService
{
    /**
     * Get the active business hierarchy (Settings and active locations) across the whole system.
     *
     * @return Collection
     */
    public function getBusinessHierarchy(): Collection
    {
        $activeLocations = Location::query()
            ->where('is_active', true)
            ->orderBy('name', 'asc')
            ->orderBy('id', 'asc')
            ->get(['id', 'name', 'setting_id'])
            ->groupBy('setting_id');

        return Setting::without(['currency'])
            ->orderBy('company_name', 'asc')
            ->orderBy('id', 'asc')
            ->get(['id', 'company_name', 'is_pkp'])
            ->map(function (Setting $setting) use ($activeLocations) {
                $locations = $activeLocations->get($setting->id, collect());

                return [
                    'setting_id' => (int) $setting->id,
                    'company_name' => (string) $setting->company_name,
                    'is_pkp' => (bool) $setting->is_pkp,
                    'locations' => $locations->map(fn ($loc) => [
                        'id' => (int) $loc->id,
                        'name' => (string) $loc->name,
                        'setting_id' => (int) $loc->setting_id,
                    ])->all(),
                ];
            });
    }

    /**
     * Load current stock matrix for given product IDs across all businesses and active locations.
     *
     * Returns:
     * [
     *    productId => [
     *        'global' => StockBucketData,
     *        'businesses' => [
     *            settingId => [
     *                'setting_id' => int,
     *                'stock' => StockBucketData,
     *                'locations' => [
     *                    locationId => [
     *                        'id' => int,
     *                        'stock' => StockBucketData,
     *                    ]
     *                ]
     *            ]
     *        ]
     *    ]
     * ]
     *
     * @param array<int> $productIds
     * @return array<int, array>
     */
    public function getStockMatrix(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        // Query product_stocks joined to active locations across all settings
        $rows = DB::table('product_stocks')
            ->join('locations', 'product_stocks.location_id', '=', 'locations.id')
            ->join('settings', 'locations.setting_id', '=', 'settings.id')
            ->where('locations.is_active', true)
            ->whereIn('product_stocks.product_id', $productIds)
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
        foreach ($productIds as $pid) {
            $matrix[$pid] = [
                'global' => new StockBucketData(),
                'businesses' => [],
            ];
        }

        foreach ($rows as $row) {
            $productId = (int) $row->product_id;
            $settingId = (int) $row->setting_id;
            $locationId = (int) $row->location_id;

            $locationBucket = new StockBucketData(
                (float) $row->quantity_tax,
                (float) $row->quantity_non_tax,
                (float) $row->broken_quantity_tax,
                (float) $row->broken_quantity_non_tax
            );

            if (!isset($matrix[$productId])) {
                $matrix[$productId] = [
                    'global' => new StockBucketData(),
                    'businesses' => [],
                ];
            }

            if (!isset($matrix[$productId]['businesses'][$settingId])) {
                $matrix[$productId]['businesses'][$settingId] = [
                    'setting_id' => $settingId,
                    'stock' => new StockBucketData(),
                    'locations' => [],
                ];
            }

            $matrix[$productId]['businesses'][$settingId]['locations'][$locationId] = [
                'id' => $locationId,
                'stock' => $locationBucket,
            ];

            // Reconcile up: location -> business -> global
            $matrix[$productId]['businesses'][$settingId]['stock'] =
                $matrix[$productId]['businesses'][$settingId]['stock']->add($locationBucket);

            $matrix[$productId]['global'] =
                $matrix[$productId]['global']->add($locationBucket);
        }

        return $matrix;
    }

    /**
     * SQL expression for a product's current global Good stock across all businesses and active locations.
     */
    public static function globalGoodStockSqlExpression(): string
    {
        return "(SELECT COALESCE(SUM(ps.quantity_tax + ps.quantity_non_tax), 0)
                 FROM product_stocks ps
                 JOIN locations l ON ps.location_id = l.id
                 JOIN settings s ON l.setting_id = s.id
                 WHERE l.is_active = 1
                   AND ps.product_id = products.id)";
    }

    /**
     * SQL expression for whether a product is "Lama Tidak Terjual":
     * - Global Good stock > 0
     * - Product age >= 90 days (created_at <= 90 days before today)
     * - No eligible sale quantity in the 90 days ending today
     */
    public static function slowMovingSqlExpression(string $todayDate): string
    {
        $cutoffDate = \Carbon\Carbon::parse($todayDate)->subDays(89)->format('Y-m-d');
        $productAgeCutoff = \Carbon\Carbon::parse($todayDate)->subDays(90)->endOfDay()->toDateTimeString();

        $goodStockSql = self::globalGoodStockSqlExpression();
        $effectiveDateSql = \App\Services\Reports\Concerns\EffectiveSaleReportingDate::sqlExpression('sales');
        $saleEligibilitySql = \App\Services\Reports\Concerns\FulfilledTransactionEligibility::saleSqlExpression('sales');

        return "({$goodStockSql} > 0
            AND products.created_at <= '{$productAgeCutoff}'
            AND NOT EXISTS (
                SELECT 1
                FROM sale_details
                JOIN sales ON sale_details.sale_id = sales.id
                WHERE sale_details.product_id = products.id
                  AND {$saleEligibilitySql}
                  AND {$effectiveDateSql} >= '{$cutoffDate}'
                  AND {$effectiveDateSql} <= '{$todayDate}'
                  AND sale_details.quantity > 0
            ))";
    }

    /**
     * Base query for eligible products:
     * Active, non-merged, stock-managed products.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getEligibleProductsBaseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Product::without(['media', 'brand', 'category'])
            ->where('is_active', true)
            ->whereNull('merged_into_id')
            ->where('stock_managed', true);
    }

    /**
     * Get aggregate attention counts across all eligible products.
     *
     * @param StockInsightsFilterData|null $filter
     * @return array{out_of_stock: int, reorder_required: int, minimum_unset: int, slow_moving: int}
     */
    public function getAttentionCounts(?StockInsightsFilterData $filter = null): array
    {
        $todayDate = $filter ? $filter->endDate : \Carbon\Carbon::today()->format('Y-m-d');

        $query = $this->getEligibleProductsBaseQuery();

        $goodStockSql = self::globalGoodStockSqlExpression();
        $slowMovingSql = self::slowMovingSqlExpression($todayDate);

        $outOfStockCase = "CASE WHEN ({$goodStockSql}) <= 0 THEN 1 ELSE 0 END";
        $reorderCase = "CASE WHEN COALESCE(products.product_stock_alert, 0) > 0 AND ({$goodStockSql}) > 0 AND ({$goodStockSql}) <= products.product_stock_alert THEN 1 ELSE 0 END";
        $minimumUnsetCase = "CASE WHEN COALESCE(products.product_stock_alert, 0) = 0 THEN 1 ELSE 0 END";
        $slowMovingCase = "CASE WHEN {$slowMovingSql} THEN 1 ELSE 0 END";

        $result = $query->selectRaw("
            COALESCE(SUM({$outOfStockCase}), 0) as out_of_stock_count,
            COALESCE(SUM({$reorderCase}), 0) as reorder_required_count,
            COALESCE(SUM({$minimumUnsetCase}), 0) as minimum_unset_count,
            COALESCE(SUM({$slowMovingCase}), 0) as slow_moving_count
        ")->first();

        return [
            'out_of_stock' => (int) ($result->out_of_stock_count ?? 0),
            'reorder_required' => (int) ($result->reorder_required_count ?? 0),
            'minimum_unset' => (int) ($result->minimum_unset_count ?? 0),
            'slow_moving' => (int) ($result->slow_moving_count ?? 0),
        ];
    }

    /**
     * Query and paginate ProductStockInsightsRow objects according to filter parameters.
     *
     * @param StockInsightsFilterData $filter
     * @return LengthAwarePaginator
     */
    public function paginate(StockInsightsFilterData $filter): LengthAwarePaginator
    {
        $todayDate = $filter->endDate;
        $startDate = $filter->startDate;

        $goodStockSql = self::globalGoodStockSqlExpression();
        $slowMovingSql = self::slowMovingSqlExpression($todayDate);

        $outOfStockSql = "({$goodStockSql} <= 0)";
        $reorderRequiredSql = "(COALESCE(products.product_stock_alert, 0) > 0 AND {$goodStockSql} > 0 AND {$goodStockSql} <= products.product_stock_alert)";
        $minimumUnsetSql = "(COALESCE(products.product_stock_alert, 0) = 0)";

        $query = $this->getEligibleProductsBaseQuery()
            ->with(['category:id,category_name', 'brand:id,name', 'unit:id,name']);

        // 1. Tokenized Search
        if ($filter->search !== '') {
            $tokens = array_filter(explode(' ', $filter->search), 'strlen');
            if (!empty($tokens)) {
                $query->where(function ($q) use ($tokens) {
                    foreach ($tokens as $token) {
                        $q->where(function ($sub) use ($token) {
                            $sub->where('products.product_name', 'like', '%' . $token . '%')
                                ->orWhere('products.product_code', 'like', '%' . $token . '%')
                                ->orWhere('products.barcode', 'like', '%' . $token . '%');
                        });
                    }
                });
            }
        }

        // 2. Category Filter
        if (!empty($filter->categoryIds)) {
            $query->whereIn('products.category_id', $filter->categoryIds);
        }

        // 3. Brand Filter
        if (!empty($filter->brandIds)) {
            $query->whereIn('products.brand_id', $filter->brandIds);
        }

        // 4. Status Filter (Multi-select OR semantics)
        if (!empty($filter->statuses)) {
            $query->where(function ($q) use ($filter, $outOfStockSql, $reorderRequiredSql, $minimumUnsetSql, $slowMovingSql) {
                foreach ($filter->statuses as $status) {
                    if ($status === StockInsightsFilterData::STATUS_OUT_OF_STOCK) {
                        $q->orWhereRaw($outOfStockSql);
                    } elseif ($status === StockInsightsFilterData::STATUS_REORDER_REQUIRED) {
                        $q->orWhereRaw($reorderRequiredSql);
                    } elseif ($status === StockInsightsFilterData::STATUS_MINIMUM_UNSET) {
                        $q->orWhereRaw($minimumUnsetSql);
                    } elseif ($status === StockInsightsFilterData::STATUS_SLOW_MOVING) {
                        $q->orWhereRaw($slowMovingSql);
                    }
                }
            });
        }

        // 5. Select projections needed for status flags and default sort priority
        $query->select('products.*');
        $query->selectRaw("{$goodStockSql} as computed_good_stock");
        $query->selectRaw("CASE WHEN {$outOfStockSql} THEN 1 ELSE 0 END as is_out_of_stock");
        $query->selectRaw("CASE WHEN {$reorderRequiredSql} THEN 1 ELSE 0 END as is_reorder_required");
        $query->selectRaw("CASE WHEN {$minimumUnsetSql} THEN 1 ELSE 0 END as is_minimum_unset");
        $query->selectRaw("CASE WHEN {$slowMovingSql} THEN 1 ELSE 0 END as is_slow_moving");

        // Sales & Financial subqueries for sorting when requested
        $effectiveDateSql = \App\Services\Reports\Concerns\EffectiveSaleReportingDate::sqlExpression('sales');
        $saleEligibilitySql = \App\Services\Reports\Concerns\FulfilledTransactionEligibility::saleSqlExpression('sales');

        $soldQtySubquery = "
            SELECT COALESCE(SUM(sd.quantity), 0)
            FROM sale_details sd
            JOIN sales ON sd.sale_id = sales.id
            WHERE sd.product_id = products.id
              AND {$saleEligibilitySql}
              AND {$effectiveDateSql} >= '{$startDate}'
              AND {$effectiveDateSql} <= '{$todayDate}'
        ";

        $lastSaleDateSubquery = "
            SELECT MAX({$effectiveDateSql})
            FROM sale_details sd
            JOIN sales ON sd.sale_id = sales.id
            WHERE sd.product_id = products.id
              AND {$saleEligibilitySql}
              AND {$effectiveDateSql} >= '{$startDate}'
              AND {$effectiveDateSql} <= '{$todayDate}'
        ";

        $rawLineDppSql = "(
            CASE WHEN sales.is_tax_included = 1
                 THEN sd.sub_total - COALESCE(sd.product_tax_amount, 0)
                 ELSE sd.sub_total
            END
        )";

        $lineDppSql = "(
            CASE WHEN {$rawLineDppSql} < 0 THEN 0.0 ELSE {$rawLineDppSql} END
        )";

        $innerRawLineDppSql = "(
            CASE WHEN s_inner.is_tax_included = 1
                 THEN sd_inner.sub_total - COALESCE(sd_inner.product_tax_amount, 0)
                 ELSE sd_inner.sub_total
            END
        )";

        $saleTotalDppSql = "(
            SELECT SUM(
                CASE WHEN {$innerRawLineDppSql} < 0 THEN 0.0 ELSE {$innerRawLineDppSql} END
            )
            FROM sale_details sd_inner
            JOIN sales s_inner ON sd_inner.sale_id = s_inner.id
            WHERE sd_inner.sale_id = sales.id
        )";

        $priorLinesRoundedDiscountSql = "(
            SELECT COALESCE(SUM(
                ROUND(
                    (
                        (
                            CASE
                                WHEN (
                                    CASE WHEN s_prior.is_tax_included = 1
                                         THEN sd_prior.sub_total - COALESCE(sd_prior.product_tax_amount, 0)
                                         ELSE sd_prior.sub_total
                                    END
                                ) < 0 THEN 0.0
                                ELSE (
                                    CASE WHEN s_prior.is_tax_included = 1
                                         THEN sd_prior.sub_total - COALESCE(sd_prior.product_tax_amount, 0)
                                         ELSE sd_prior.sub_total
                                    END
                                )
                            END
                        ) / ({$saleTotalDppSql})
                    ) * sales.discount_amount,
                    2
                )
            ), 0.0)
            FROM sale_details sd_prior
            JOIN sales s_prior ON sd_prior.sale_id = s_prior.id
            WHERE sd_prior.sale_id = sales.id
              AND sd_prior.id < sd.id
        )";

        $lineProportionalDiscountSql = "(
            CASE
                WHEN COALESCE(sales.discount_amount, 0) > 0 AND ({$saleTotalDppSql}) > 0 THEN
                    CASE
                        WHEN sd.id = (
                            SELECT MAX(sd_last.id)
                            FROM sale_details sd_last
                            WHERE sd_last.sale_id = sales.id
                        ) THEN
                            ROUND(sales.discount_amount - {$priorLinesRoundedDiscountSql}, 2)
                        ELSE
                            ROUND(({$lineDppSql} / ({$saleTotalDppSql})) * sales.discount_amount, 2)
                    END
                ELSE 0.0
            END
        )";

        $lineRevenueSql = "({$lineDppSql} - {$lineProportionalDiscountSql})";

        $salesValueSubquery = "
            SELECT COALESCE(SUM(
                CASE WHEN {$lineRevenueSql} < 0 THEN 0.0 ELSE {$lineRevenueSql} END
            ), 0)
            FROM sale_details sd
            JOIN sales ON sd.sale_id = sales.id
            WHERE sd.product_id = products.id
              AND {$saleEligibilitySql}
              AND {$effectiveDateSql} >= '{$startDate}'
              AND {$effectiveDateSql} <= '{$todayDate}'
        ";

        $soldCostSubquery = "
            SELECT COALESCE(SUM(
                COALESCE(
                    sd.cost_unit_snapshot * sd.quantity,
                    sd.cost_total_snapshot,
                    0
                ) + COALESCE((
                    SELECT COALESCE(SUM(
                        COALESCE(
                            sbi.cost_unit_snapshot * sbi.quantity,
                            sbi.cost_total_snapshot,
                            0
                        )
                    ), 0)
                    FROM sale_bundle_items sbi
                    WHERE sbi.sale_detail_id = sd.id
                ), 0)
            ), 0)
            FROM sale_details sd
            JOIN sales ON sd.sale_id = sales.id
            WHERE sd.product_id = products.id
              AND {$saleEligibilitySql}
              AND {$effectiveDateSql} >= '{$startDate}'
              AND {$effectiveDateSql} <= '{$todayDate}'
        ";

        if ($filter->sortColumn === 'sold_quantity') {
            $query->selectRaw("({$soldQtySubquery}) as computed_sold_quantity");
            $query->orderBy('computed_sold_quantity', $filter->sortDirection)
                ->orderBy('products.product_name', 'asc')
                ->orderBy('products.id', 'asc');
        } elseif ($filter->sortColumn === 'last_sale_date') {
            $query->selectRaw("({$lastSaleDateSubquery}) as computed_last_sale_date");
            $query->orderBy('computed_last_sale_date', $filter->sortDirection)
                ->orderBy('products.product_name', 'asc')
                ->orderBy('products.id', 'asc');
        } elseif ($filter->sortColumn === 'global_stock') {
            $query->orderBy('computed_good_stock', $filter->sortDirection)
                ->orderBy('products.product_name', 'asc')
                ->orderBy('products.id', 'asc');
        } elseif ($filter->sortColumn === 'product_name') {
            $query->orderBy('products.product_name', $filter->sortDirection)
                ->orderBy('products.id', 'asc');
        } elseif ($filter->sortColumn === 'sales_value') {
            $query->selectRaw("({$salesValueSubquery}) as computed_sales_value");
            $query->orderBy('computed_sales_value', $filter->sortDirection)
                ->orderBy('products.product_name', 'asc')
                ->orderBy('products.id', 'asc');
        } elseif ($filter->sortColumn === 'sold_cost') {
            $query->selectRaw("({$soldCostSubquery}) as computed_sold_cost");
            $query->orderBy('computed_sold_cost', $filter->sortDirection)
                ->orderBy('products.product_name', 'asc')
                ->orderBy('products.id', 'asc');
        } elseif ($filter->sortColumn === 'gross_profit') {
            $query->selectRaw("( ({$salesValueSubquery}) - ({$soldCostSubquery}) ) as computed_gross_profit");
            $query->orderBy('computed_gross_profit', $filter->sortDirection)
                ->orderBy('products.product_name', 'asc')
                ->orderBy('products.id', 'asc');
        } else {
            // Default Operational Order:
            // 1. Stok Habis
            // 2. Perlu Dibeli Lagi
            // 3. Batas Minimum Belum Diatur
            // 4. Lama Tidak Terjual
            // 5. Remaining products
            // Tie-break: product_name asc, id asc
            $prioritySql = "
                CASE
                    WHEN {$outOfStockSql} THEN 1
                    WHEN {$reorderRequiredSql} THEN 2
                    WHEN {$minimumUnsetSql} THEN 3
                    WHEN {$slowMovingSql} THEN 4
                    ELSE 5
                END
            ";
            $query->orderByRaw("{$prioritySql} asc")
                ->orderBy('products.product_name', 'asc')
                ->orderBy('products.id', 'asc');
        }

        // Execute paginator
        $paginator = $query->paginate($filter->perPage, ['*'], 'page', $filter->page);

        $productCollection = collect($paginator->items());
        $productIds = $productCollection->pluck('id')->all();

        if (empty($productIds)) {
            return new Paginator([], 0, $filter->perPage, $filter->page, [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ]);
        }

        // Bounded load: Stock Matrix
        $stockMatrix = $this->getStockMatrix($productIds);

        // Bounded load: Sales & Financial Aggregates for the page
        $financialAggregates = $this->getSalesAndFinancialAggregates($productIds, $startDate, $todayDate);

        // Transform into ProductStockInsightsRow objects
        $rows = $productCollection->map(function (Product $product) use ($stockMatrix, $financialAggregates) {
            $productId = (int) $product->id;
            $stockData = $stockMatrix[$productId] ?? [
                'global' => new StockBucketData(),
                'businesses' => [],
            ];

            $financial = $financialAggregates[$productId] ?? [
                'sold_quantity' => 0.0,
                'sales_value' => 0.0,
                'sold_cost' => 0.0,
                'gross_profit' => 0.0,
                'is_cost_incomplete' => false,
                'last_sale_date' => null,
            ];

            return new ProductStockInsightsRow(
                productId: $productId,
                productCode: (string) $product->product_code,
                productName: (string) $product->product_name,
                barcode: $product->barcode ? (string) $product->barcode : null,
                categoryId: $product->category_id ? (int) $product->category_id : null,
                categoryName: $product->category?->category_name,
                brandId: $product->brand_id ? (int) $product->brand_id : null,
                brandName: $product->brand?->name,
                unitName: (string) ($product->unit?->name ?? 'Pcs'),
                stockAlert: (int) ($product->product_stock_alert ?? 0),
                globalStock: $stockData['global'],
                businesses: $stockData['businesses'],
                isOutOfStock: (bool) $product->is_out_of_stock,
                isReorderRequired: (bool) $product->is_reorder_required,
                isMinimumUnset: (bool) $product->is_minimum_unset,
                isSlowMoving: (bool) $product->is_slow_moving,
                soldQuantity: (float) $financial['sold_quantity'],
                salesValue: (float) $financial['sales_value'],
                soldCost: (float) $financial['sold_cost'],
                grossProfit: (float) $financial['gross_profit'],
                isCostIncomplete: (bool) $financial['is_cost_incomplete'],
                lastSaleDate: $financial['last_sale_date']
            );
        });

        return new Paginator(
            $rows,
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ]
        );
    }

    /**
     * Compute sales and financial measures for a given set of product IDs over the rolling period:
     * - Quantity sold and last sale date from current persisted SaleDetails
     * - Line revenue tax-exclusive (sub_total - product_tax_amount)
     * - Proportional deterministic allocation of eligible Sale header discount
     * - Attributed snapshot HPP (including bundle items) without return reversal
     * - Incomplete cost detection (stock_managed product with missing snapshot)
     *
     * @param array<int> $productIds
     * @param string $startDate 'Y-m-d'
     * @param string $endDate 'Y-m-d'
     * @return array<int, array{sold_quantity: float, sales_value: float, sold_cost: float, gross_profit: float, is_cost_incomplete: bool, last_sale_date: ?string}>
     */
    public function getSalesAndFinancialAggregates(array $productIds, string $startDate, string $endDate): array
    {
        if (empty($productIds)) {
            return [];
        }

        $effectiveDateSql = \App\Services\Reports\Concerns\EffectiveSaleReportingDate::sqlExpression('sales');
        $saleEligibilitySql = \App\Services\Reports\Concerns\FulfilledTransactionEligibility::saleSqlExpression('sales');

        // Step 1: Find all eligible sales that contain any of our target products in this date range
        $allSaleIds = DB::table('sale_details')
            ->join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->whereIn('sale_details.product_id', $productIds)
            ->whereRaw($saleEligibilitySql)
            ->whereRaw("{$effectiveDateSql} >= ?", [$startDate])
            ->whereRaw("{$effectiveDateSql} <= ?", [$endDate])
            ->distinct()
            ->pluck('sales.id')
            ->all();

        $results = [];
        foreach ($productIds as $pid) {
            $results[$pid] = [
                'sold_quantity' => 0.0,
                'sales_value' => 0.0,
                'sold_cost' => 0.0,
                'gross_profit' => 0.0,
                'is_cost_incomplete' => false,
                'last_sale_date' => null,
            ];
        }

        if (empty($allSaleIds)) {
            return $results;
        }

        // Step 2: Fetch all sales with their header discounts and effective dates
        $sales = DB::table('sales')
            ->whereIn('id', $allSaleIds)
            ->select('id', DB::raw("{$effectiveDateSql} as effective_date"), 'discount_amount', 'is_tax_included')
            ->get()
            ->keyBy('id');

        // Step 3: Fetch all sale details for these sales to calculate proportional header discounts and product sales
        $allSaleDetails = DB::table('sale_details')
            ->whereIn('sale_id', $allSaleIds)
            ->select(
                'id',
                'sale_id',
                'product_id',
                'quantity',
                'sub_total',
                'product_tax_amount',
                'cost_unit_snapshot',
                'cost_total_snapshot',
                'cost_snapshot_source'
            )
            ->orderBy('sale_id')
            ->orderBy('id')
            ->get();

        // Group sale details by sale_id
        $detailsBySale = $allSaleDetails->groupBy('sale_id');

        // Step 4: Fetch bundle items for these sales
        $allBundleItems = DB::table('sale_bundle_items')
            ->whereIn('sale_id', $allSaleIds)
            ->select(
                'id',
                'sale_id',
                'sale_detail_id',
                'product_id',
                'quantity',
                'cost_unit_snapshot',
                'cost_total_snapshot',
                'cost_snapshot_source'
            )
            ->get();

        $bundleItemsByDetail = $allBundleItems->groupBy('sale_detail_id');

        // Step 5: Process each sale and allocate header discounts proportionally to line DPP
        foreach ($detailsBySale as $saleId => $details) {
            $sale = $sales->get($saleId);
            $effectiveDate = $sale?->effective_date;
            $headerDiscount = (float) ($sale?->discount_amount ?? 0);
            $isTaxIncluded = (bool) ($sale?->is_tax_included ?? false);

            // Compute tax-exclusive line DPP for all lines in this sale
            $lineDpps = [];
            $totalSaleDpp = 0.0;
            foreach ($details as $detail) {
                if ($isTaxIncluded) {
                    $lineDpp = max(0.0, (float) $detail->sub_total - (float) ($detail->product_tax_amount ?? 0));
                } else {
                    $lineDpp = max(0.0, (float) $detail->sub_total);
                }
                $lineDpps[$detail->id] = $lineDpp;
                $totalSaleDpp += $lineDpp;
            }

            // Allocate header discount deterministically across lines
            $allocatedDiscounts = [];
            if ($headerDiscount > 0 && $totalSaleDpp > 0) {
                $runningAllocated = 0.0;
                $detailCount = count($details);
                $index = 0;

                foreach ($details as $detail) {
                    $index++;
                    if ($index === $detailCount) {
                        // Assign exact remainder to the last line to prevent rounding drift
                        $lineDiscount = round($headerDiscount - $runningAllocated, 2);
                    } else {
                        $ratio = $lineDpps[$detail->id] / $totalSaleDpp;
                        $lineDiscount = round($headerDiscount * $ratio, 2);
                        $runningAllocated += $lineDiscount;
                    }
                    $allocatedDiscounts[$detail->id] = $lineDiscount;
                }
            } else {
                foreach ($details as $detail) {
                    $allocatedDiscounts[$detail->id] = 0.0;
                }
            }

            // Now aggregate metrics for target products
            foreach ($details as $detail) {
                $pid = (int) $detail->product_id;
                if (!isset($results[$pid])) {
                    continue;
                }

                $qty = (float) $detail->quantity;
                $dpp = $lineDpps[$detail->id];
                $discount = $allocatedDiscounts[$detail->id] ?? 0.0;
                $netLineRevenue = max(0.0, $dpp - $discount);

                $results[$pid]['sold_quantity'] += $qty;
                $results[$pid]['sales_value'] += $netLineRevenue;

                // Track last sale date
                if ($effectiveDate !== null) {
                    if ($results[$pid]['last_sale_date'] === null || $effectiveDate > $results[$pid]['last_sale_date']) {
                        $results[$pid]['last_sale_date'] = $effectiveDate;
                    }
                }

                // Cost calculation: parent line snapshot + bundle component snapshots (never replaced)
                $lineCost = $detail->cost_unit_snapshot !== null
                    ? (float) $detail->cost_unit_snapshot * $qty
                    : (float) ($detail->cost_total_snapshot ?? 0);
                $results[$pid]['sold_cost'] += $lineCost;

                if ($detail->cost_snapshot_source === \Modules\Sale\Services\SalesCostSnapshotService::SOURCE_MISSING_AVERAGE_PRICE
                    || ($detail->cost_unit_snapshot === null && $detail->cost_snapshot_source !== \Modules\Sale\Services\SalesCostSnapshotService::SOURCE_NON_STOCK_MANAGED)) {
                    $results[$pid]['is_cost_incomplete'] = true;
                }

                if ($bundleItemsByDetail->has($detail->id)) {
                    foreach ($bundleItemsByDetail->get($detail->id) as $bundleItem) {
                        $compCost = $bundleItem->cost_unit_snapshot !== null
                            ? (float) $bundleItem->cost_unit_snapshot * (float) $bundleItem->quantity
                            : (float) ($bundleItem->cost_total_snapshot ?? 0);
                        $results[$pid]['sold_cost'] += $compCost;

                        if ($bundleItem->cost_snapshot_source === \Modules\Sale\Services\SalesCostSnapshotService::SOURCE_MISSING_AVERAGE_PRICE
                            || ($bundleItem->cost_unit_snapshot === null && $bundleItem->cost_snapshot_source !== \Modules\Sale\Services\SalesCostSnapshotService::SOURCE_NON_STOCK_MANAGED)) {
                            $results[$pid]['is_cost_incomplete'] = true;
                        }
                    }
                }
            }
        }

        // Finalize gross profit and rounding per product
        foreach ($results as $pid => &$metrics) {
            $metrics['sales_value'] = round($metrics['sales_value'], 2);
            $metrics['sold_cost'] = round($metrics['sold_cost'], 2);
            $metrics['gross_profit'] = round($metrics['sales_value'] - $metrics['sold_cost'], 2);
        }

        return $results;
    }
}
