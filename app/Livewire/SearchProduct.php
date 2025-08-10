<?php

namespace App\Livewire;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Modules\Setting\Entities\Location;

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
        $settingId = session('setting_id');
        $this->posLocationId = Location::where('setting_id', $settingId)
            ->where('is_pos', true)
            ->value('id');

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
        $sql = "
            SELECT
                psn.serial_number,
                p.id            AS product_id,
                p.product_name,
                p.product_code,
                p.serial_number_required,
                p.unit_id,
                p.base_unit_id,
                CASE WHEN p.sale_price > 0 THEN p.sale_price ELSE p.product_price END AS price,
                u.name          AS unit_name
            FROM product_serial_numbers psn
            JOIN products p ON p.id = psn.product_id
            LEFT JOIN units u ON u.id = p.base_unit_id
            WHERE LOWER(psn.serial_number) = LOWER(:code)
              AND psn.is_broken = 0
              AND (:posLocationId IS NULL OR psn.location_id = :posLocationId)
            LIMIT 1
        ";

        $row = DB::selectOne($sql, [
            'code'          => $code,
            'posLocationId' => $this->posLocationId,
        ]);

        if (!$row) return false;

        $this->dispatch('pos:serial-scanned', [
            'matched_by'        => 'serial',
            'product_id'        => $row->product_id,
            'product_name'      => $row->product_name,
            'product_code'      => $row->product_code,
            'serial_number'     => $row->serial_number,
            'unit_id'           => $row->unit_id ?? $row->base_unit_id,
            'unit_name'         => $row->unit_name,
            'conversion_factor' => 1,
            'quantity'          => 1,
            'price'             => (float) $row->price,
            'location_id'       => $this->posLocationId,
        ]);

        return true;
    }

    private function tryHandleExactConversionBarcode(string $barcode): bool
    {
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
                COALESCE(puc.price, CASE WHEN p.sale_price > 0 THEN p.sale_price ELSE p.product_price END) AS price,
                u.name          AS unit_name,
                COALESCE(st.stock_qty, 0) AS stock_qty
            FROM product_unit_conversions puc
            JOIN products p   ON p.id = puc.product_id
            LEFT JOIN units u ON u.id = puc.unit_id
            LEFT JOIN (
                SELECT product_id,
                       SUM((quantity_non_tax + quantity_tax) - (broken_quantity_non_tax + broken_quantity_tax)) AS stock_qty
                FROM product_stocks
                WHERE (:posLocationId IS NULL OR location_id = :posLocationId)
                GROUP BY product_id
            ) st ON st.product_id = p.id
            WHERE LOWER(puc.barcode) = LOWER(:code)
            LIMIT 1
        ";

        $row = DB::selectOne($sql, [
            'code'          => $barcode,
            'posLocationId' => $this->posLocationId,
        ]);

        if (!$row) return false;
        if ((int) $row->stock_qty <= 0) return false;

        $expectedCount = (int) round($row->conversion_factor);

        if ((int) $row->serial_number_required === 1) {
            $this->dispatch('pos:request-serials', [
                'matched_by'        => 'conversion_barcode',
                'product_id'        => $row->product_id,
                'product_name'      => $row->product_name,
                'product_code'      => $row->product_code,
                'unit_id'           => $row->unit_id,
                'unit_name'         => $row->unit_name,
                'conversion_factor' => (float) $row->conversion_factor,
                'expected_count'    => $expectedCount,
                'price'             => (float) $row->price,
                'location_id'       => $this->posLocationId,
            ]);
        } else {
            $this->dispatch('pos:add-line', [
                'matched_by'        => 'conversion_barcode',
                'product_id'        => $row->product_id,
                'product_name'      => $row->product_name,
                'product_code'      => $row->product_code,
                'unit_id'           => $row->unit_id,
                'unit_name'         => $row->unit_name,
                'conversion_factor' => (float) $row->conversion_factor,
                'quantity'          => $expectedCount,
                'price'             => (float) $row->price,
                'location_id'       => $this->posLocationId,
            ]);
        }

        return true;
    }

    private function tryHandleExactProductBarcode(string $barcode): bool
    {
        $sql = "
            SELECT
                p.id            AS product_id,
                p.product_name,
                p.product_code,
                p.serial_number_required,
                p.barcode,
                p.unit_id,
                p.base_unit_id,
                CASE WHEN p.sale_price > 0 THEN p.sale_price ELSE p.product_price END AS price,
                u.name          AS unit_name,
                COALESCE(st.stock_qty, 0) AS stock_qty
            FROM products p
            LEFT JOIN units u ON u.id = p.unit_id
            LEFT JOIN (
                SELECT product_id,
                       SUM((quantity_non_tax + quantity_tax) - (broken_quantity_non_tax + broken_quantity_tax)) AS stock_qty
                FROM product_stocks
                WHERE (:posLocationId IS NULL OR location_id = :posLocationId)
                GROUP BY product_id
            ) st ON st.product_id = p.id
            WHERE LOWER(p.barcode) = LOWER(:code)
            LIMIT 1
        ";

        $row = DB::selectOne($sql, [
            'code'          => $barcode,
            'posLocationId' => $this->posLocationId,
        ]);

        if (!$row) return false;
        if ((int) $row->stock_qty <= 0) return false;

        if ((int) $row->serial_number_required === 1) {
            $this->dispatch('pos:request-serials', [
                'matched_by'        => 'product_barcode',
                'product_id'        => $row->product_id,
                'product_name'      => $row->product_name,
                'product_code'      => $row->product_code,
                'unit_id'           => $row->unit_id ?? $row->base_unit_id,
                'unit_name'         => $row->unit_name,
                'conversion_factor' => 1,
                'expected_count'    => 1,
                'price'             => (float) $row->price,
                'location_id'       => $this->posLocationId,
            ]);
        } else {
            $this->dispatch('pos:add-line', [
                'matched_by'        => 'product_barcode',
                'product_id'        => $row->product_id,
                'product_name'      => $row->product_name,
                'product_code'      => $row->product_code,
                'unit_id'           => $row->unit_id ?? $row->base_unit_id,
                'unit_name'         => $row->unit_name,
                'conversion_factor' => 1,
                'quantity'          => 1,
                'price'             => (float) $row->price,
                'location_id'       => $this->posLocationId,
            ]);
        }

        return true;
    }

    /* ===========================
     * Suggestions (LIKE search)
     * =========================== */
    private function suggestions(string $input): Collection
    {
        $term = '%' . mb_strtolower($input) . '%';
        $limit = (int) $this->how_many;

        $sql = "
        SELECT * FROM (
            /* Base rows */
            SELECT
                p.id,
                p.product_name,
                p.product_code,
                CASE WHEN p.sale_price > 0 THEN p.sale_price ELSE p.product_price END AS price,
                p.barcode,
                p.unit_id,
                COALESCE(st.stock_qty, 0) AS product_quantity,
                p.base_unit_id,
                1 AS conversion_factor,
                u.name AS unit_name,
                'base' AS source,
                NULL AS serial_number
            FROM products p
            LEFT JOIN (
                SELECT product_id,
                       SUM((quantity_non_tax + quantity_tax) - (broken_quantity_non_tax + broken_quantity_tax)) AS stock_qty
                FROM product_stocks
                WHERE (:posLocationId IS NULL OR location_id = :posLocationId)
                GROUP BY product_id
            ) st ON st.product_id = p.id
            LEFT JOIN units u ON u.id = p.unit_id

            UNION ALL

            /* Conversion rows */
            SELECT
                p.id,
                p.product_name,
                p.product_code,
                COALESCE(puc.price, CASE WHEN p.sale_price > 0 THEN p.sale_price ELSE p.product_price END) AS price,
                puc.barcode,
                puc.unit_id,
                COALESCE(st.stock_qty, 0) AS product_quantity,
                puc.base_unit_id,
                puc.conversion_factor,
                u.name AS unit_name,
                'conversion' AS source,
                NULL AS serial_number
            FROM product_unit_conversions puc
            JOIN products p ON p.id = puc.product_id
            LEFT JOIN (
                SELECT product_id,
                       SUM((quantity_non_tax + quantity_tax) - (broken_quantity_non_tax + broken_quantity_tax)) AS stock_qty
                FROM product_stocks
                WHERE (:posLocationId IS NULL OR location_id = :posLocationId)
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
                CASE WHEN p.sale_price > 0 THEN p.sale_price ELSE p.product_price END AS price,
                p.barcode,
                p.base_unit_id AS unit_id,
                COALESCE(st.stock_qty, 0) AS product_quantity,
                p.base_unit_id,
                1 AS conversion_factor,
                ub.name AS unit_name,
                'serial' AS source,
                psn.serial_number
            FROM product_serial_numbers psn
            JOIN products p ON p.id = psn.product_id
            LEFT JOIN (
                SELECT product_id,
                       SUM((quantity_non_tax + quantity_tax) - (broken_quantity_non_tax + broken_quantity_tax)) AS stock_qty
                FROM product_stocks
                WHERE (:posLocationId IS NULL OR location_id = :posLocationId)
                GROUP BY product_id
            ) st ON st.product_id = p.id
            LEFT JOIN units ub ON ub.id = p.base_unit_id
            WHERE psn.is_broken = 0
              AND (:posLocationId IS NULL OR psn.location_id = :posLocationId)
        ) results
        WHERE (
            LOWER(results.product_name) LIKE :term
            OR LOWER(results.product_code) LIKE :term
            OR LOWER(results.barcode) LIKE :term
            OR LOWER(results.serial_number) LIKE :term
        )
        AND results.product_quantity > 0
        LIMIT {$limit}
        ";

        return collect(DB::select($sql, [
            'posLocationId' => $this->posLocationId,
            'term'          => $term,
        ]));
    }
}
