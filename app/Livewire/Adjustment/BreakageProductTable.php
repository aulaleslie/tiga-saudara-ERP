<?php

namespace App\Livewire\Adjustment;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class BreakageProductTable extends Component
{
    protected $listeners = ['productSelected'];

    public $products;
    public $hasAdjustments;

    public function mount($adjustedProducts = null): void
    {
        $this->products = [];

        if ($adjustedProducts) {
            $this->hasAdjustments = true;
            $this->products = array_map(function ($adjustedProduct) {
                return $adjustedProduct['product'];
            }, $adjustedProducts);
        } else {
            $this->hasAdjustments = false;
        }
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.adjustment.breakage-product-table');
    }

    public function productSelected($product) {
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
}
