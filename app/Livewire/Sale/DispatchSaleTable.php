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
    public $selectedSerialNumbers = [];

    protected $listeners = [
        'serialNumberSelected' => 'handleSerialNumberSelected',
    ];

    public function mount($sale, $locations, $aggregatedProducts)
    {
        $this->sale = $sale;
        $this->locations = $locations;
        $this->aggregatedProducts = $aggregatedProducts;

        foreach ($aggregatedProducts as $key => $product) {
            $this->dispatchedQuantities[$key] = 0;
            $this->serialNumberRequiredFlags[$key] = false;
            $this->selectedLocations[$key] = 0;
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
        if ((int) $taxId > 0) {
            return $stockRecord->quantity_tax - $stockRecord->broken_quantity_tax;
        } else {
            return $stockRecord->quantity_non_tax - $stockRecord->broken_quantity_non_tax;
        }
    }

    public function handleSerialNumberSelected($payload): void
    {
        Log::info('Serial number selected', $payload);
        // Save the selected serial number in a nested array keyed by product composite key and serial index.
        $productKey = $payload['productCompositeKey'];
        $serialIndex = $payload['serialIndex'];
        $this->selectedSerialNumbers[$productKey][$serialIndex] = $payload['serialNumber']['serial_number'];

        // You can add further processing here such as validation if all serial numbers are filled.
    }

    public function render()
    {
        return view('livewire.sale.dispatch-sale-table');
    }
}
