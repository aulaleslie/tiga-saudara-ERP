<?php

namespace App\Livewire\AutoComplete;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;
use Modules\Product\Entities\Product;

class ProductLoader extends Component
{
    public $query = '';  // User input for search
    public $search_results = []; // Search results
    public $index; // Row index in table (passed from parent)
    public $isFocused = false;
    public $query_count = 0;
    public $how_many = 10; // Limit for search results

    // New property to hold the selected product
    public $selectedProduct = null;

    public function updatedQuery(): void
    {
        // Only search if not already selected
        if ($this->isFocused && !$this->selectedProduct) {
            $this->searchProducts();
        } else {
            $this->search_results = [];
        }
    }

    public function resetQueryAfterDelay(): void
    {
        sleep(1); // Small delay before closing
        $this->isFocused = false;
    }

    public function searchProducts(): void
    {
        $setting_id = session('setting_id');
        if ($this->query) {
            $this->query_count = Product::where('stock_managed', true)
                ->where('setting_id', $setting_id)
                ->where(function ($query) {
                    $query->where('product_name', 'like', '%' . $this->query . '%')
                        ->orWhere('product_code', 'like', '%' . $this->query . '%');
                })
                ->count();
            $this->search_results = Product::where('stock_managed', true)
                ->where('setting_id', $setting_id)
                ->where(function ($query) {
                    $query->where('product_name', 'like', '%' . $this->query . '%')
                        ->orWhere('product_code', 'like', '%' . $this->query . '%');
                })
                ->take($this->how_many)
                ->get();
        } else {
            $this->dispatch('productSelected', [
                'index'   => $this->index,
                'product' => null,
            ]);
        }
    }

    public function selectProduct($productId): void
    {
        $product = Product::find($productId);
        if ($product) {
            $this->selectedProduct = $product; // Store the selected product
            $this->query = $product->product_name;
            $this->search_results = [$product];

            // Dispatch event with both the product data and its row index
            $this->dispatch('productSelected', [
                'index'   => $this->index,
                'product' => $product,
            ]);
            $this->isFocused = false;
            $this->query_count = 0;
        }
    }

    // New method to clear the selection
    public function clearSelection(): void
    {
        $this->selectedProduct = null;
        $this->query = '';
        $this->dispatch('productSelected', [
            'index'   => $this->index,
            'product' => null,
        ]);
    }

    public function loadMore(): void
    {
        $this->how_many += 10; // Load more results
        $this->searchProducts();
    }

    public function resetQuery(): void
    {
        $this->search_results = [];
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.auto-complete.product-loader');
    }
}
