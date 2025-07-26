<?php

namespace App\Livewire\Transfer;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\Transaction;

class TransferProductTable extends Component
{
    protected $listeners = [
        'productSelected',
        'serialNumberSelected',
    ];

    public $products;
    public $hasAdjustments;
    public $locationId;

    public function mount($existingProducts = null, $locationId = null, $originLocationId = null): void
    {
        Log::info('TransferProductTable', [
            'originLocationId' => $originLocationId,
            'locationId' => $locationId,
        ]);
        $this->products = [];
        $this->locationId = $locationId;

        if ($existingProducts) {
            $this->hasAdjustments = true;
            $this->products = array_map(function ($adjustedProduct) {
                return $adjustedProduct['product'];
            }, $existingProducts);

            if ($this->locationId) {
                $this->updateProductQuantitiesByLocation();
            }
        } else {
            $this->hasAdjustments = false;
        }
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.transfer.transfer-product-table');
    }

    public function productSelected($product)
    {
        Log::info('product', $product);

        if (collect($this->products)->contains('id', $product['id'] ?? $product['product']['id'] ?? null)) {
            session()->flash('message', 'Already exists in the product list!');
            return;
        }

        // Add serial tracking fields
        $product['serial_number_required'] = $product['serial_number_required'] ?? false;
        $product['serial_numbers'] = [];
        if ($product['serial_number_required']) {
            $product['quantity'] = 0;
        }

        $this->products[] = $product;
    }

    public function removeProduct($key)
    {
        unset($this->products[$key]);
    }

    protected function updateProductQuantitiesByLocation(): void
    {
        foreach ($this->products as &$product) {
            $product['product_quantity'] = $this->getProductQuantity($product['id']);
        }
    }

    protected function getProductQuantity($productId)
    {
        if ($this->locationId) {
            // Query to get the latest quantity for the product in the specified location
            return Transaction::where('product_id', $productId)
                ->where('location_id', $this->locationId)
                ->groupBy('product_id', 'location_id')
                ->sum('quantity');
        }

        return 0;
    }

    public function serialNumberSelected($index, $serialNumber): void
    {
        if (isset($this->products[$index]) && $this->products[$index]['serial_number_required']) {
            // Prevent duplicates
            if (collect($this->products[$index]['serial_numbers'])->contains('id', $serialNumber['id'])) {
                session()->flash('message', "Serial number '{$serialNumber['serial_number']}' already selected.");
                return;
            }
            $this->products[$index]['serial_numbers'][] = $serialNumber;
            $this->products[$index]['quantity'] = count($this->products[$index]['serial_numbers']);
        }
    }

    public function removeSerialNumber($index, $serialIndex): void
    {
        if (isset($this->products[$index]['serial_numbers'][$serialIndex])) {
            unset($this->products[$index]['serial_numbers'][$serialIndex]);
            $this->products[$index]['serial_numbers'] = array_values($this->products[$index]['serial_numbers']);
            $this->products[$index]['quantity'] = count($this->products[$index]['serial_numbers']);
        }
    }
}
