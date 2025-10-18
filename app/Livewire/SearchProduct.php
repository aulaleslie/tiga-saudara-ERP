<?php

namespace App\Livewire;

use App\Livewire\Pos\Checkout;
use App\Support\ProductBundleResolver;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use App\Support\PosLocationResolver;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class SearchProduct extends Component
{
    public string $query = '';
    public $search_results;
    public int $how_many = 5;

    /** POS location resolved from current setting (nullable if none configured) */
    private ?int $posLocationId = null;

    public function mount(): void
    {
        // resolve POS location for the current business/setting
        $this->posLocationId = PosLocationResolver::resolveId();

        $this->search_results = Collection::empty();
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.search-product');
    }

    public function updatedQuery(): void
    {
        $input = trim($this->query);

        if (mb_strlen($input) < 2) {
            $this->search_results = Collection::empty();
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

        $this->search_results = $this->suggestions($input);
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

        $this->dispatch('pos:focus-search');
    }

    /* ===========================
     * Exact scan resolvers
     * =========================== */

    private function tryHandleExactSerial(string $code): bool
    {
        $settingId = session('setting_id');

        $sql = "
        SELECT
            psn.id          AS serial_id,          -- include id
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
                   SUM((quantity_non_tax + quantity_tax) - (broken_quantity_non_tax + broken_quantity_tax)) AS stock_qty
            FROM product_stocks
            WHERE (location_id = COALESCE(:stockLocationId, location_id))
            GROUP BY product_id
        ) st ON st.product_id = p.id
        WHERE LOWER(psn.serial_number) = LOWER(:code)
          AND psn.is_broken = 0
          AND (psn.location_id = COALESCE(:serialLocationId, psn.location_id))
        LIMIT 1
    ";

        $row = DB::selectOne($sql, [
            'code'              => $code,
            'stockLocationId'   => $this->posLocationId,
            'serialLocationId'  => $this->posLocationId,
            'settingId'         => $settingId,
        ]);

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
            COALESCE(puc.price, COALESCE(pp.sale_price, p.sale_price, p.product_price)) AS price,
            COALESCE(pp.sale_price, p.sale_price, p.product_price) AS sale_price,
            COALESCE(pp.tier_1_price, p.tier_1_price) AS tier_1_price,
            COALESCE(pp.tier_2_price, p.tier_2_price) AS tier_2_price,
            u.name          AS unit_name,
            COALESCE(st.stock_qty, 0) AS stock_qty
        FROM product_unit_conversions puc
        JOIN products p   ON p.id = puc.product_id
        LEFT JOIN product_prices pp ON pp.product_id = p.id AND pp.setting_id = :settingId
        LEFT JOIN units u ON u.id = puc.unit_id
        LEFT JOIN (
            SELECT product_id,
                   SUM((quantity_non_tax + quantity_tax) - (broken_quantity_non_tax + broken_quantity_tax)) AS stock_qty
            FROM product_stocks
            WHERE (location_id = COALESCE(:posLocationId1, location_id))
            GROUP BY product_id
        ) st ON st.product_id = p.id
        WHERE LOWER(puc.barcode) = LOWER(:code)
        LIMIT 1
    ";

        $row = DB::selectOne($sql, [
            'code'           => $barcode,
            'posLocationId1' => $this->posLocationId,
            'settingId'      => $settingId,
        ]);

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
                   SUM((quantity_non_tax + quantity_tax) - (broken_quantity_non_tax + broken_quantity_tax)) AS stock_qty
            FROM product_stocks
            WHERE (location_id = COALESCE(:posLocationId1, location_id))
            GROUP BY product_id
        ) st ON st.product_id = p.id
        WHERE LOWER(p.barcode) = LOWER(:code)
        LIMIT 1
    ";

        $row = DB::selectOne($sql, [
            'code'           => $barcode,
            'posLocationId1' => $this->posLocationId,
            'settingId'      => $settingId,
        ]);

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
        $limit = (int) $this->how_many;

        $settingId = session('setting_id');

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
            p.unit_id,
            COALESCE(st.stock_qty, 0) AS product_quantity,
            p.base_unit_id,
            1 AS conversion_factor,
            u.name AS unit_name,
            'base' AS source,
            NULL AS serial_id,
            NULL AS serial_number
        FROM products p
        LEFT JOIN product_prices pp ON pp.product_id = p.id AND pp.setting_id = :settingId_base
        LEFT JOIN (
            SELECT product_id,
                   SUM((quantity_non_tax + quantity_tax) - (broken_quantity_non_tax + broken_quantity_tax)) AS stock_qty
            FROM product_stocks
            WHERE (location_id = COALESCE(:pos1, location_id))
            GROUP BY product_id
        ) st ON st.product_id = p.id
        LEFT JOIN units u ON u.id = p.unit_id

        UNION ALL

        /* Conversion rows */
        SELECT
            p.id,
            p.product_name,
            p.product_code,
            COALESCE(puc.price, COALESCE(pp.sale_price, p.sale_price, p.product_price)) AS price,
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
            NULL AS serial_number
        FROM product_unit_conversions puc
        JOIN products p ON p.id = puc.product_id
        LEFT JOIN product_prices pp ON pp.product_id = p.id AND pp.setting_id = :settingId_conversion
        LEFT JOIN (
            SELECT product_id,
                   SUM((quantity_non_tax + quantity_tax) - (broken_quantity_non_tax + broken_quantity_tax)) AS stock_qty
            FROM product_stocks
            WHERE (location_id = COALESCE(:pos2, location_id))
            GROUP BY product_id
        ) st ON st.product_id = p.id
        LEFT JOIN units u ON u.id = puc.unit_id
        WHERE puc.barcode IS NOT NULL

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
            psn.serial_number
        FROM product_serial_numbers psn
        JOIN products p ON p.id = psn.product_id
        LEFT JOIN product_prices pp ON pp.product_id = p.id AND pp.setting_id = :settingId_serial
        LEFT JOIN (
            SELECT product_id,
                   SUM((quantity_non_tax + quantity_tax) - (broken_quantity_non_tax + broken_quantity_tax)) AS stock_qty
            FROM product_stocks
            WHERE (location_id = COALESCE(:pos3, location_id))
            GROUP BY product_id
        ) st ON st.product_id = p.id
        LEFT JOIN units ub ON ub.id = p.base_unit_id
        WHERE psn.is_broken = 0
          AND (psn.location_id = COALESCE(:pos4, psn.location_id))
    ) results
    WHERE (
        LOWER(results.product_name) LIKE :term1
        OR LOWER(results.product_code) LIKE :term2
        OR LOWER(results.barcode)     LIKE :term3
        OR LOWER(results.serial_number) LIKE :term4
    )
    AND results.product_quantity > 0
    LIMIT {$limit}
    ";

        return collect(DB::select($sql, [
            'pos1'  => $this->posLocationId,
            'pos2'  => $this->posLocationId,
            'pos3'  => $this->posLocationId,
            'pos4'  => $this->posLocationId,
            'term1' => $term,
            'term2' => $term,
            'term3' => $term,
            'term4' => $term,
            'settingId_base'        => $settingId,
            'settingId_conversion'  => $settingId,
            'settingId_serial'      => $settingId,
        ]));
    }
}
