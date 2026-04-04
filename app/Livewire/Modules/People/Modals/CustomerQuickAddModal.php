<?php

namespace App\Livewire\Modules\People\Modals;

use App\Constants\CustomerTier;
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
    public $customer_email;
    public $customer_phone;
    public $address;
    public $payment_term_id;
    public $tier;

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
        $this->customer_email = '';
        $this->customer_phone = '';
        $this->address = '';
        $this->payment_term_id = '';
        $this->tier = '';
    }

    public function save()
    {
        $this->validate([
            'customer_name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'payment_term_id' => 'nullable|exists:payment_terms,id',
            'tier' => 'nullable|in:WHOLESALER,RESELLER',
        ]);

        try {
            // Generate unique placeholder values for empty email/phone to satisfy NOT NULL + unique constraints
            $uniqId = uniqid();
            $email = !empty($this->customer_email) ? $this->customer_email : "noemail-{$uniqId}@placeholder.local";
            $phone = !empty($this->customer_phone) ? $this->customer_phone : "nophone-{$uniqId}";

            $customer = Customer::create([
                'setting_id' => session('setting_id'),
                'customer_name' => $this->customer_name,
                'contact_name' => $this->contact_name ?: $this->customer_name,
                'customer_email' => $email,
                'customer_phone' => $phone,
                'address' => $this->address ?: '',
                'city' => '',
                'country' => '',
                'payment_term_id' => $this->payment_term_id ?: null,
                'tier' => $this->tier ?: null,
            ]);

            // Store customer data before resetting form
            $customerData = $customer->toArray();
            
            // Close modal and reset form first
            $this->showModal = false;
            $this->resetForm();
            
            // Then dispatch event with the stored customer data
            $this->dispatch('customerCreated', $customerData);

            session()->flash('success', 'Pelanggan berhasil ditambahkan!');
        } catch (\Exception $e) {
            session()->flash('error', 'Gagal menambahkan pelanggan: ' . $e->getMessage());
        }
    }

    public function render(): Factory|Application|View|\Illuminate\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.modules.people.modals.customer-quick-add-modal', [
            'paymentTerms' => PaymentTerm::all(),
            'tierOptions' => CustomerTier::options(),
        ]);
    }
}
