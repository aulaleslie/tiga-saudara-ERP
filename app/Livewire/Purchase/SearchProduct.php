<?php

namespace App\Livewire\Purchase;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Product\Entities\Product;

class SearchProduct extends Component
{
    public string $query = '';
    public $search_results;
    public int $how_many = 5;
    public $supplier_id;

    protected $listeners = [
        'productCreated' => 'handleProductCreated',
    ];

    public function mount(): void
    {
        $this->search_results = Collection::empty();
    }

    public function render(): Factory|View|Application
    {
        return view('livewire.purchase.search-product');
    }

    public function updatedQuery(): void
    {
        $this->search_results = $this->getProducts();
    }

    public function updatedSupplierId(): void
    {
        $this->search_results = Collection::empty();
        $this->query = '';
    }

    #[On('supplierSelected')]
    public function handleSupplierSelected($supplier_id = null): void
    {
        $this->supplier_id = $supplier_id;
        $this->search_results = Collection::empty();
        $this->query = '';
    }

    private function getProducts()
    {
        $query = Product::with('baseUnit')
            ->where('stock_managed', true);

        if ($this->supplier_id) {
            // Filter products that have been purchased from this supplier
            $query->whereHas('purchaseDetails.purchase', function ($q) {
                $q->where('supplier_id', $this->supplier_id);
            });
        }

        $query->globalSearch($this->query);

        return $query->take($this->how_many)->get();
    }

    public function loadMore(): void
    {
        $this->how_many += 5;
        $this->updatedQuery();
    }

    public function handleProductCreated($data): void
    {
        // Refresh search results to include the new product
        $this->updatedQuery();
        // Optionally set the query to the new product name to show it
        $this->query = $data['product_name'];
    }

    public function resetQuery(): void
    {
        $this->query = '';
        $this->how_many = 5;
        $this->search_results = Collection::empty();
    }

    public function selectProduct($product): void
    {
        Log::info('product', [
            'product' => $product,
        ]);
        // Target the purchase product cart directly so the item gets added
        $this->dispatch('productSelected', $product)->to(ProductCart::class);
    }
}
