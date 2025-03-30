<?php

namespace App\Livewire\AutoComplete;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;
use Modules\Product\Entities\ProductSerialNumber;

class SerialNumberLoader extends Component
{
    public $query = '';  // User input for search
    public $search_results = []; // search results
    public $index; // Row index in table
    public $isFocused = false;
    public $query_count = 0;
    public $how_many = 10; // Limit for search results
    public $location_id = 0;
    public $product_id = 0;
    public $is_taxed = false;

    public function mount($location_id = 0, $product_id = 0, $is_taxed = false): void
    {
        $this->location_id = $location_id;
        $this->product_id = $product_id;
        $this->is_taxed = $is_taxed;
    }

    public function updatedQuery(): void
    {
        if ($this->isFocused) {
            $this->searchSerialNumbers();
        } else {
            $this->search_results = [];
        }
    }

    public function resetQueryAfterDelay(): void
    {
        sleep(1); // Small delay before closing
        $this->isFocused = false;
    }

    public function searchSerialNumbers(): void
    {
        if ($this->query) {
            $this->query_count = ProductSerialNumber::where(function ($query) {
                $query->where('supplier_name', 'like', '%' . $this->query . '%');
            })
                ->where('setting_id', session('setting_id'))
                ->count();
            $this->search_results = ProductSerialNumber::where(function ($query) {
                $query->where('supplier_name', 'like', '%' . $this->query . '%');
            })
                ->where('setting_id', session('setting_id'))
                ->limit($this->how_many)
                ->get();
        }
    }

    public function selectSerialNumber($serialNumberId): void
    {
        $serialNumber = ProductSerialNumber::find($serialNumberId);
        if ($serialNumber) {
            $this->search_results = array($serialNumber);
            $this->query = "$serialNumber->serial_number";

            // Dispatch event to update table row
            $this->dispatch('serialNumberSelected', $supplier);
            $this->isFocused = false;
            $this->query_count = 0;
        }
    }

    public function loadMore(): void
    {
        $this->how_many += 10; // Load more results
        $this->searchSuppliers();
    }

    public function resetQuery(): void
    {
        $this->search_results = [];
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.auto-complete.supplier-loader');
    }
}
