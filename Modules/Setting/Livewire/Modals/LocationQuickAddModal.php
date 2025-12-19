<?php

namespace Modules\Setting\Livewire\Modals;

use Livewire\Component;
use Modules\Setting\Entities\Location;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

class LocationQuickAddModal extends Component
{
    public $showModal = false;
    public $name = '';

    protected $listeners = [
        'openLocationModal' => 'openModal'
    ];

    protected function rules()
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('locations', 'name')->where('setting_id', session('setting_id') ?? 1)
            ],
        ];
    }

    protected function validationAttributes()
    {
        return [
            'name' => 'nama lokasi',
        ];
    }

    public function openModal()
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function save()
    {
        $this->validate();

        $location = Location::create([
            'name' => $this->name,
            'setting_id' => session('setting_id') ?? 1,
            // Location model boot method handles assignment to setting sale locations
        ]);

        $this->dispatch('locationCreated', [
            'id' => $location->id,
            'name' => $location->name
        ]);

        session()->flash('success', 'Lokasi berhasil ditambahkan.');

        $this->closeModal();
    }

    private function resetForm()
    {
        $this->name = '';
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.modals.location-quick-add-modal');
    }
}
