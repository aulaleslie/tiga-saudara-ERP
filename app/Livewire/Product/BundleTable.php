<?php

namespace App\Livewire\Product;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class BundleTable extends Component
{
    public $items = [];
    public $suggestions = [];
    public $productId; // passed from the blade view

    protected $listeners = [
        'productSelected' => 'updateProductRow',
    ];

    /**
     * When the component mounts, initialize one empty item.
     */
    public function mount($productId): void
    {
        $this->productId = $productId;
        $this->items = [
            ['product_id' => null, 'product_name' => '', 'price' => 0, 'quantity' => 1, 'search' => '']
        ];
    }

    /**
     * Add a new bundle item row.
     */
    public function addItem(): void
    {
        $this->items[] = ['product_id' => null, 'product_name' => '', 'price' => 0, 'quantity' => 1, 'search' => ''];
    }

    /**
     * Remove a bundle item row by index.
     */
    public function removeItem($index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function updateProductRow($data): void {
        // Extract the index and product from the event payload.
        $index = $data['index'] ?? null;
        $product = $data['product'] ?? null;

        // Ensure both index and product are available before updating.
        if (!is_null($index) && $product) {
            $this->items[$index]['product_id']   = $product['id'];
            $this->items[$index]['product_name'] = $product['product_name'];
        } else {
            // Log an error if expected data is missing.
            Log::error('updateProductRow missing index or product', compact('data'));
            $this->items[$index]['product_id']   = null;
            $this->items[$index]['product_name'] = null;
        }
    }

    public function updatePrice($index): void
    {
        $price = $this->items[$index]['price'];

        // If the entered price is not numeric, reset to 0; otherwise, cast to float.
        if (!is_numeric($price)) {
            $this->items[$index]['price'] = 0;
        } else {
            $this->items[$index]['price'] = (float)$price;
        }
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.product.bundle-table');
    }
}
