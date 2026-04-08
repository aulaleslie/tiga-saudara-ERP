<?php

namespace App\Livewire\Sale;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Livewire\Component;
use Modules\Product\Entities\Product;

class SearchProduct extends Component
{
    public string $query = '';
    public $search_results;
    public int $how_many = 5;
    public $settingId;

    protected $listeners = [
        'productCreated' => 'handleProductCreated',
    ];

    public function mount(): void
    {
        $this->settingId = session('setting_id');
        $this->search_results = Collection::empty();
    }

    public function render(): Factory|View|Application
    {
        return view('livewire.sale.search-product');
    }

    public function updatedQuery(): void
    {
        // Ensure settingId is available
        if (!$this->settingId) {
            $this->search_results = Collection::empty();
            return;
        }

        // Fetch products based on the query and setting_id
        $this->search_results = Product::where('stock_managed', true)
            ->globalSearch($this->query)
            ->take($this->how_many)
            ->get();
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
        // Clear the current query/results so the input empties after selection
        $this->resetQuery();
        $this->dispatch('saleProductSearchCleared');
        $this->dispatch('productSelected', $product);
    }

    public function handleProductCreated(array $data): void
    {
        // Only refresh suggestions if the user currently has a query typed
        if (trim($this->query) === '') {
            return;
        }

        $this->updatedQuery();
    }
}
