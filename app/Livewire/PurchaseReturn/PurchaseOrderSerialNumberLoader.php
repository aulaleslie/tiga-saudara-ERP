<?php

namespace App\Livewire\PurchaseReturn;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\ProductSerialNumber;

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

    public function mount($index, $product_id, $purchase_id): void
    {
        $this->product_id = $product_id;
        $this->purchase_id = $purchase_id;
        $this->index = $index;

        Log::info("serial number row", [
            'index' => $this->index,
            'product_id' => $this->product_id,
            'purchase_id' => $this->purchase_id,
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

    public function searchSerialNumbers(): void
    {
        if ($this->query && $this->product_id && $this->purchase_id) {
            $serial_number_query = ProductSerialNumber::whereIn('product_id', function ($query) {
                $query->select('pd.product_id')
                    ->from('purchase_details as pd')
                    ->leftJoin('received_note_details as rnd', 'pd.id', '=', 'rnd.po_detail_id')
                    ->leftJoin('product_serial_numbers as psn', 'rnd.id', '=', 'psn.received_note_detail_id')
                    ->where('pd.purchase_id', $this->purchase_id);
            })
                ->where('product_id', $this->product_id)
                ->where('serial_number', 'like', '%' . $this->query . '%');

            $this->query_count = $serial_number_query->count();

            $this->search_results = $serial_number_query->limit($this->how_many)->get();
        }
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
