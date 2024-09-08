<?php

namespace App\Livewire\Product;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;

class HargaJualCalculator extends Component
{
    public float $product_cost = 0;
    public float $product_order_tax = 0;
    public float $product_tax_type = 1; // 1 for Exclusive, 2 for Inclusive
    public float $profit_percentage = 0;
    public float $product_price = 0;

    public function calculateHargaJual(): void
    {
        $cost = $this->parseCurrency($this->product_cost);
        $tax = (float)$this->product_order_tax;
        $profit = (float)$this->profit_percentage;

        if ($this->product_tax_type == 2) { // Inclusive
            $cost = $cost / (1 + $tax / 100);
        }

        $price = $cost + ($cost * $profit / 100);

        if ($this->product_tax_type == 1) { // Exclusive
            $price += $price * $tax / 100;
        }

        $this->product_price = number_format($price, 2);
    }

    protected function parseCurrency($value): float
    {
        return (float)str_replace([',', ' '], ['', ''], $value);
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.product.harga-jual-calculator');
    }
}
