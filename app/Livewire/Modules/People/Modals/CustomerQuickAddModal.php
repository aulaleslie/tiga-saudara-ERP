<?php

namespace App\Livewire\Modules\People\Modals;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;
use Modules\People\Entities\Customer;
use Modules\Purchase\Entities\PaymentTerm;

class CustomerQuickAddModal extends Component
{
    public $showModal = false;
    public $customer_name;
    public $contact_name;
    public $email;
    public $phone;
    public $address;
    public $payment_term_id;

    public $listeners = [
        'openCustomerModal' => 'openModal',
    ];

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

    private function resetForm()
    {
        $this->customer_name = '';
        $this->contact_name = '';
        $this->email = '';
        $this->phone = '';
        $this->address = '';
        $this->payment_term_id = '';
    }

    public function save()
    {
        $this->validate([
            'customer_name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'payment_term_id' => 'nullable|exists:payment_terms,id',
        ]);

        try {
            $customer = Customer::create([
                'customer_name' => $this->customer_name,
                'contact_name' => $this->contact_name,
                'email' => $this->email,
                'phone' => $this->phone,
                'address' => $this->address,
                'payment_term_id' => $this->payment_term_id,
            ]);

            $this->dispatch('customerCreated', $customer->toArray());
            $this->closeModal();

            session()->flash('success', 'Pelanggan berhasil ditambahkan!');
        } catch (\Exception $e) {
            session()->flash('error', 'Gagal menambahkan pelanggan: ' . $e->getMessage());
        }
    }

    public function render(): Factory|Application|View|\Illuminate\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.modules.people.modals.customer-quick-add-modal', [
            'paymentTerms' => PaymentTerm::all(),
        ]);
    }
}