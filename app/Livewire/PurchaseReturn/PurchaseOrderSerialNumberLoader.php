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
            ->with(['location.setting', 'receivedNoteDetail.receivedNote.purchase'])
            ->first();

        if (!$serial) {
            $this->error_message = 'Serial number tidak ditemukan.';
            return;
        }

        if ($serial->dispatch_detail_id) {
            $this->error_message = 'Serial number sudah terjual/keluar.';
            return;
        }

        // Resolve purchase order from serial
        $purchase = $serial->receivedNoteDetail->receivedNote->purchase ?? null;
        $purchaseIdFromSerial = $purchase->id ?? null;
        $purchaseReference = $purchase->reference ?? null;
        $purchaseDate = $purchase && $purchase->date ? \Carbon\Carbon::parse($purchase->date)->format('Y-m-d') : null;

        // Validation: All serials in the same row must belong to the same location and same purchase order
        if (!empty($this->existingSerials)) {
            $firstSerial = $this->existingSerials[0];
            
            if ($serial->location_id != ($firstSerial['location_id'] ?? null)) {
                $this->error_message = 'Nomor seri berasal dari lokasi yang berbeda, tambahkan baris baru dan scan ulang nomor seri.';
                return;
            }

            // Also check purchase order if both have it
            $firstPurchaseOrderId = $firstSerial['purchase_order_id'] ?? null;
            if ($purchaseIdFromSerial && $firstPurchaseOrderId) {
                if ($purchaseIdFromSerial != $firstPurchaseOrderId) {
                    $this->error_message = 'Nomor seri berasal dari pembelian yang berbeda, tambahkan baris baru dan scan ulang nomor seri.';
                    return;
                }
            }
        }
        
        // Dispatch event to update table row
        $this->dispatch('serialNumberSelected', $this->index, [
            'id' => $serial->id,
            'serial_number' => $serial->serial_number,
            'location_id' => $serial->location_id,
            'location_name' => $serial->location->name ?? null,
            'location_label' => ($serial->location->setting->company_name ?? 'N/A') . ' - ' . ($serial->location->name ?? 'N/A'),
            'purchase_order_id' => $purchaseIdFromSerial,
            'purchase_order_reference' => $purchaseReference,
            'purchase_order_date' => $purchaseDate,
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
