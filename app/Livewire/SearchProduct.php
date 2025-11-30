<?php

namespace App\Livewire;

use App\Livewire\Pos\Checkout;
use App\Livewire\Pos\ProductList;
use App\Support\ProductBundleResolver;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use App\Support\PosLocationResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class SearchProduct extends Component
{
    public string $query = '';
    public $search_results;
    public int $how_many = 5;

    /** Number of product results (excluding helper actions). */
    public int $resultCount = 0;

    /** All sale location ids configured for the active setting */
    private array $posLocationIds = [];

    /** Cache bundle sell availability checks per product. */
    private array $bundleSellableCache = [];

    public function mount(): void
    {
        // resolve POS location for the current business/setting
        $settingId = session('setting_id');
        $this->posLocationIds = PosLocationResolver::resolveLocationIds()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        Log::info('SearchProduct mounted', [
            'settingId' => $settingId,
            'posLocationIds' => $this->posLocationIds,
        ]);

        $this->search_results = Collection::empty();
        $this->resultCount = 0;
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.search-product');
    }

    public function updatedQuery(): void
    {
        $input = trim($this->query);

        if (mb_strlen($input) < 2) {
            $this->dispatch('posSearchUpdated', '')->to(ProductList::class);
            $this->search_results = Collection::empty();
            $this->resultCount = 0;
            return;
        }

        if ($this->tryHandleExactSerial($input)) {
            $this->resetQuery();
            return;
        }
        if ($this->tryHandleExactConversionBarcode($input)) {
            $this->resetQuery();
            return;
        }
        if ($this->tryHandleExactProductBarcode($input)) {
            $this->resetQuery();
            return;
        }

        $results = $this->suggestions($input);
        
        // Debug: log what we're getting
        Log::info('SearchProduct updatedQuery', [
            'input' => $input,
            'posLocationIds' => $this->posLocationIds,
            'results_count' => $results->count(),
            'results' => $results->take(3)->toArray(),
        ]);
        
        $this->resultCount = $results->count();

        $results->push((object) [
            'source' => 'action',
            'action' => 'show_results',
            'label'  => 'Tunjukkan hasil pencarian',
            'query'  => $input,
        ]);

        $this->search_results = $results;
    }

    public function loadMore(): void
    {
        $this->how_many += 5;
        $this->updatedQuery();
    }

    public function resetQuery(): void
    {
        $this->query = '';
        $this->how_many = 5;
        $this->search_results = Collection::empty();
        $this->resultCount = 0;

        $this->dispatch('posSearchUpdated', '')->to(ProductList::class);
        $this->dispatch('pos:focus-search');
    }

    public function showSearchResults(): void
    {
        $term = trim($this->query);

        $this->dispatch('posSearchUpdated', $term)->to(ProductList::class);

        $this->query = '';
        $this->how_many = 5;
        $this->search_results = Collection::empty();
        $this->resultCount = 0;

        $this->dispatch('pos:focus-search');
    }

    /* ===========================
     * Exact scan resolvers
     * =========================== */

    private function tryHandleExactSerial(string $code): bool
    {
        $settingId = session('setting_id');

        $stockFilter = $this->buildLocationFilter('location_id', 'serial_stock', true);
        $serialFilter = $this->buildLocationFilter('psn.location_id', 'serial_loc', true);
        $sql = "
        SELECT
            psn.id          AS serial_id,
            psn.serial_number,
            p.id            AS product_id,
            p.product_name,
            p.product_code,
            p.serial_number_required,
            p.unit_id,
            p.base_unit_id,
            COALESCE(pp.sale_price, p.sale_price, p.product_price) AS price,
            COALESCE(pp.sale_price, p.sale_price, p.product_price) AS sale_price,
            COALESCE(pp.tier_1_price, p.tier_1_price) AS tier_1_price,
            COALESCE(pp.tier_2_price, p.tier_2_price) AS tier_2_price,
            u.name          AS unit_name,
            COALESCE(st.stock_qty, 0) AS stock_qty
        FROM product_serial_numbers psn
        JOIN products p ON p.id = psn.product_id
        LEFT JOIN product_prices pp ON pp.product_id = p.id AND pp.setting_id = :settingId
        LEFT JOIN units u ON u.id = p.base_unit_id
        LEFT JOIN (
            SELECT product_id,
                   SUM(quantity_non_tax + quantity_tax) AS stock_qty
            FROM product_stocks
            WHERE {stock_filter}
            GROUP BY product_id
        ) st ON st.product_id = p.id
        WHERE LOWER(psn.serial_number) = LOWER(:code)
          AND psn.is_broken = 0
          AND psn.dispatch_detail_id IS NULL
          AND {serial_filter}
        LIMIT 1
    ";

        $sql = str_replace('{stock_filter}', $stockFilter['sql'], $sql);
        $sql = str_replace('{serial_filter}', $serialFilter['sql'], $sql);

        $bindings = array_merge(
            ['code' => $code, 'settingId' => $settingId],
            $stockFilter['bindings'],
            $serialFilter['bindings'],
        );

        $row = DB::selectOne($sql, $bindings);

        if (!$row) return false;

        if ((int) $row->stock_qty <= 0) {
            return false;
        }

        $bundles = ProductBundleResolver::forProduct((int) $row->product_id);
        if ($bundles->isNotEmpty()) {
            $payload = [
                'id'                    => (int) $row->product_id,
                'product_name'          => (string) $row->product_name,
                'product_code'          => (string) $row->product_code,
                'product_quantity'      => (int) $row->stock_qty,
                'conversion_factor'     => 1,
                'serial_number_required'=> (bool) $row->serial_number_required,
                'price'                 => (float) $row->price,
                'sale_price'            => (float) $row->sale_price,
                'tier_1_price'          => $row->tier_1_price !== null ? (float) $row->tier_1_price : null,
                'tier_2_price'          => $row->tier_2_price !== null ? (float) $row->tier_2_price : null,
                'pending_serials'       => [[
                    'id'            => (int) $row->serial_id,
                    'serial_number' => (string) $row->serial_number,
                ]],
            ];

            $this->dispatch('productSelected', $payload)->to(Checkout::class);
            return true;
        }

        // Send to Checkout listener
        $this->dispatch('serialScanned', [
            'product_id' => (int) $row->product_id,
            'serial'     => [
                'id'            => (int) $row->serial_id,
                'serial_number' => (string) $row->serial_number,
            ],
            'price'       => (float) $row->price,
            'sale_price'  => (float) $row->sale_price,
            'tier_1_price'=> $row->tier_1_price !== null ? (float) $row->tier_1_price : null,
            'tier_2_price'=> $row->tier_2_price !== null ? (float) $row->tier_2_price : null,
        ])->to(Checkout::class);

        return true; // updatedQuery() will call resetQuery() → focuses input
    }

    private function tryHandleExactConversionBarcode(string $barcode): bool
    {
        $settingId = session('setting_id');

        $stockFilter = $this->buildLocationFilter('location_id', 'conversion_stock');

        $sql = "
        SELECT
            p.id            AS product_id,
            p.product_name,
            p.product_code,
            p.serial_number_required,
            puc.barcode,
            puc.unit_id,
            puc.base_unit_id,
            puc.conversion_factor,
            COALESCE(pucp.price, COALESCE(pp.sale_price, p.sale_price, p.product_price)) AS price,
            COALESCE(pp.sale_price, p.sale_price, p.product_price) AS sale_price,
            COALESCE(pp.tier_1_price, p.tier_1_price) AS tier_1_price,
            COALESCE(pp.tier_2_price, p.tier_2_price) AS tier_2_price,
            u.name          AS unit_name,
            COALESCE(st.stock_qty, 0) AS stock_qty
        FROM product_unit_conversions puc
        JOIN products p   ON p.id = puc.product_id
        LEFT JOIN product_unit_conversion_prices pucp
            ON pucp.product_unit_conversion_id = puc.id
           AND pucp.setting_id = :conversionSettingId
        LEFT JOIN product_prices pp ON pp.product_id = p.id AND pp.setting_id = :productSettingId
        LEFT JOIN units u ON u.id = puc.unit_id
        LEFT JOIN (
            SELECT product_id,
                   SUM(quantity_non_tax + quantity_tax) AS stock_qty
            FROM product_stocks
            WHERE {stock_filter}
            GROUP BY product_id
        ) st ON st.product_id = p.id
        WHERE LOWER(puc.barcode) = LOWER(:code)
        LIMIT 1
    ";

        $sql = str_replace('{stock_filter}', $stockFilter['sql'], $sql);

        $bindings = array_merge(
            [
                'code' => $barcode,
                'conversionSettingId' => $settingId,
                'productSettingId' => $settingId,
            ],
            $stockFilter['bindings'],
        );

        $row = DB::selectOne($sql, $bindings);

        if (!$row) return false;
        if ((int) $row->stock_qty <= 0) return false;

        $payload = [
            'id'                => (int) $row->product_id,
            'product_name'      => (string) $row->product_name,
            'product_code'      => (string) $row->product_code,
            'product_quantity'  => (int) $row->stock_qty,
            'unit_id'           => (int) $row->unit_id,
            'unit_name'         => (string) $row->unit_name,
            'conversion_factor' => (float) $row->conversion_factor, // pass CF
            'price'             => (float) $row->price,
            'sale_price'        => (float) $row->sale_price,
            'tier_1_price'      => $row->tier_1_price !== null ? (float) $row->tier_1_price : null,
            'tier_2_price'      => $row->tier_2_price !== null ? (float) $row->tier_2_price : null,
            'barcode'           => (string) $row->barcode,
            'source'            => 'conversion',
        ];

        $this->dispatch('productSelected', $payload)->to(Checkout::class);
        return true; // updatedQuery() will reset & refocus
    }

    public function selectProduct($result): void
    {
        // normalize stdClass -> array
        $data = is_array($result) ? $result : (array) $result;

        if (($data['source'] ?? null) === 'action' && ($data['action'] ?? null) === 'show_results') {
            $this->showSearchResults();
            return;
        }

        // guard: no stock
        if (isset($data['product_quantity']) && (int)$data['product_quantity'] <= 0) {
            $this->dispatch('pos:toast', ['type' => 'warning', 'message' => 'Produk habis stok']);
            $this->resetQuery();
            return;
        }

        $source = $data['source'] ?? 'base';

        if ($source === 'serial' && !empty($data['serial_number'])) {
            // forward to Checkout::onSerialScanned
            $this->dispatch('serialScanned', [
                'product_id' => (int)$data['id'],
                'serial' => [
                    'id' => (int)($data['serial_id'] ?? 0),
                    'serial_number' => (string)$data['serial_number'],
                ],
                'price' => isset($data['price']) ? (float)$data['price'] : 0.0,
                'sale_price' => isset($data['sale_price']) ? (float)$data['sale_price'] : 0.0,
                'tier_1_price' => array_key_exists('tier_1_price', $data) && $data['tier_1_price'] !== null
                    ? (float)$data['tier_1_price']
                    : null,
                'tier_2_price' => array_key_exists('tier_2_price', $data) && $data['tier_2_price'] !== null
                    ? (float)$data['tier_2_price']
                    : null,
            ])->to('pos.checkout');

            $this->resetQuery();
            return;
        }

        // base / conversion → forward the whole suggestion to Checkout::productSelected
        $this->dispatch('productSelected', $data)->to('pos.checkout');
        $this->resetQuery();
    }

    private function tryHandleExactProductBarcode(string $barcode): bool
    {
        $settingId = session('setting_id');

        $stockFilter = $this->buildLocationFilter('location_id', 'product_stock');

        $sql = "
        SELECT
            p.id            AS product_id,
            p.product_name,
            p.product_code,
            p.serial_number_required,
            p.barcode,
            p.unit_id,
            p.base_unit_id,
            COALESCE(pp.sale_price, p.sale_price, p.product_price) AS price,
            COALESCE(pp.sale_price, p.sale_price, p.product_price) AS sale_price,
            COALESCE(pp.tier_1_price, p.tier_1_price) AS tier_1_price,
            COALESCE(pp.tier_2_price, p.tier_2_price) AS tier_2_price,
            u.name          AS unit_name,
            COALESCE(st.stock_qty, 0) AS stock_qty
        FROM products p
        LEFT JOIN product_prices pp ON pp.product_id = p.id AND pp.setting_id = :settingId
        LEFT JOIN units u ON u.id = p.unit_id
        LEFT JOIN (
            SELECT product_id,
                   SUM(quantity_non_tax + quantity_tax) AS stock_qty
            FROM product_stocks
            WHERE {stock_filter}
            GROUP BY product_id
        ) st ON st.product_id = p.id
        WHERE LOWER(p.barcode) = LOWER(:code)
        LIMIT 1
    ";

        $sql = str_replace('{stock_filter}', $stockFilter['sql'], $sql);

        $bindings = array_merge(
            ['code' => $barcode, 'settingId' => $settingId],
            $stockFilter['bindings'],
        );

        $row = DB::selectOne($sql, $bindings);

        if (!$row) return false;
        if ((int) $row->stock_qty <= 0) return false;

        $payload = [
            'id'                => (int) $row->product_id,
            'product_name'      => (string) $row->product_name,
            'product_code'      => (string) $row->product_code,
            'product_quantity'  => (int) $row->stock_qty,
            'unit_id'           => (int) ($row->unit_id ?? $row->base_unit_id),
            'unit_name'         => (string) $row->unit_name,
            'conversion_factor' => 1.0,
            'price'             => (float) $row->price,
            'sale_price'        => (float) $row->sale_price,
            'tier_1_price'      => $row->tier_1_price !== null ? (float) $row->tier_1_price : null,
            'tier_2_price'      => $row->tier_2_price !== null ? (float) $row->tier_2_price : null,
            'barcode'           => (string) $row->barcode,
            'source'            => 'base',
        ];

        $this->dispatch('productSelected', $payload)->to(Checkout::class);
        return true;
    }

    /* ===========================
     * Suggestions (LIKE search)
     * =========================== */
    private function suggestions(string $input): Collection
    {
        $term  = '%' . mb_strtolower($input) . '%';
        $serialTerm = '%' . mb_strtolower($input) . '%';
        $limit = (int) $this->how_many;

        $settingId = session('setting_id');

        $baseStockFilter = $this->buildLocationFilter('location_id', 'suggest_base', false);
        $conversionStockFilter = $this->buildLocationFilter('location_id', 'suggest_conversion', false);
        $serialStockFilter = $this->buildLocationFilter('location_id', 'suggest_serial', false);
        $serialLocationFilter = $this->buildLocationFilter('psn.location_id', 'suggest_serial_loc', false);

        $sql = "
    SELECT * FROM (
        /* Base rows */
        SELECT
            p.id,
            p.product_name,
            p.product_code,
            COALESCE(pp.sale_price, p.sale_price, p.product_price) AS price,
            COALESCE(pp.sale_price, p.sale_price, p.product_price) AS sale_price,
            COALESCE(pp.tier_1_price, p.tier_1_price) AS tier_1_price,
            COALESCE(pp.tier_2_price, p.tier_2_price) AS tier_2_price,
            p.barcode,
            COALESCE(p.unit_id, p.base_unit_id) AS unit_id,
            COALESCE(st.stock_qty, 0) AS product_quantity,
            p.base_unit_id,
            1 AS conversion_factor,
            u.name AS unit_name,
            'base' AS source,
            NULL AS serial_id,
            NULL AS serial_number,
            p.serial_number_required,
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM product_bundles pb
                    WHERE pb.parent_product_id = p.id
                ) THEN 1 ELSE 0
            END AS has_bundle
        FROM products p
        LEFT JOIN product_prices pp ON pp.product_id = p.id AND pp.setting_id = :settingId_base
        LEFT JOIN (
            SELECT product_id,
                   SUM(quantity_non_tax + quantity_tax) AS stock_qty
            FROM product_stocks
            WHERE {base_stock_filter}
            GROUP BY product_id
        ) st ON st.product_id = p.id
        LEFT JOIN units u ON u.id = COALESCE(p.unit_id, p.base_unit_id)
        WHERE p.serial_number_required = 0
          AND (
              LOWER(p.product_name) LIKE :term_base_name
              OR LOWER(p.product_code) LIKE :term_base_code
              OR LOWER(p.barcode) LIKE :term_base_barcode
          )

        UNION ALL

        /* Conversion rows */
        SELECT
            p.id,
            p.product_name,
            p.product_code,
            COALESCE(pucp.price, COALESCE(pp.sale_price, p.sale_price, p.product_price)) AS price,
            COALESCE(pp.sale_price, p.sale_price, p.product_price) AS sale_price,
            COALESCE(pp.tier_1_price, p.tier_1_price) AS tier_1_price,
            COALESCE(pp.tier_2_price, p.tier_2_price) AS tier_2_price,
            puc.barcode,
            puc.unit_id,
            COALESCE(st.stock_qty, 0) AS product_quantity,
            puc.base_unit_id,
            puc.conversion_factor,
            u.name AS unit_name,
            'conversion' AS source,
            NULL AS serial_id,
            NULL AS serial_number,
            p.serial_number_required,
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM product_bundles pb
                    WHERE pb.parent_product_id = p.id
                ) THEN 1 ELSE 0
            END AS has_bundle
        FROM product_unit_conversions puc
        JOIN products p ON p.id = puc.product_id
        LEFT JOIN product_unit_conversion_prices pucp
            ON pucp.product_unit_conversion_id = puc.id
           AND pucp.setting_id = :settingId_conversion_pucp
        LEFT JOIN product_prices pp ON pp.product_id = p.id AND pp.setting_id = :settingId_conversion_pp
        LEFT JOIN (
            SELECT product_id,
                   SUM(quantity_non_tax + quantity_tax) AS stock_qty
            FROM product_stocks
            WHERE {conversion_stock_filter}
            GROUP BY product_id
        ) st ON st.product_id = p.id
        LEFT JOIN units u ON u.id = puc.unit_id
        WHERE p.serial_number_required = 0
          AND (
              LOWER(p.product_name) LIKE :term_conv_name
              OR LOWER(p.product_code) LIKE :term_conv_code
              OR LOWER(puc.barcode) LIKE :term_conv_barcode
          )

        UNION ALL

        /* Serial rows */
        SELECT
            p.id,
            p.product_name,
            p.product_code,
            COALESCE(pp.sale_price, p.sale_price, p.product_price) AS price,
            COALESCE(pp.sale_price, p.sale_price, p.product_price) AS sale_price,
            COALESCE(pp.tier_1_price, p.tier_1_price) AS tier_1_price,
            COALESCE(pp.tier_2_price, p.tier_2_price) AS tier_2_price,
            p.barcode,
            p.base_unit_id AS unit_id,
            COALESCE(st.stock_qty, 0) AS product_quantity,
            p.base_unit_id,
            1 AS conversion_factor,
            ub.name AS unit_name,
            'serial' AS source,
            psn.id AS serial_id,
            psn.serial_number,
            p.serial_number_required,
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM product_bundles pb
                    WHERE pb.parent_product_id = p.id
                ) THEN 1 ELSE 0
            END AS has_bundle
        FROM product_serial_numbers psn
        JOIN products p ON p.id = psn.product_id
        LEFT JOIN product_prices pp ON pp.product_id = p.id AND pp.setting_id = :settingId_serial
        LEFT JOIN (
            SELECT product_id,
                   SUM(quantity_non_tax + quantity_tax) AS stock_qty
            FROM product_stocks
            WHERE {serial_stock_filter}
            GROUP BY product_id
        ) st ON st.product_id = p.id
        LEFT JOIN units ub ON ub.id = p.base_unit_id
        WHERE psn.is_broken = 0
          AND psn.dispatch_detail_id IS NULL
          AND {serial_location_filter}
          AND LOWER(psn.serial_number) LIKE :term_serial
          AND p.serial_number_required = 1
    ) results
    WHERE results.product_quantity > 0
    ORDER BY results.product_name ASC
    LIMIT {$limit}
    ";

        $sql = str_replace('{base_stock_filter}', $baseStockFilter['sql'], $sql);
        $sql = str_replace('{conversion_stock_filter}', $conversionStockFilter['sql'], $sql);
        $sql = str_replace('{serial_stock_filter}', $serialStockFilter['sql'], $sql);
        $sql = str_replace('{serial_location_filter}', $serialLocationFilter['sql'], $sql);

        $bindings = array_merge(
            $baseStockFilter['bindings'],
            $conversionStockFilter['bindings'],
            $serialStockFilter['bindings'],
            $serialLocationFilter['bindings'],
            [
                'term_base_name' => $term,
                'term_base_code' => $term,
                'term_base_barcode' => $term,
                'term_conv_name' => $term,
                'term_conv_code' => $term,
                'term_conv_barcode' => $term,
                'term_serial'    => $serialTerm,
                'settingId_base'            => $settingId,
                'settingId_conversion_pucp' => $settingId,
                'settingId_conversion_pp'   => $settingId,
                'settingId_serial'          => $settingId,
            ]
        );

        $results = collect(DB::select($sql, $bindings))
            ->filter(function ($row) {
                $productId = (int) ($row->id ?? 0);

                if ($productId <= 0) {
                    return false;
                }

                $sellable = $this->isBundleSellable($productId);
                $row->bundle_sellable = $sellable;

                return $sellable;
            })
            ->map(function ($row) {
                $row->product_quantity = (int) ($row->product_quantity ?? 0);
                $row->serial_number_required = (bool) ($row->serial_number_required ?? false);
                $row->has_bundle = (bool) ($row->has_bundle ?? false);

                return $row;
            })
            ->values();

        return $results;
    }

    /**
     * Build an SQL IN clause & bindings for the configured POS sale locations.
     */
    private function buildLocationFilter(string $column, string $bindingPrefix, bool $requireLocations = false): array
    {
        if (empty($this->posLocationIds)) {
            return [
                'sql' => $requireLocations ? '0 = 1' : '1 = 1',
                'bindings' => [],
            ];
        }

        $placeholders = [];
        $bindings = [];

        foreach ($this->posLocationIds as $index => $locationId) {
            $key = $bindingPrefix . '_' . $index;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $locationId;
        }

        return [
            'sql' => sprintf('%s IN (%s)', $column, implode(', ', $placeholders)),
            'bindings' => $bindings,
        ];
    }

    private function isBundleSellable(int $productId): bool
    {
        if ($productId <= 0) {
            return false;
        }

        if (!array_key_exists($productId, $this->bundleSellableCache)) {
            $bundles = ProductBundleResolver::forProduct($productId);

            if ($bundles->isEmpty()) {
                $this->bundleSellableCache[$productId] = true;
            } else {
                $this->bundleSellableCache[$productId] = $bundles->contains(function ($bundle) {
                    return $bundle->items && $bundle->items->isNotEmpty();
                });
            }
        }

        return $this->bundleSellableCache[$productId];
    }
}
