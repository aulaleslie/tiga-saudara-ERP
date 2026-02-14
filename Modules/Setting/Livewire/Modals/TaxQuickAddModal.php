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
    public $is_default = false;
    public $product_id = null; // Track which product row is requesting the tax

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
            'value' => 'required|numeric|min:0|max:100',
            'is_default' => 'nullable|boolean',
        ];
    }

    protected function validationAttributes()
    {
        return [
            'name' => 'nama',
            'value' => 'nilai (%)',
            'is_default' => 'default',
        ];
    }

    public function openModal($productId = null)
    {
        $this->resetForm();
        $this->product_id = $productId;
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
            'value' => $this->value,
            'is_default' => (bool) $this->is_default,
        ]);

        // Dispatch event with structured data for ProductCart to handle
        $this->dispatch('taxCreated', 
            id: $tax->id,
            name: $tax->name,
            value: $tax->value,
            product_id: $this->product_id
        );

        $this->closeModal();
    }

    private function resetForm()
    {
        $this->name = '';
        $this->value = 0;
        $this->is_default = false;
        $this->product_id = null;
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.modals.tax-quick-add-modal');
    }
}
