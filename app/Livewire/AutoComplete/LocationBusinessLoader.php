<?php

namespace App\Livewire\AutoComplete;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Setting\Entities\Location;

class LocationBusinessLoader extends Component
{
    public $query = '';
    public $search_results = [];
    public $isFocused = false;
    public $query_count = 0;
    public $how_many = 10;
    public $locationSelected = false;

    public $exclude = null;      // Exclude location ID (e.g., don't show origin as destination)
    public $label = 'Location';  // Label to show
    public $eventName = 'locationSelected'; // Event to emit on select

    public $locationId = null;   // Selected location id
    public $settingId = null;

    public $name = 'location_id';

    public function mount($settingId = null, $locationId = null, $exclude = null, $label = 'Location', $eventName = 'locationSelected', $name = 'location_id'): void
    {
        $this->settingId = $settingId;
        $this->locationId = $locationId;
        $this->exclude = $exclude;
        $this->label = $label;
        $this->eventName = $eventName;
        $this->name = $name;

        Log::info('loaded: ', [
            'settingId' => $settingId,
            'locationId' => $locationId,
            'exclude' => $exclude,
            'label' => $label,
            'eventName' => $eventName,
            'name' => $name,
        ]);

        if ($locationId) {
            $location = Location::find($locationId);
            if ($location) {
                $this->query = $location->name;
                $this->search_results = [$location];
                $this->query_count = 1;
            }
        }
    }

    public function updatedQuery(): void
    {
        $this->locationSelected = false;
        if (trim($this->query) === '') {
            $this->search_results = [];
            $this->query_count = 0;
            $this->dispatch($this->eventName, null);
            return;
        }
        if ($this->isFocused) {
            $this->searchLocations();
        }
    }

    public function resetQueryAfterDelay(): void
    {
        usleep(150 * 1000); // Small delay, 150ms
        $this->isFocused = false;
        if (!$this->locationSelected) {
            $this->dispatch($this->eventName, null);
        }
    }

    public function searchLocations(): void
    {
        // Base query: eager-load the setting relation
        $qb = Location::with('setting')
            // If a specific settingId was passed, restrict to it
            ->when($this->settingId, fn($q) => $q->where('setting_id', $this->settingId))
            // Match either the location name or the related setting's company_name
            ->where(function ($q) {
                $q->where('locations.name', 'like', '%' . $this->query . '%')
                    ->orWhereHas('setting', fn($q2) =>
                    $q2->where('company_name', 'like', '%' . $this->query . '%')
                    );
            })
            // Exclude a single location if requested
            ->when($this->exclude, fn($q) => $q->where('locations.id', '!=', $this->exclude));

        // Get total count
        $this->query_count = $qb->count();

        // Fetch the first N results, with the setting relation loaded
        $this->search_results = $qb
            ->orderBy('locations.name')
            ->limit($this->how_many)
            ->get();
    }

    public function selectLocation($locationId): void
    {
        $location = Location::with('setting')->find($locationId);
        if ($location) {
            $this->query = $location->name . ' - ' . $location->setting->company_name;
            $this->locationId = $location->id;
            $this->search_results = [$location];
            $this->locationSelected = true;
            $this->dispatch($this->eventName, [
                'id' => $location->id,
                'name' => $location->name,
                'code' => $location->code ?? null,
            ]);
            $this->isFocused = false;
            $this->query_count = 0;
        }
    }

    public function loadMore(): void
    {
        $this->how_many += 10;
        $this->searchLocations();
    }

    public function resetQuery(): void
    {
        $this->search_results = [];
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.auto-complete.location-business-loader');
    }
}
