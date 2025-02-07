<?php

namespace App\Livewire\PurchaseReturn;

use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;

class PurchaseOrderSearchPurchaseReturn extends Component
{
    public $query = '';  // User input for search
    public $supplier_id = ''; // Selected supplier
    public $product_id = '';
    public $search_results = []; // Product search results
    public $index; // Row index in table
    public $isFocused = false;
    public $query_count = 0;
    public $how_many = 10; // Limit for search results

    public function mount($index, $supplier_id, $product_id): void
    {
        $this->supplier_id = $supplier_id;
        $this->product_id = $product_id;
        $this->index = $index;
        Log::info("supplier purchase row", [
            'supplier_id' => $this->supplier_id,
            'product_id' => $this->product_id,
            'index' => $this->index,
        ]);
    }

    public function updatedQuery()
    {
        if ($this->isFocused) {
            $this->searchPurchaseOrders();
        } else {
            $this->search_results = [];
        }
    }

    public function searchPurchaseOrders()
    {
        if ($this->query && $this->supplier_id && $this->product_id) {
            $this->query_count = Purchase::whereIn('id', function ($query) {
                $query->select('p.id')
                    ->from('purchases as p')
                    ->leftJoin('purchase_details as pd', 'p.id', '=', 'pd.purchase_id')
                    ->where('p.supplier_id', $this->supplier_id)
                    ->where('pd.product_id', $this->product_id)
                    ->where('p.reference', 'like', '%' . $this->query . '%');
            })
                ->count();

            $this->search_results = Purchase::whereIn('id', function ($query) {
                $query->select('p.id')
                    ->from('purchases as p')
                    ->leftJoin('purchase_details as pd', 'p.id', '=', 'pd.purchase_id')
                    ->where('p.supplier_id', $this->supplier_id)
                    ->where('pd.product_id', $this->product_id)
                    ->where('p.reference', 'like', '%' . $this->query . '%');
            })
                ->limit($this->how_many)
                ->get();
        }
    }

    public function selectPurchaseOrder($purchaseOrderId): void
    {
        $purchaseOrder = Purchase::find($purchaseOrderId);
        if ($purchaseOrder) {
            $this->search_results = array($purchaseOrder);
            // Set input to show full product name and code
            $this->query = "$purchaseOrder->reference";

            // Dispatch event to update table row
            $this->dispatch('purchaseOrderSelected', $this->index, $purchaseOrder);
            $this->isFocused = false;
            $this->query_count = 0;
        }
    }

    public function loadMore()
    {
        $this->how_many += 10; // Load more results
        $this->searchPurchaseOrders();
    }

    public function resetQuery()
    {
        $this->search_results = [];
    }

    public function render()
    {
        return view('livewire.purchase-return.purchase-order-search-purchase-return');
    }
}
