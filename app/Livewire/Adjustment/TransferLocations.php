<?php

namespace App\Livewire\Adjustment;

use Livewire\Component;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

class TransferLocations extends Component
{
    public $currentSettingId;
    public $destinationSettingId = null;
    public $locationOriginId;
    public $locationDestinationId;
    public $locations = [];
    public $destinationLocations = [];
    public $formDisabled = false;

    public function mount($currentSettingId)
    {
        $this->currentSettingId = $currentSettingId;
        $this->locations = Location::where('setting_id', $currentSettingId)->get();
        $this->destinationLocations = $this->locations; // Default to current setting locations
    }

    public function updatedDestinationSettingId($destinationSettingId)
    {
        $this->destinationSettingId = $destinationSettingId;
        $this->destinationLocations = $destinationSettingId
            ? Location::where('setting_id', $destinationSettingId)->get()
            : $this->locations; // Reset to current setting locations if no destination is selected
    }

    public function submitOriginDestination()
    {
        $this->formDisabled = true;
    }

    public function resetForm()
    {
        $this->reset(['destinationSettingId', 'locationOriginId', 'locationDestinationId', 'formDisabled']);
        $this->destinationLocations = $this->locations;
    }

    public function render()
    {
        return view('livewire.adjustment.transfer-locations', [
            'locations' => $this->locations,
            'destinationLocations' => $this->destinationLocations,
            'settings' => Setting::where('id', '!=', $this->currentSettingId)->get(),
        ]);
    }
}

