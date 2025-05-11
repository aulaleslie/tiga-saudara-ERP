<?php

namespace App\Livewire;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Transaction;

class SearchProduct extends Component
{
    public string $query = '';
    public $search_results;
    public int $how_many = 5;


    public function mount(): void
    {
        $this->search_results = Collection::empty();
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.search-product');
    }

    public function updatedQuery(): void
    {
        $term = '%' . strtolower($this->query) . '%';

        $results = collect(DB::select("
        SELECT * FROM (
            SELECT
                p.id AS id,
                p.product_name,
                p.product_code,
                p.sale_price,
                p.barcode,
                p.unit_id,
                p.product_quantity,
                p.base_unit_id,
                1 AS conversion_factor,
                u.name AS unit_name,
                'base' AS source
            FROM products p
            JOIN units u ON u.id = p.base_unit_id

            UNION

            SELECT
                p.id AS id,
                p.product_name,
                p.product_code,
                p.sale_price,
                puc.barcode,
                puc.unit_id,
                p.product_quantity,
                puc.base_unit_id,
                puc.conversion_factor,
                u.name AS unit_name,
                'conversion' AS source
            FROM product_unit_conversions puc
            JOIN products p ON p.id = puc.product_id
            JOIN units u ON u.id = puc.unit_id
            WHERE puc.barcode IS NOT NULL
        ) results
        WHERE LOWER(product_name) LIKE ? OR LOWER(product_code) LIKE ? OR LOWER(barcode) LIKE ?
        LIMIT ?
    ", [$term, $term, $term, $this->how_many]));

        $this->search_results = $results;

        // Auto-select if exactly 1 result and input is more than 10 characters
        if ($results->count() === 1 && strlen($this->query) > 10) {
            $this->selectProduct($results->first());
            $this->resetQuery();
        }
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
    }

    public function selectProduct($product): void
    {
        $this->dispatch('productSelected', $product);
    }
}
