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
    public $listeners = [
        // External components (e.g. quick-add modal) can push new suppliers in via this event.
        'supplierCreated' => 'handleSupplierCreated',
    ];

    public $query = '';  // User input for search
    public $search_results = []; // search results
    public $index; // Row index in table
    public $isFocused = false;
    public $query_count = 0;
    public $how_many = 10; // Limit for search results
    public $supplierSelected = false;
    public string $idempotencyToken = '';

    public function mount($supplierId = null, $idempotencyToken = '')
    {
        $this->idempotencyToken = $idempotencyToken;
        
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
            'supplierSelected' => $this->supplierSelected,
        ]);
        
        // If a supplier is already selected, don't process query changes
        // unless the user is actively typing (isFocused = true)
        if ($this->supplierSelected && !$this->isFocused) {
            Log::info('SupplierLoader: Skipping query update - supplier already selected');
            return;
        }
        
        // If user is typing after selection, clear local selection flag but let the new pick
        // drive the parent update to avoid double re-renders mid-search.
        if ($this->supplierSelected && $this->isFocused) {
            Log::info('SupplierLoader: User typing after selection - clearing local selection flag');
            $this->supplierSelected = false;
        }

        if (trim($this->query) === '') {
            $this->search_results = [];
            $this->query_count = 0;
            $this->dispatch('supplierSelected', null);
            return;
        }

        if ($this->isFocused) {
            $this->searchSuppliers();
        }
    }

    public function resetQueryAfterDelay(): void
    {
        usleep(150 * 1000); // 150ms delay - reduced from 1s for faster response
        $this->isFocused = false;
        // Don't dispatch null on blur - only dispatch when user explicitly clears selection
    }

    public function handleBlur(): void
    {
        // Delay hiding dropdown to allow click events on dropdown items to fire first
        $this->js("setTimeout(() => { \$wire.set('isFocused', false) }, 200)");
    }

    public function handleFocus(): void
    {
        $this->isFocused = true;
        
        // If there's a query but no search results, trigger a search
        if (trim($this->query) !== '' && count($this->search_results) === 0) {
            $this->searchSuppliers();
        }
    }

    public function searchSuppliers(): void
    {
        if ($this->query) {
            $this->query_count = Supplier::active()->where(function ($query) {
                $query->where('supplier_name', 'like', '%' . $this->query . '%')
                    ->orWhere('contact_name', 'like', '%' . $this->query . '%');
            })
                ->count();
            $this->search_results = Supplier::active()->where(function ($query) {
                $query->where('supplier_name', 'like', '%' . $this->query . '%')
                    ->orWhere('contact_name', 'like', '%' . $this->query . '%');
            })
                ->limit($this->how_many)
                ->get();
        }
    }

    public function selectSupplier($supplierId): void
    {
        $supplier = Supplier::find($supplierId);
        if ($supplier) {
            $this->query = $supplier->supplier_name;
            $this->supplierSelected = true; // ✅ mark as selected
            $this->isFocused = false;
            $this->search_results = []; // Clear results to hide dropdown
            $this->query_count = 0;

            $supplierPayload = $supplier->only([
                'id',
                'supplier_name',
                'contact_name',
                'payment_term_id',
                'supplier_email',
                'supplier_phone',
            ]);

            Log::info('SupplierLoader: Dispatching supplierSelected event', [
                'supplier_id' => $supplierPayload['id'],
                'payment_term_id' => $supplierPayload['payment_term_id'] ?? 'NULL'
            ]);
            
            // Dispatch after this component's update completes to avoid race condition
            $this->js("setTimeout(() => { Livewire.dispatch('supplierSelected', " . json_encode([$supplierPayload]) . ") }, 50)");
        }
    }

    public function handleSupplierSelected($supplier): void
    {
        if ($supplier) {
            $this->query = $supplier['supplier_name'];
            $this->search_results = []; // Clear results - supplier already selected
            $this->supplierSelected = true;
            $this->isFocused = false;
            $this->query_count = 0;
        } else {
            $this->query = '';
            $this->search_results = [];
            $this->supplierSelected = false;
            $this->query_count = 0;
        }
    }

    public function handleSupplierCreated($supplier): void
    {
        // Handle the newly created supplier same as selected supplier
        $this->handleSupplierSelected($supplier);
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
