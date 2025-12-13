<?php

namespace Modules\Setting\Livewire\Modals;

use Livewire\Component;
use Modules\Setting\Entities\Tax;
use Illuminate\Validation\Rule;

class TaxQuickAddModal extends Component
{
    public $showModal = false;
    public $name = '';
    public $value = 0;

    protected $listeners = [
        'openTaxModal' => 'openModal'
    ];

    protected function rules()
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('taxes', 'name')
            ],
            'value' => 'required|numeric|min:0|max:100'
        ];
    }

    protected function validationAttributes()
    {
        return [
            'name' => 'nama',
            'value' => 'nilai (%)'
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

        $tax = Tax::create([
            'name' => $this->name,
            'value' => $this->value
        ]);

        $this->dispatch('taxCreated', [
            'id' => $tax->id,
            'name' => $tax->name,
            'value' => $tax->value
        ]);

        session()->flash('success', 'Pajak berhasil ditambahkan.');

        $this->closeModal();
    }

    private function resetForm()
    {
        $this->name = '';
        $this->value = 0;
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.modals.tax-quick-add-modal');
    }
}