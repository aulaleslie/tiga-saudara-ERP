<?php

namespace App\Livewire\Transfer;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;

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
            ->map(function ($product) {
                $quantity = $this->getProductQuantityAtLocation($product->id, $this->locationId);
                $product->product_quantity = $quantity;

                return $product;
            })
            ->filter(function ($product) {
                return $product->product_quantity > 0;
            })
            ->take($this->how_many);
    }

    public function getProductQuantityAtLocation($productId, $locationId): int
    {
        if (empty($productId) || empty($locationId)) {
            return 0;
        }

        $settingId = session('setting_id');

        $stock = ProductStock::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->when($settingId, function ($query) use ($settingId) {
                $query->whereHas('location', function ($q) use ($settingId) {
                    $q->where('setting_id', $settingId);
                });
            })
            ->first();

        if (!$stock) {
            return 0;
        }

        $availableQuantity = (int) ($stock->quantity_tax ?? 0) + (int) ($stock->quantity_non_tax ?? 0);

        if ($availableQuantity === 0 && !is_null($stock->quantity)) {
            $brokenQuantity = (int) ($stock->broken_quantity_tax ?? 0) + (int) ($stock->broken_quantity_non_tax ?? 0);
            $availableQuantity = max(0, (int) $stock->quantity - $brokenQuantity);
        }

        return max(0, $availableQuantity);
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
