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
    public ?int $settingId = null;
    public ?int $selectedSettingId = null;

    protected $listeners = [
        'productCreated' => 'handleProductCreated',
        'document-business-context-changed' => 'handleBusinessContextChanged',
    ];

    public function mount(?int $selectedSettingId = null): void
    {
        $this->selectedSettingId = $selectedSettingId ?? (int) session('setting_id');
        $this->settingId = $this->selectedSettingId;
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
        // Return active products marked is_sold, regardless of stock_managed
        $this->search_results = Product::where('is_sold', true)
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

    public function handleBusinessContextChanged(?int $settingId): void
    {
        if ($settingId === null) {
            $this->selectedSettingId = (int) session('setting_id');
        } else {
            $this->selectedSettingId = $settingId;
        }
        $this->settingId = $this->selectedSettingId;
    }
}
