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

        // Fetch products with an optional serial number match using a LEFT JOIN.
        $this->search_results = Product::query()
            ->leftJoin('product_serial_numbers as psn', 'products.id', '=', 'psn.product_id')
            ->where('products.stock_managed', true)
            ->where('products.setting_id', $this->settingId)
            ->where(function ($q) {
                $q->where('products.product_name', 'like', '%' . $this->query . '%')
                    ->orWhere('products.product_code', 'like', '%' . $this->query . '%')
                    ->orWhere('products.barcode', 'like', '%' . $this->query . '%')
                    ->orWhere('psn.serial_number', 'like', '%' . $this->query . '%');
            })
            ->select('products.*', 'psn.id as serial_number_id', 'psn.serial_number')
            ->take($this->how_many)
            ->get();

        // If there's exactly one result and its barcode or serial number exactly equals the query,
        // immediately select it.
        if ($this->search_results->count() === 1) {
            $first = $this->search_results->first();
            if ($first->barcode === $this->query || $first->serial_number === $this->query) {
                $this->selectProduct($first);
            }
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
        $this->resetQuery();
    }

    public function doNothing(): void
    {
        // This method intentionally left blank.
    }
}
