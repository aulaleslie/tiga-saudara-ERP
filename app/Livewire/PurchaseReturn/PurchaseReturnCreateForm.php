<?php

namespace App\Livewire\PurchaseReturn;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\People\Entities\Supplier;

class PurchaseReturnCreateForm extends Component
{
    public $supplier_id = '';
    public $date;

    protected $listeners = ['supplierSelected'];

    public function mount(): void
    {
        $this->date = now()->format('Y-m-d');
    }

    // Automatically triggers when supplier_id changes
    public function supplierUpdated($supplier): void
    {
        $this->supplier_id = $supplier['id'];
        Log::info("Supplier updated to: ", ["supplier_id" => $supplier]);
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.purchase-return.purchase-return-create-form');
    }
}
