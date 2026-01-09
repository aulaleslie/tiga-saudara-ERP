<?php

namespace App\Livewire\PurchaseReturn;

use Livewire\Component;
use Livewire\Attributes\Modelable;
use Livewire\Attributes\Reactive;
use Modules\Purchase\Entities\Purchase;
use Illuminate\Support\Facades\Log;

class PurchaseOrderSearchDropdown extends Component
{
    #[Modelable]
    public int|string|null $selected = null;
    public string $placeholder = 'Pilih purchase order...';
    public string $search = '';
    public bool $open = false;

    public $supplier_id;
    public $product_id;
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
        $product_id = null,
        $selected = null,
        $placeholder = 'Pilih purchase order...',
        ?string $error = null
    ): void {
        $this->index = $index;
        $this->supplier_id = $supplier_id;
        $this->product_id = $product_id;
        $this->selected = $selected;
        $this->placeholder = $placeholder;
        $this->error = $error;

        if ($this->selected) {
            $this->selectedLabel = $this->resolveLabel($this->selected);
        }
    }

    public function render()
    {
        return view('livewire.purchase-return.purchase-order-search-dropdown');
    }

    public function toggleDropdown(): void
    {
        $this->open = !$this->open;
        if ($this->open) {
            $this->search = '';
            $this->searchPurchaseOrders();
        }
    }

    public function closeDropdown(): void
    {
        $this->open = false;
    }

    public function updatedSearch(): void
    {
        $this->how_many = 10;
        $this->searchPurchaseOrders();
    }

    public function loadMore(): void
    {
        $this->how_many += 10;
        $this->searchPurchaseOrders();
    }

    public function select(int|string $id): void
    {
        $this->selected = $id;
        $purchase = Purchase::find($id);
        
        if ($purchase) {
            $this->selectedLabel = $purchase->reference;
            $this->dispatch('purchaseOrderSelected', $this->index, $purchase);
        }
        
        $this->open = false;
        $this->search = '';
    }

    private function resolveLabel(int|string|null $id): ?string
    {
        if (!$id) return null;
        
        $purchase = Purchase::find($id);
        return $purchase ? $purchase->reference : null;
    }

    private function searchPurchaseOrders(): void
    {
        if (!$this->supplier_id || !$this->product_id) {
            $this->options = [];
            return;
        }

        $queryBuilder = function ($query) {
            $query->select('p.id')
                ->from('purchases as p')
                ->leftJoin('purchase_details as pd', 'p.id', '=', 'pd.purchase_id')
                ->where('p.supplier_id', $this->supplier_id)
                ->where('pd.product_id', $this->product_id)
                ->whereIn('p.status', ['RECEIVED PARTIALLY', 'RECEIVED'])
                ->where('p.reference', 'like', '%' . $this->search . '%');
        };

        $this->query_count = Purchase::whereIn('id', $queryBuilder)->count();
        
        $this->options = Purchase::whereIn('id', $queryBuilder)
            ->limit($this->how_many)
            ->get()
            ->map(fn($purchase) => [
                'id' => $purchase->id,
                'name' => $purchase->reference
            ])
            ->all();
    }
}
