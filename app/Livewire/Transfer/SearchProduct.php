<?php

namespace App\Livewire\Transfer;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Transaction;

class SearchProduct extends Component
{
    public string $query = '';
    public $search_results;
    public int $how_many = 5;

    public $locationId;  // Add locationId as a public property

    public function mount($locationId = null): void
    {
        $this->search_results = Collection::empty();
        Log::info("locationSelectedMounted: " . $locationId);
        $this->locationId = $locationId;

        $this->search_results = Collection::empty();
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.transfer.search-product');
    }

    public function updatedQuery(): void
    {
        $this->search_results = Product::where('stock_managed', true)
            ->where(function ($query) {
                $query->where('product_name', 'like', '%' . $this->query . '%')
                    ->orWhere('product_code', 'like', '%' . $this->query . '%');
            })
            ->get()
            ->filter(function ($product) {
                // Filter products where quantity at location is greater than 0
                $quantity = $this->getProductQuantityAtLocation($product->id, $this->locationId);
                return $quantity > 0;  // Only return products with non-zero quantity
            })
            ->take($this->how_many)
            ->map(function ($product) {
                $quantity = $this->getProductQuantityAtLocation($product->id, $this->locationId);
                $product->product_quantity = $quantity;  // Adding the calculated quantity to the product object
                return $product;
            });
    }

    public function getProductQuantityAtLocation($productId, $locationId): int
    {
        return Transaction::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->groupBy('product_id', 'location_id')
            ->sum('quantity');
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
