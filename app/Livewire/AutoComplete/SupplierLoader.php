<?php

namespace App\Livewire\AutoComplete;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;

class SupplierLoader extends Component
{
    public $query = '';  // User input for search
    public $search_results = []; // search results
    public $index; // Row index in table
    public $isFocused = false;
    public $query_count = 0;
    public $how_many = 10; // Limit for search results
    public $supplierSelected = false;

    public function mount($supplierId = null)
    {
        if ($supplierId) {
            $supplier = Supplier::find($supplierId);
            $this->query = $supplier->supplier_name;
            $this->search_results = [$supplier];
            $this->query_count = 1;
        }
    }

    public function updatedQuery(): void
    {
        Log::info('updated query', [
            'query' => $this->query,
            'isFocused' => $this->isFocused,
            'search_results' => $this->search_results,
        ]);
        $this->supplierSelected = false;

        if (trim($this->query) === '') {
            $this->search_results = [];
            $this->query_count = 0;
            Log::info('3. supplier loader trigger event', [
                'supplierSelected' => $this->supplierSelected,
            ]);
            $this->dispatch('supplierSelected', null);
            return;
        }

        if ($this->isFocused) {
            $this->searchSuppliers();
        }
    }

    public function resetQueryAfterDelay(): void
    {
        sleep(1); // Small delay before closing
        $this->isFocused = false;

        if (!$this->supplierSelected) {
            Log::info('2. supplier loader trigger event', [
                'supplierSelected' => $this->supplierSelected,
            ]);
            $this->dispatch('supplierSelected', null);
        }
    }

    public function searchSuppliers(): void
    {
        if ($this->query) {
            $this->query_count = Supplier::where(function ($query) {
                $query->where('supplier_name', 'like', '%' . $this->query . '%');
            })
                ->where('setting_id', session('setting_id'))
                ->count();
            $this->search_results = Supplier::where(function ($query) {
                $query->where('supplier_name', 'like', '%' . $this->query . '%');
            })
                ->where('setting_id', session('setting_id'))
                ->limit($this->how_many)
                ->get();
        }
    }

    public function selectSupplier($supplierId): void
    {
        $supplier = Supplier::find($supplierId);
        if ($supplier) {
            $this->query = $supplier->supplier_name;
            $this->search_results = [$supplier];
            $this->supplierSelected = true; // ✅ mark as selected

            $this->dispatch('supplierSelected', $supplier);
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
