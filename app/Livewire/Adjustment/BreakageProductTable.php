<?php

namespace App\Livewire\Adjustment;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\Transaction;

class BreakageProductTable extends Component
{
    protected $listeners = ['productSelected'];

    public $products;
    public $hasAdjustments;
    public $locationId;

    public function mount($adjustedProducts = null, $locationId = null): void
    {
        $this->products = [];
        $this->locationId = $locationId;

        if ($adjustedProducts) {
            $this->hasAdjustments = true;
            $this->products = array_map(function ($adjustedProduct) {
                return $adjustedProduct['product'];
            }, $adjustedProducts);

            if ($this->locationId) {
                $this->updateProductQuantitiesByLocation();
            }
        } else {
            $this->hasAdjustments = false;
        }
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.adjustment.breakage-product-table');
    }

    public function productSelected($product)
    {
        Log::info('product', $product);

        switch ($this->hasAdjustments) {
            case false:
            case true:
                if (in_array($product, $this->products)) {
                    return session()->flash('message', 'Already exists in the product list!');
                }
                break;
            default:
                return session()->flash('message', 'Something went wrong!');
        }

        array_push($this->products, $product);
    }

    public function removeProduct($key): void
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
}
