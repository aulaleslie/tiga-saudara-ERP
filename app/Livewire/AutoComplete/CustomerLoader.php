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

    public function mount($customerId = null)
    {
        if ($customerId) {
            $customer = Customer::find($customerId);
            $this->query = $customer->contact_name;
            $this->search_results = [$customer];
            $this->query_count = 1;
        }
    }

    public function updatedQuery(): void
    {
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
            $this->query_count = Customer::where(function ($query) {
                $query->where('contact_name', 'like', '%' . $this->query . '%');
            })
                ->count();
            $this->search_results = Customer::where(function ($query) {
                $query->where('contact_name', 'like', '%' . $this->query . '%');
            })
                ->limit($this->how_many)
                ->get();
        }
    }

    public function selectCustomer($customerId): void
    {
        $customer = Customer::find($customerId);
        if ($customer) {
            $this->search_results = array($customer);
            $this->query = "$customer->contact_name";

            // Dispatch event to update table row
            $this->dispatch('customerSelected', $customer);
            $this->isFocused = false;
            $this->query_count = 0;
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
