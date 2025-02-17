<?php

namespace App\Livewire\Adjustment;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;

class BreakageProductTable extends Component
{
    protected $listeners = ['productSelected', 'serialNumberSelected', 'locationSelected'];

    public $products;
    public $hasAdjustments;
    public $locationId;

    public function mount($adjustedProducts = null, $locationId = null, $serial_numbers = null, $is_taxables = null): void
    {
        $this->products = [];
        $this->locationId = $locationId;

        if ($adjustedProducts) {
            $this->hasAdjustments = true;
            $this->products = array_map(function ($adjustedProduct) {
                // Fetch product stock by product ID & location
                $productStock = ProductStock::where('product_id', $adjustedProduct['product']['id'])
                    ->where('location_id', $this->locationId)
                    ->first();

                $productEntity = $productsWithUnits[$adjustedProduct['product']['id']] ?? null;

                return [
                    'id' => $adjustedProduct['product']['id'],
                    'product_name' => $adjustedProduct['product']['product_name'],
                    'product_code' => $adjustedProduct['product']['product_code'],
                    'serial_number_required' => $adjustedProduct['product']['serial_number_required'],
                    'serial_numbers' => $this->getSerialNumbers($adjustedProduct['serial_number_ids']),
                    'unit' => $productEntity?->baseUnit?->unit_name ?? '',
                    'quantity_tax' => $productStock->quantity_tax ?? 0,
                    'quantity_non_tax' => $productStock->quantity_non_tax ?? 0,
                    'broken_quantity_tax' => $productStock->broken_quantity_tax ?? 0,
                    'broken_quantity_non_tax' => $productStock->broken_quantity_non_tax ?? 0,
                    'is_taxable' => $adjustedProduct['is_taxable'] ?? 0,
                ];
            }, $adjustedProducts);
        } else {
            $this->hasAdjustments = false;
        }
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.adjustment.breakage-product-table');
    }

    public function productSelected($product): void
    {
        Log::info('product', $product);

        // Prevent duplicate selection
        if (collect($this->products)->contains('id', $product['id'])) {
            session()->flash('message', 'Produk sudah dipilih.');
            return;
        }

        // Fetch product stock by product ID & location
        $productStock = ProductStock::where('product_id', $product['id'])
            ->where('location_id', $this->locationId)
            ->first();

        // Ensure productStock exists, else default values
        $product['quantity'] = $productStock->quantity;
        $product['quantity_tax'] = $productStock->quantity_tax ?? 0;
        $product['quantity_non_tax'] = $productStock->quantity_non_tax ?? 0;
        $product['broken_quantity_tax'] = $productStock->broken_quantity_tax ?? 0;
        $product['broken_quantity_non_tax'] = $productStock->broken_quantity_non_tax ?? 0;

        // Retrieve product unit
        $productEntity = Product::with('baseUnit')->find($product['id']);
        $product['unit'] = $productEntity->baseUnit->unit_name ?? '';

        // Initialize empty serial numbers
        $product['serial_numbers'] = [];

        // Add to the product list
        $this->products[] = $product;
    }

    public function removeProduct($key): void
    {
        unset($this->products[$key]);
    }

    public function locationSelected($locationId): void
    {
        Log::info("locationSelected: " . $locationId);
        $this->locationId = $locationId;
    }

    protected function updateProductQuantitiesByLocation(): void
    {
        foreach ($this->products as &$product) {
            $product['product_quantity'] = $this->getProductQuantity($product['id']);
        }
    }

    public function serialNumberSelected($index, $serialNumber)
    {
        if (isset($this->products[$index]) && $this->products[$index]['serial_number_required']) {
            if (in_array($serialNumber, $this->products[$index]['serial_numbers'])) {
                session()->flash('message', "Serial number '{$serialNumber['serial_number']}' sudah ada.");
                return;
            }

            $this->products[$index]['serial_numbers'][] = $serialNumber;
            Log::info("Serial number added for row {$index}", ['serial_number' => $serialNumber]);
        }
    }

    public function removeSerialNumber($index, $serialIndex)
    {
        if (isset($this->products[$index]['serial_numbers'][$serialIndex])) {
            unset($this->products[$index]['serial_numbers'][$serialIndex]);
            $this->products[$index]['serial_numbers'] = array_values($this->products[$index]['serial_numbers']);
            Log::info("Removed serial number at index {$serialIndex} for row {$index}");
        }
    }

    protected function getProductQuantity($productId)
    {
        if ($this->locationId) {
            return Transaction::where('product_id', $productId)
                ->where('location_id', $this->locationId)
                ->groupBy('product_id', 'location_id')
                ->sum('quantity');
        }

        return 0;
    }

    protected function getSerialNumbers($serialNumberIds)
    {
        if (empty($serialNumberIds)) {
            return [];
        }

        return ProductSerialNumber::whereIn('id', $serialNumberIds)
            ->get(['id', 'serial_number'])
            ->toArray();
    }
}
