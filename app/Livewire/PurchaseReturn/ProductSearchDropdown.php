<?php

namespace App\Livewire\PurchaseReturn;

use Livewire\Component;
use Livewire\Attributes\Modelable;
use Livewire\Attributes\Reactive;
use Modules\Product\Entities\Product;

class ProductSearchDropdown extends Component
{
    #[Modelable]
    public int|string|null $selected = null;
    public string $placeholder = 'Pilih produk...';
    public string $search = '';
    public bool $open = false;

    public $supplier_id;
    public $index;

    #[Reactive]
    public ?string $error = null;

    public array $options = [];
    public ?string $selectedLabel = null;
    
    public int $how_many = 10;
    public int $query_count = 0;

    public function mount(
        $index = null,
        $supplier_id = null,
        $selected = null,
        $placeholder = 'Pilih produk...',
        ?string $error = null
    ): void {
        $this->index = $index;
        $this->supplier_id = $supplier_id;
        $this->selected = $selected;
        $this->placeholder = $placeholder;
        $this->error = $error;

        if ($this->selected) {
            $this->selectedLabel = $this->resolveLabel($this->selected);
        }
    }

    public function render()
    {
        return view('livewire.purchase-return.product-search-dropdown');
    }

    public function toggleDropdown(): void
    {
        $this->open = !$this->open;
    }

    public function updatedOpen($value): void
    {
        if ($value) {
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

    public function select(int|string $id): void
    {
        $this->selected = $id;
        $product = Product::find($id);
        
        if ($product) {
            $this->selectedLabel = "$product->product_code | $product->product_name";
            
            // Populate additional data needed by parent
            $product->last_purchase_price = (float) ($product->lastPurchasePrice() ?? 0);
            $product->serial_number_required = (bool) $product->serial_number_required;
            
            $this->dispatch('productSelected', $this->index, $product);
        }
        
        $this->open = false;
        $this->search = '';
    }

    private function resolveLabel(int|string|null $id): ?string
    {
        if (!$id) return null;
        
        $product = Product::find($id);
        return $product ? "$product->product_code | $product->product_name" : null;
    }

    private function searchProducts(): void
    {
        if (!$this->supplier_id) {
            $this->options = [];
            return;
        }

        $product_query = Product::whereIn('id', function ($query) {
            $query->select('pd.product_id')
                ->from('purchases as p')
                ->leftJoin('purchase_details as pd', 'p.id', '=', 'pd.purchase_id')
                ->where('p.supplier_id', $this->supplier_id)
                ->whereIn('p.status', ['RECEIVED PARTIALLY', 'RECEIVED']);
        })
        ->where(function ($query) {
            $query->where('product_name', 'like', '%' . $this->search . '%')
                ->orWhere('product_code', 'like', '%' . $this->search . '%');
        });

        $this->query_count = $product_query->count();
        
        $this->options = $product_query->limit($this->how_many)
            ->get()
            ->map(fn($product) => [
                'id' => $product->id,
                'name' => "$product->product_code | $product->product_name"
            ])
            ->all();
    }
}
