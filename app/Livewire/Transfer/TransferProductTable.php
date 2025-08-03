<?php

namespace App\Livewire\Transfer;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Product\Entities\ProductStock;

class TransferProductTable extends Component
{
    protected $listeners = [
        'productSelected',
        'locationsConfirmed' => 'resetOnNewLocations',
    ];

    public $products = [];
    public $originLocationId;
    public $destinationLocationId;

    /**
     * Mount with the two location IDs passed in via wire:key
     */
    public function mount($originLocationId = null, $destinationLocationId = null): void
    {
        $this->originLocationId      = $originLocationId;
        $this->destinationLocationId = $destinationLocationId;
        $this->products              = [];
    }

    /**
     * Clear the table whenever parent confirms new locations
     */
    public function resetOnNewLocations(array $payload): void
    {
        $this->originLocationId      = $payload['originLocationId'];
        $this->destinationLocationId = $payload['destinationLocationId'];
        $this->products              = [];
    }

    /**
     * Add a product (with its stock snapshot) to the table
     */
    public function productSelected(array $product): void
    {
        // avoid duplicates
        if (collect($this->products)->contains('id', $product['id'] ?? null)) {
            session()->flash('message', 'Already exists in the product list!');
            return;
        }

        // load that product's stock record at the origin location
        $stock = ProductStock::where('product_id', $product['id'])
            ->where('location_id', $this->originLocationId)
            ->first();

        // merge in all the relevant stock columns
        $product['stock'] = [
            'total'                    => $stock->quantity                  ?? 0,
            'quantity_tax'             => $stock->quantity_tax              ?? 0,
            'quantity_non_tax'         => $stock->quantity_non_tax          ?? 0,
            'broken_quantity_tax'      => $stock->broken_quantity_tax       ?? 0,
            'broken_quantity_non_tax'  => $stock->broken_quantity_non_tax   ?? 0,
        ];

        // initialize transfer inputs
        $product['quantity_tax']             = 0;
        $product['quantity_non_tax']         = 0;
        $product['broken_quantity_tax']      = 0;
        $product['broken_quantity_non_tax']  = 0;

        $this->products[] = $product;
    }

    public function removeProduct(int $key): void
    {
        unset($this->products[$key]);
        $this->products = array_values($this->products);
    }

    public function render(): View
    {
        return view('livewire.transfer.transfer-product-table');
    }
}
