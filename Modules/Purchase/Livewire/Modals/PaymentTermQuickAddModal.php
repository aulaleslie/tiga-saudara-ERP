<?php

namespace Modules\Purchase\Livewire\Modals;

use Livewire\Component;
use Modules\Purchase\Entities\PaymentTerm;
use Illuminate\Validation\Rule;

class PaymentTermQuickAddModal extends Component
{
    public $showModal = false;
    public $name = '';
    public $longevity = 0;

    protected $listeners = [
        'openPaymentTermModal' => 'openModal'
    ];

    protected function rules()
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('payment_terms', 'name')
            ],
            'longevity' => 'required|integer|min:0|max:365'
        ];
    }

    protected function validationAttributes()
    {
        return [
            'name' => 'nama',
            'longevity' => 'jangka waktu (hari)'
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

        $paymentTerm = PaymentTerm::create([
            'name' => $this->name,
            'longevity' => $this->longevity
        ]);

        $this->dispatch('paymentTermCreated', [
            'id' => $paymentTerm->id,
            'name' => $paymentTerm->name
        ]);

        session()->flash('success', 'Term pembayaran berhasil ditambahkan.');

        $this->closeModal();
    }

    private function resetForm()
    {
        $this->name = '';
        $this->longevity = 0;
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.modals.payment-term-quick-add-modal');
    }
}