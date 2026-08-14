<?php

namespace Modules\Product\Livewire;

use Livewire\Component;
use Modules\Product\Entities\Product;

class ProductSearchDropdown extends Component
{
    public $index;
    public $selected = null;
    public string $placeholder = 'Pilih produk...';
    public string $search = '';
    public bool $open = false;
    public int $how_many = 10;
    public int $query_count = 0;
    // keep as non-typed to allow Eloquent Collection assignment
    public $search_results = [];
    public ?string $selectedLabel = null;
    public $excludeProductId = null;

    public function mount($index = null, $selected = null, $placeholder = 'Pilih produk...', $excludeProductId = null)
    {
        $this->index = $index;
        $this->selected = $selected;
        $this->placeholder = $placeholder;
        $this->excludeProductId = $excludeProductId;

        if ($this->selected) {
            $this->selectedLabel = $this->resolveLabel($this->selected);
        }
    }

    public function render()
    {
        return view('livewire.modules.product.product-search-dropdown');
    }

    public function toggleDropdown(): void
    {
        $this->open = !$this->open;
        if ($this->open) {
            $this->search = '';
            $this->searchProducts();
        }
    }

    public function closeDropdown(): void
    {
        $this->open = false;
    }

    public function updatedSearch(): void
    {
        $this->how_many = 10;
        $this->searchProducts();
    }

    public function loadMore(): void
    {
        $this->how_many += 10;
        $this->searchProducts();
    }

    private function searchProducts(): void
    {
        if (!$this->search) {
            $this->search_results = [];
            $this->query_count = 0;
            return;
        }

        $query = Product::query()->globalSearch($this->search);

        if ($this->excludeProductId) {
            $query->where('id', '!=', $this->excludeProductId);
        }

        $this->query_count = (clone $query)->count();
        $this->search_results = $query
            ->orderBy('product_name')
            ->orderBy('id')
            ->take($this->how_many)
            ->get();
    }

    public function select($id): void
    {
        $query = Product::query()->active()->where('id', $id);

        if ($this->excludeProductId) {
            $query->where('id', '!=', $this->excludeProductId);
        }

        $product = $query->first();
        if (!$product) {
            return;
        }

        $this->selected = $product->id;
        $this->selectedLabel = $product->product_name;

        // Dispatch productSelected with index (rowKey) and product
        $this->dispatch('productSelected', $this->index, $product);

        $this->open = false;
        $this->search = '';
        $this->search_results = [];
        $this->query_count = 0;
    }

    public function clearSelection(): void
    {
        $this->selected = null;
        $this->selectedLabel = null;
        $this->dispatch('productSelected', $this->index, null);
    }

    private function resolveLabel($id): ?string
    {
        $p = Product::find($id);
        return $p ? $p->product_name : null;
    }
}
