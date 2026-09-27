<?php

namespace App\Livewire\AutoComplete;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;
use Modules\Setting\Entities\Location;

class LocationLoader extends Component
{
    public $query = '';  // User input for search
    public $search_results = []; // search results
    public $index; // Row index in table
    public $isFocused = false;
    public $query_count = 0;
    public $how_many = 10; // Limit for search results

    public function mount($locationId = null): void {
        // If a location ID is provided, fetch the location
        if ($locationId) {
            $location = Location::find($locationId);
            if ($location) {
                $this->query = $location->name; // Set the query to the location name

                $this->search_results = [$location];
            }
        }
    }

    public function updatedQuery(): void
    {
        if ($this->isFocused) {
            $this->searchLocations();
        } else {
            $this->search_results = [];
        }
    }

    public function resetQueryAfterDelay(): void
    {
        sleep(1); // Small delay before closing
        $this->isFocused = false;
    }

    public function searchLocations(): void
    {
        if ($this->query) {
            $this->query_count = Location::where(function ($query) {
                $query->where('name', 'like', '%' . $this->query . '%');
            })
                ->where('setting_id', session('setting_id'))
                ->count();
            $this->search_results = Location::where(function ($query) {
                $query->where('name', 'like', '%' . $this->query . '%');
            })
                ->where('setting_id', session('setting_id'))
                ->limit($this->how_many)
                ->get();
        }
    }

    public function selectLocation($locationId): void
    {
        $location = Location::find($locationId);
        if ($location) {
            $this->search_results = array($location);
            $this->query = "$location->name";

            // Dispatch event to update table row
            $this->dispatch('locationSelected', $location->id);
            $this->isFocused = false;
            $this->query_count = 0;
        }
    }

    public function loadMore(): void
    {
        $this->how_many += 10; // Load more results
        $this->searchLocations();
    }

    public function resetQuery(): void
    {
        $this->search_results = [];
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.auto-complete.location-loader');
    }
}
