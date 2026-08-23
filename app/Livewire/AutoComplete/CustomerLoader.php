<?php

namespace App\Livewire\AutoComplete;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;
use Modules\People\Entities\Customer;

class CustomerLoader extends Component
{
    public $query = '';  // User input for search
    public $search_results = []; // search results
    public $index; // Row index in table
    public $isFocused = false;
    public $query_count = 0;
    public $how_many = 10; // Limit for search results
    public $selectedCustomerId = null; // Track selected customer to prevent query override

    protected $listeners = ['customerSelected' => 'handleCustomerSelected'];

    public function mount($customerId = null)
    {
        if ($customerId) {
            $customer = Customer::find($customerId);
            $this->query = $customer->canonical_name;
            $this->search_results = [$customer];
            $this->query_count = 1;
            $this->selectedCustomerId = $customerId;
        }
    }

    public function updatedQuery(): void
    {
        // If a customer was just selected, don't search again
        if ($this->selectedCustomerId !== null) {
            $this->selectedCustomerId = null;
            return;
        }
        
        if ($this->isFocused) {
            $this->searchCustomers();
        } else {
            $this->search_results = [];
        }
    }

    public function resetQueryAfterDelay(): void
    {
        usleep(150 * 1000); // 150ms delay - reduced from 1s for faster response
        $this->isFocused = false;
    }

    public function searchCustomers(): void
    {
        if ($this->query) {
            $this->query_count = Customer::active()->where(function ($query) {
                $query->where('contact_name', 'like', '%' . $this->query . '%')
                      ->orWhere('customer_name', 'like', '%' . $this->query . '%');
            })
                ->count();
            $this->search_results = Customer::active()->where(function ($query) {
                $query->where('contact_name', 'like', '%' . $this->query . '%')
                      ->orWhere('customer_name', 'like', '%' . $this->query . '%');
            })
                ->limit($this->how_many)
                ->get();
        }
    }

    public function selectCustomer($customerId): void
    {
        $customer = Customer::find($customerId);
        if ($customer) {
            // Set selectedCustomerId BEFORE changing query to prevent updatedQuery from overwriting
            $this->selectedCustomerId = $customer->id;
            $this->query = $customer->canonical_name;
            $this->search_results = []; // Clear results to close dropdown
            $this->isFocused = false;
            $this->query_count = 0;

            // Dispatch event with customer data as array for proper Livewire 3 serialization
            $this->dispatch('customerSelected', [
                'id' => $customer->id,
                'customer_name' => $customer->customer_name,
                'contact_name' => $customer->contact_name,
                'tier' => $customer->tier ?? null,
            ]);
        }
    }

    public function handleCustomerSelected($customerData): void
    {
        // Handle customer selection from external sources (like CreateModal)
        if (is_array($customerData) && isset($customerData['id'])) {
            $customer = Customer::find($customerData['id']);
            if ($customer) {
                // Mark as selected before mutating query to prevent updatedQuery from clearing it
                $this->selectedCustomerId = $customer->id;
                $this->query = $customer->canonical_name;
                $this->search_results = [$customer];
                $this->query_count = 1;
                $this->isFocused = false;
            }
        }
    }

    public function loadMore(): void
    {
        $this->how_many += 10; // Load more results
        $this->searchCustomers();
    }

    public function resetQuery(): void
    {
        $this->search_results = [];
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.auto-complete.customer-loader');
    }
}
