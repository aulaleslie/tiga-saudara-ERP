<?php

namespace App\Livewire\PurchaseReturn;

use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\Product;

class ProductSearchPurchaseReturn extends Component
{
    public $query = '';  // User input for search
    public $supplier_id = ''; // Selected supplier
    public $search_results = []; // Product search results
    public $index; // Row index in table
    public $isFocused = false;
    public $query_count = 0;
    public $how_many = 10; // Limit for search results

    public function mount($index, $supplier_id): void
    {
        $this->supplier_id = $supplier_id;
        $this->index = $index;
        Log::info("supplier product row", [
            'supplier_id' => $this->supplier_id,
            'index' => $this->index
        ]);
    }

    public function updatedQuery()
    {
        if ($this->isFocused) {
            $this->searchProducts();
        } else {
            $this->search_results = [];
        }
    }

    public function searchProducts()
    {
        if ($this->query && $this->supplier_id) {
            $this->query_count = Product::whereIn('id', function ($query) {
                $query->select('pd.product_id')
                    ->from('purchases as p')
                    ->leftJoin('purchase_details as pd', 'p.id', '=', 'pd.purchase_id')
                    ->where('p.supplier_id', $this->supplier_id)
                    ->where('p.payment_status', 'paid');
            })
                ->where(function ($query) {
                    $query->where('product_name', 'like', '%' . $this->query . '%')
                        ->orWhere('product_code', 'like', '%' . $this->query . '%');
                })
                ->count();

            $this->search_results = Product::whereIn('id', function ($query) {
                $query->select('pd.product_id')
                    ->from('purchases as p')
                    ->leftJoin('purchase_details as pd', 'p.id', '=', 'pd.purchase_id')
                    ->where('p.supplier_id', $this->supplier_id)
                    ->where('p.payment_status', 'paid');
            })
                ->where(function ($query) {
                    $query->where('product_name', 'like', '%' . $this->query . '%')
                        ->orWhere('product_code', 'like', '%' . $this->query . '%');
                })
                ->limit($this->how_many)
                ->get();
        }
    }

    public function selectProduct($productId)
    {
        $product = Product::find($productId);
        if ($product) {
            $this->search_results = array($product);
            // Set input to show full product name and code
            $this->query = "$product->product_code | $product->product_name";

            // Dispatch event to update table row
            $this->dispatch('productSelected', $this->index, $product);
            $this->isFocused = false;
            $this->query_count = 0;
        }
    }

    public function loadMore()
    {
        $this->how_many += 10; // Load more results
        $this->searchProducts();
    }

    public function resetQuery()
    {
        $this->search_results = [];
    }

    public function render()
    {
        return view('livewire.purchase-return.product-search-purchase-return');
    }
}
