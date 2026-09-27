<?php

namespace App\Livewire\Sale;

use Livewire\Component;

class DispatchSaleHeader extends Component
{
    public $sale;
    public $dispatch_date;

    public function mount($sale)
    {
        $this->sale = $sale;
        $this->dispatch_date = now()->format('Y-m-d');
    }

    public function render()
    {
        return view('livewire.sale.dispatch-sale-header');
    }
}
