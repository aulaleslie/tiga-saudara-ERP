<?php

namespace App\Livewire\SalesReturn;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;
use Modules\Product\Entities\Product;

class ReplacementProductSearch extends Component
{
    public $index;
    public $query = '';
    public $search_results = [];
    public $isFocused = false;
    public $query_count = 0;
    public $how_many = 10;

    public function mount($index): void
    {
        $this->index = $index;
    }

    public function updatedQuery(): void
    {
        if ($this->isFocused) {
            $this->searchProducts();
        } else {
            $this->search_results = [];
        }
    }

    public function searchProducts(): void
    {
        if (! $this->query) {
            $this->search_results = [];
            $this->query_count = 0;
            return;
        }

        $qb = Product::query()
            ->where(function ($query) {
                $query->where('product_name', 'like', '%' . $this->query . '%')
                    ->orWhere('product_code', 'like', '%' . $this->query . '%');
            })
            ->orderBy('product_name');

        $this->query_count = $qb->count();
        $this->search_results = $qb->limit($this->how_many)->get();
    }

    public function selectProduct($productId): void
    {
        $product = Product::find($productId);
        if (! $product) {
            return;
        }

        $payload = [
            'index' => $this->index,
            'product' => [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'unit_price' => (float) ($product->product_price ?? 0),
            ],
        ];

        $this->query = $product->product_code . ' | ' . $product->product_name;
        $this->search_results = [$product];
        $this->dispatch('replacementProductSelected', $payload);
        $this->isFocused = false;
        $this->query_count = 0;
    }

    public function loadMore(): void
    {
        $this->how_many += 10;
        $this->searchProducts();
    }

    public function resetQuery(): void
    {
        $this->search_results = [];
        $this->query_count = 0;
    }

    public function resetQueryAfterDelay(): void
    {
        usleep(150 * 1000);
        $this->isFocused = false;
    }

    public function render(): Factory|Application|View
    {
        return view('livewire.sales-return.replacement-product-search');
    }
}
