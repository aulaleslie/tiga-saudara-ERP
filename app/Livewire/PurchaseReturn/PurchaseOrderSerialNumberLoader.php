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
    public $product_id;
    
    #[Reactive]
    public $purchase_id;
    
    #[Reactive]
    public $index; // Row index in table
    
    #[Reactive]
    public $is_broken = false;
    
    #[Reactive]
    public $is_transfer = false;
    
    #[Reactive]
    public array $existingSerials = [];
    
    public $error_message = '';

    protected $listeners = [];

    public function mount(): void
    {
        // Reactive props are handled automatically
    }



    public function addSerial(): void
    {
        $this->error_message = '';
        $serial_number_input = trim($this->query);

        if (empty($serial_number_input)) {
            return;
        }

        // Validate serial number
        $serial = ProductSerialNumber::where('product_id', $this->product_id)
            ->where('serial_number', $serial_number_input)
            ->with(['location.setting'])
            ->first();

        if (!$serial) {
            $this->error_message = 'Serial number tidak ditemukan.';
            $this->dispatch('error-occurred', ['index' => $this->index]);
            return;
        }

        // Check if already in existing serials (current row)
        $alreadyExists = collect($this->existingSerials)->contains('serial_number', $serial->serial_number);
        if ($alreadyExists) {
            $this->error_message = 'Nomor seri ini sudah ditambahkan di baris ini.';
            $this->dispatch('error-occurred', ['index' => $this->index]);
            return;
        }

        if ($serial->dispatch_detail_id) {
            $this->error_message = 'Serial number sudah terjual/keluar.';
            $this->dispatch('error-occurred', ['index' => $this->index]);
            return;
        }

        if (strtoupper($serial->status) !== ProductSerialNumber::STATUS_ACTIVE) {
            $this->error_message = "Serial number tidak aktif ({$serial->status}).";
            $this->dispatch('error-occurred', ['index' => $this->index]);
            return;
        }

        if ($serial->is_in_return_process) {
            $this->error_message = 'Serial number sedang dalam proses retur.';
            $this->dispatch('error-occurred', ['index' => $this->index]);
            return;
        }

        // Validation: All serials in the same row must belong to the same location
        if (!empty($this->existingSerials)) {
            $firstSerial = $this->existingSerials[0];
            
            if ($serial->location_id != ($firstSerial['location_id'] ?? null)) {
                $this->error_message = 'Nomor seri berasal dari lokasi yang berbeda, tambahkan baris baru dan scan ulang nomor seri.';
                $this->dispatch('error-occurred', ['index' => $this->index]);
                return;
            }
        }
        
        // Dispatch event to update table row
        $this->dispatch('serialNumberSelected', $this->index, [
            'id' => $serial->id,
            'serial_number' => $serial->serial_number,
            'location_id' => $serial->location_id,
            'location_name' => $serial->location->name ?? null,
            'location_label' => ($serial->location->setting->company_name ?? 'N/A') . ' - ' . ($serial->location->name ?? 'N/A'),
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
