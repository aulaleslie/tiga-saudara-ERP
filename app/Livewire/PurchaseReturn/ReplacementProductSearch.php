<?php

namespace App\Livewire\PurchaseReturn;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;
use Modules\Product\Entities\Product;

class ReplacementProductSearch extends Component
{
    public $index;
    public string $placeholder = 'Pilih produk pengganti...';
    public string $search = '';
    public bool $open = false;
    public int $how_many = 10;
    public int $query_count = 0;

    public $selected = null;
    public ?string $selectedLabel = null;

    public array $options = []; // Renamed from search_results for consistency with view pattern

    public function mount($index): void
    {
        $this->index = $index;
    }

    public function updatedSearch(): void
    {
        $this->how_many = 10;
        $this->searchProducts();
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

    public function searchProducts(): void
    {
        $qb = Product::query()
            ->where(function ($query) {
                $query->where('product_name', 'like', '%' . $this->search . '%')
                    ->orWhere('product_code', 'like', '%' . $this->search . '%');
            })
            ->orderBy('product_name');

        $this->query_count = $qb->count();
        $this->options = $qb->limit($this->how_many)
            ->get()
            ->map(fn($product) => [
                'id' => $product->id,
                'name' => "$product->product_code | $product->product_name"
            ])
            ->all();
    }

    public function select($productId): void
    {
        $product = Product::find($productId);
        if (! $product) {
            return;
        }

        $this->selected = $productId;
        $this->selectedLabel = "$product->product_code | $product->product_name";

        $payload = [
            'index' => $this->index,
            'quantity' => 1,
            'unit_value' => (float) ($product->lastPurchasePrice(session('setting_id')) ?? 0),
            'sub_total' => (float) ($product->lastPurchasePrice(session('setting_id')) ?? 0),
            'serial_number' => '',
            'product' => [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'last_purchase_price' => (float) ($product->lastPurchasePrice(session('setting_id')) ?? 0),
                'serial_number_required' => (bool) $product->serial_number_required,
            ],
        ];

        $this->dispatch('replacementProductSelected', $payload);
        $this->open = false;
        $this->search = '';
        $this->options = [];
    }

    public function loadMore(): void
    {
        $this->how_many += 10;
        $this->searchProducts();
    }

    public function resetSearch(): void
    {
        $this->options = [];
        $this->query_count = 0;
    }

    public function render(): Factory|Application|View
    {
        return view('livewire.purchase-return.replacement-product-search');
    }
}
