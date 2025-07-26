<?php

namespace App\Livewire\PurchaseReturn;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Purchase\Entities\PurchaseDetail;

class PurchaseOrderSerialNumberLoader extends Component
{
    public $query = '';  // User input for search
    public $product_id = '';
    public $purchase_id = '';
    public $search_results = []; // Product search results
    public $index; // Row index in table
    public $isFocused = false;
    public $query_count = 0;
    public $how_many = 10; // Limit for search results
    public $location_id;
    public $is_broken = false;
    public $is_transfer = false;

    protected $listeners = [
        'purchaseOrderSelected' => 'updatePurchaseOrderRow',
    ];

    public function mount($index, $product_id, $purchase_id = null, $location_id = null, $is_broken = null, $is_transfer = null): void
    {
        $this->index = $index;
        $this->product_id = $product_id;
        $this->purchase_id = $purchase_id;
        $this->location_id = $location_id;
        $this->is_broken = $is_broken;

        Log::info("serial number row", [
            'index' => $this->index,
            'product_id' => $this->product_id,
            'purchase_id' => $this->purchase_id,
            'location_id' => $this->location_id,
            'is_broken' => $this->is_broken,
            'is_transfer' => $this->is_transfer,
        ]);
    }

    public function updatedQuery(): void
    {
        if ($this->isFocused) {
            $this->searchSerialNumbers();
        } else {
            $this->search_results = [];
        }
    }

    public function updatePurchaseOrderRow($index, $purchase): void
    {
        $this->purchase_id = $purchase['id'];
    }

    public function searchSerialNumbers(): void
    {
        if ($this->query && $this->product_id) {
            $serial_number_query = ProductSerialNumber::query();

            // If is_transfer is true, location_id must exist,
            // Only show serial numbers with dispatch_detail_id IS NULL and correct location
            if ($this->is_transfer) {
                if (!$this->location_id) {
                    // If location is not set, no result
                    $this->search_results = [];
                    $this->query_count = 0;
                    return;
                }
                $serial_number_query->where('location_id', $this->location_id)
                    ->whereNull('dispatch_detail_id')
                    ->where('product_id', $this->product_id)
                    ->where('serial_number', 'like', '%' . $this->query . '%');
                // Optionally, add more filters if needed for transfer
            } else {
                // Standard logic for purchase return, not transfer
                if ($this->location_id) {
                    $serial_number_query->where('location_id', $this->location_id);
                }

                if ($this->is_broken) {
                    $serial_number_query->where('is_broken', true);
                }

                // Filter for specific purchase_id (exclude broken products)
                if ($this->purchase_id) {
                    $serial_number_query->whereIn('received_note_detail_id', function ($query) {
                        $query->select('rnd.id')
                            ->from('received_note_details as rnd')
                            ->join('purchase_details as pd', 'rnd.po_detail_id', '=', 'pd.id')
                            ->where('pd.purchase_id', $this->purchase_id);
                    });
                }

                $serial_number_query
                    ->where('product_id', $this->product_id)
                    ->where('serial_number', 'like', '%' . $this->query . '%')
                    ->whereNull('dispatch_detail_id');
            }

            $this->query_count = $serial_number_query->count();
            $this->search_results = $serial_number_query->limit($this->how_many)->get();
        }
    }

    public function resetQueryAfterDelay(): void
    {
        sleep(1); // Small delay before closing
        $this->isFocused = false;
    }

    public function selectSerialNumber($serial_number_id): void
    {
        $serial_number = ProductSerialNumber::find($serial_number_id);
        if ($serial_number) {
            $this->search_results = array($serial_number);
            // Set input to show full serial number name and code
            $this->query = "$serial_number->serial_number";

            // Dispatch event to update table row
            $this->dispatch('serialNumberSelected', $this->index, $serial_number);
            $this->isFocused = false;
            $this->query_count = 0;
            $this->query = '';
            $this->search_results = [];
        }
    }

    public function loadMore(): void
    {
        $this->how_many += 10; // Load more results
        $this->searchSerialNumbers();
    }

    public function resetQuery(): void
    {
        $this->search_results = [];
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.purchase-return.purchase-order-serial-number-loader');
    }
}
