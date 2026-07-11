<?php

namespace App\Livewire;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use Modules\Product\Entities\Product;

class SearchProduct extends Component
{
    public string $query = '';
    public Collection $search_results;
    public int $how_many = 5;

    public function mount(): void
    {
        $this->search_results = collect();
    }

    public function render()
    {
        return view('livewire.search-product');
    }

    public function updatedQuery(): void
    {
        $term = trim($this->query);

        if (mb_strlen($term) < 2) {
            $this->search_results = collect();

            return;
        }

        $this->search_results = Product::query()
            ->where('stock_managed', true)
            ->globalSearch($term)
            ->take($this->how_many)
            ->get([
                'id',
                'product_name',
                'product_code',
                'product_quantity',
                'product_unit',
                'product_price',
                'sale_price',
                'product_cost',
                'product_tax_type',
                'product_order_tax',
            ])
            ->map(function (Product $product) {
                return [
                    'id' => $product->id,
                    'product_name' => $product->product_name,
                    'product_code' => $product->product_code,
                    'product_quantity' => (int) $product->product_quantity,
                    'product_unit' => $product->product_unit,
                    'product_price' => (float) ($product->sale_price ?? $product->product_price),
                    'sale_price' => (float) ($product->sale_price ?? $product->product_price),
                    'product_cost' => (float) $product->product_cost,
                    'product_tax_type' => (int) $product->product_tax_type,
                    'product_order_tax' => (float) $product->product_order_tax,
                ];
            });
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
        $this->search_results = collect();
    }

    public function showSearchResults(): void
    {
        $this->updatedQuery();
    }

    public function selectProduct($result): void
    {
        $payload = is_array($result) ? $result : (array) $result;

        $this->dispatch('productSelected', $payload);
        $this->resetQuery();
    }
}
