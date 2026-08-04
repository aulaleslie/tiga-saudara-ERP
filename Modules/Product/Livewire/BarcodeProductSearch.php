<?php

namespace Modules\Product\Livewire;

use Illuminate\Support\Collection;
use Livewire\Component;
use Modules\Product\Entities\Product;

/**
 * Product search for the barcode batch workspace. Unlike the shared sales
 * search it is not limited to stock-managed products and it never carries
 * barcode or price data to the client — the batch endpoint re-resolves both.
 */
class BarcodeProductSearch extends Component
{
    public string $query = '';
    public Collection $search_results;
    public int $how_many = 5;

    public function mount(): void
    {
        $this->search_results = collect();
    }

    public function updatedQuery(): void
    {
        $term = trim($this->query);

        if (mb_strlen($term) < 2) {
            $this->search_results = collect();

            return;
        }

        $this->search_results = Product::query()
            ->globalSearch($term)
            ->take($this->how_many)
            ->get(['id', 'product_name', 'product_code', 'product_unit'])
            ->map(fn (Product $product) => [
                'id' => (int) $product->id,
                'product_name' => (string) $product->product_name,
                'product_code' => (string) $product->product_code,
                'product_unit' => (string) $product->product_unit,
            ]);
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

    public function render()
    {
        return view('product::livewire.barcode-product-search');
    }
}
