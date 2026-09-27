<?php

namespace Modules\Setting\Livewire\Modals;

use Livewire\Component;
use Modules\Setting\Entities\Unit;
use Illuminate\Validation\Rule;

class UnitQuickAddModal extends Component
{
    public $showModal = false;
    public $name = '';
    public $short_name = '';
    public $listenEvent = 'openUnitModal';

    public function getListeners()
    {
        return [
            $this->listenEvent => 'openModal',
        ];
    }

    protected function rules()
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('units', 'name')->where('setting_id', session('setting_id') ?? 1)
            ],
            'short_name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('units', 'short_name')->where('setting_id', session('setting_id') ?? 1)
            ]
        ];
    }

    protected function validationAttributes()
    {
        return [
            'name' => 'nama unit',
            'short_name' => 'nama singkat'
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

        $unit = Unit::create([
            'name' => $this->name,
            'short_name' => $this->short_name,
            'setting_id' => session('setting_id') ?? 1
        ]);

        $this->dispatch('unitCreated', [
            'id' => $unit->id,
            'name' => $unit->name
        ]);

        session()->flash('success', 'Unit berhasil ditambahkan.');

        $this->closeModal();
    }

    private function resetForm()
    {
        $this->name = '';
        $this->short_name = '';
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.modals.unit-quick-add-modal');
    }
}
