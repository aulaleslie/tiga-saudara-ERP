<?php

namespace App\Livewire\Transfer;

use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Setting\Entities\Setting;

class TransferStockForm extends Component
{
    public $currentSetting;
    public $settings;

    public $selectedBusiness    = null;
    public $originLocation      = null;
    public $destinationLocation = null;

    protected $listeners = [
        'originLocationSelected'      => 'onOriginLocationSelected',
        'destinationLocationSelected' => 'onDestinationLocationSelected',
    ];

    public function mount()
    {
        $this->currentSetting = Setting::find(session('setting_id'));
        $this->settings       = Setting::all();
    }

    public function onOriginLocationSelected($payload)
    {
        Log::info('onOriginLocationSelected', ['payload' => $payload]);
        if ($payload) {
            $this->originLocation      = $payload['id'];
            $this->destinationLocation = null;
        }
    }

    public function onDestinationLocationSelected($payload)
    {
        Log::info('onDestinationLocationSelected', ['payload' => $payload]);
        if ($payload) {
            $this->destinationLocation = $payload['id'];
        }
    }

    public function confirmSelections()
    {
        if (! $this->selectedBusiness || ! $this->originLocation || ! $this->destinationLocation) {
            $this->addError('form', 'Please select both origin and destination.');
            return;
        }


        // Let your child tables know
        $this->emit('locationsConfirmed', [
            'originLocationId'      => $this->originLocation,
            'destinationLocationId' => $this->destinationLocation,
        ]);
    }

    public function render()
    {
        return view('livewire.transfer.transfer-stock-form');
    }
}
