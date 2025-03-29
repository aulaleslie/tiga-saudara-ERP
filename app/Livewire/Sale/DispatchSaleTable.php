<?php

namespace App\Livewire\Sale;

use Illuminate\Support\Facades\Log;
use Livewire\Component;

class DispatchSaleTable extends Component
{
    public $sale;
    public $aggregatedProducts;
    public $locations;
    public $selectedLocations = []; // Array to store each product's selected location
    public $stockAtLocations = [];  // Array to store stock at location for each product

    public function mount($sale, $locations, $aggregatedProducts)
    {
        $this->sale = $sale;
        $this->locations = $locations;
        $this->aggregatedProducts = $aggregatedProducts;
    }

    // When a location is updated for a product, update its stock value.
    public function updatedSelectedLocations($value, $key)
    {
        Log::info('Updated selected location for product', ['product_id' => $key, 'value' => $value]);
        $this->stockAtLocations[$key] = $this->getStockForProduct($key, $value);
    }

    // Replace this logic with your actual method of retrieving stock for a given product and location.
    protected function getStockForProduct($productId, $locationId)
    {
        // Example placeholder: Return a dummy stock value.
        return 10;
    }

    public function render()
    {
        return view('livewire.sale.dispatch-sale-table');
    }
}
