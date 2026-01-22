<?php

namespace App\Livewire\Sale;

use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;

class DispatchSaleTable extends Component
{
    public $sale;
    public $aggregatedProducts;
    public $locations;
    public $selectedLocations = []; // Array to store each product's selected location
    public $stockAtLocations = [];  // Array to store stock at location for each product
    public $dispatchedQuantities = []; // Array to store updated dispatched quantity for each product
    public $serialNumberRequiredFlags = [];
    public $serialNumberData = []; // [compositeKey][serialNumber] = ['location_id' => X, 'location_name' => Y, 'tax_id' => Z]
    public $locationsUsed = []; // [compositeKey] => [locationId1, locationId2, ...]

    protected $listeners = [
        'addSerialNumber' => 'addSerialNumber',
        'removeSerialNumber' => 'removeSerialNumber',
    ];

    public function mount($sale, $locations, $aggregatedProducts)
    {
        $this->sale = $sale;
        $this->locations = $locations;
        $this->aggregatedProducts = $aggregatedProducts;

        foreach ($aggregatedProducts as $key => $product) {
            $this->dispatchedQuantities[$key] = 0;
            $this->serialNumberRequiredFlags[$key] = $product['serial_number_required'] ?? false;
            $this->selectedLocations[$key] = 0;
            $this->serialNumberData[$key] = [];
            $this->locationsUsed[$key] = [];
        }
    }

    // Handle quantity update event for a product.
    public function quantityUpdated($value, $compositeKey): void
    {
        Log::info('Quantity updated for product', ['compositeKey' => $compositeKey, 'value' => $value]);

        // Calculate the maximum allowed quantity for this product.
        $maxAllowed = $this->aggregatedProducts[$compositeKey]['total_quantity']
            - $this->aggregatedProducts[$compositeKey]['dispatched_quantity'];

        if ($value > $maxAllowed) {
            // If entered value exceeds allowed, adjust it and alert the user.
            $this->dispatchedQuantities[$compositeKey] = $maxAllowed;
            session()->flash('message', "Jumlah yang dimasukkan melebihi batas maksimum, disesuaikan ke $maxAllowed.");
        } else {
            $this->dispatchedQuantities[$compositeKey] = $value;
        }

        // Explode composite key to get productId and taxId.
        list($productId, $taxId) = explode('-', $compositeKey);

        // Retrieve the product from the Product entity.
        $product = Product::find($productId);
        $this->serialNumberRequiredFlags[$compositeKey] = $product ? $product->serial_number_required : false;
    }

    // When a location is updated for a product, update its stock value.
    public function locationChanged($value, $compositeKey)
    {
        Log::info('Updated selected location for product', ['compositeKey' => $compositeKey, 'value' => $value]);
        $this->stockAtLocations[$compositeKey] = $this->getStockForProduct($compositeKey, $value);
    }

    // Retrieve stock for a given product and location.
    // The composite key is in the format "productId-taxId"
    protected function getStockForProduct($compositeKey, $locationId)
    {
        // Extract productId and taxId from composite key.
        list($productId, $taxId) = explode('-', $compositeKey);

        $stockRecord = ProductStock::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->first();

        if (!$stockRecord) {
            return 0;
        }

        // If tax_id > 0, use tax-specific quantities; else use non-tax quantities.
        // The tax-specific stock columns already exclude broken units after breakage adjustments.
        if ((int) $taxId > 0) {
            return max(0, $stockRecord->quantity_tax);
        } else {
            return max(0, $stockRecord->quantity_non_tax);
        }
    }



    // Add a serial number and its location to the tracking arrays.
    public function addSerialNumber($compositeKey, $serialNumber, $locationId, $locationName, $taxId): void
    {
        if (!isset($this->serialNumberData[$compositeKey])) {
            $this->serialNumberData[$compositeKey] = [];
        }

        $this->serialNumberData[$compositeKey][$serialNumber] = [
            'location_id' => $locationId,
            'location_name' => $locationName,
            'tax_id' => $taxId,
        ];

        // Track unique locations used
        $this->updateLocationsUsed($compositeKey);

        // Update quantity
        $this->dispatchedQuantities[$compositeKey] = count($this->serialNumberData[$compositeKey]);
    }

    // Remove a serial number from the tracking arrays.
    public function removeSerialNumber($compositeKey, $serialNumber): void
    {
        if (isset($this->serialNumberData[$compositeKey][$serialNumber])) {
            unset($this->serialNumberData[$compositeKey][$serialNumber]);
            
            // Update unique locations used
            $this->updateLocationsUsed($compositeKey);

            // Update quantity
            $this->dispatchedQuantities[$compositeKey] = count($this->serialNumberData[$compositeKey]);
        }
    }

    // Update the list of unique locations used for a product.
    protected function updateLocationsUsed($compositeKey): void
    {
        $this->locationsUsed[$compositeKey] = array_unique(
            array_column($this->serialNumberData[$compositeKey], 'location_id')
        );
    }

    public function render()
    {
        return view('livewire.sale.dispatch-sale-table');
    }
}
