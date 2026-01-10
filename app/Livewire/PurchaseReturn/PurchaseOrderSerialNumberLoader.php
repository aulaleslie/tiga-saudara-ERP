<?php

namespace App\Livewire\PurchaseReturn;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Purchase\Entities\PurchaseDetail;

use Livewire\Attributes\Reactive;

class PurchaseOrderSerialNumberLoader extends Component
{
    public $query = ''; // Input for serial number
    
    #[Reactive]
    public $product_id = '';
    
    #[Reactive]
    public $purchase_id = '';
    
    #[Reactive]
    public $index; // Row index in table
    
    #[Reactive]
    public $location_id;
    
    #[Reactive]
    public $is_broken = false;
    
    #[Reactive]
    public $is_transfer = false;
    public $error_message = '';

    protected $listeners = [];

    public function mount($index, $product_id, $purchase_id = null, $location_id = null, $is_broken = null, $is_transfer = null): void
    {
        $this->index = $index;
        $this->product_id = $product_id;
        $this->purchase_id = $purchase_id;
        $this->location_id = $location_id;
        $this->is_broken = $is_broken;
    }



    public function addSerial(): void
    {
        $this->error_message = '';
        // Allow leading/trailing space removal but handle input carefully
        $serial_number_input = trim($this->query);

        if (empty($serial_number_input)) {
            return;
        }

        if (!$this->location_id) {
            $this->error_message = 'Pilih lokasi terlebih dahulu.';
            return;
        }

        // Validate serial number
        $search = ProductSerialNumber::where('product_id', $this->product_id)
            ->where('serial_number', $serial_number_input);
            
        $serial = $search->first();

        if (!$serial) {
            $this->error_message = 'Serial number tidak ditemukan.';
            return;
        }

        if ((int) $serial->location_id !== (int) $this->location_id) {
            $this->error_message = 'Serial number berada di lokasi yang berbeda.';
            return;
        }

        if ($serial->dispatch_detail_id) {
            $this->error_message = 'Serial number sudah terjual/keluar.';
            return;
        }
        
        // Dispatch event to update table row
        $this->dispatch('serialNumberSelected', $this->index, [
            'id' => $serial->id,
            'serial_number' => $serial->serial_number,
        ]);
        
        // Clear input
        $this->reset('query');
        $this->dispatch('clear-input', ['index' => $this->index]);
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.purchase-return.purchase-order-serial-number-loader');
    }
}

