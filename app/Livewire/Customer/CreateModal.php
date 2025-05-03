<?php

namespace App\Livewire\Customer;

use Livewire\Component;
use Modules\People\Entities\Customer;

class CreateModal extends Component
{
    public $showModal = false;
    public $contact_name;

    protected $listeners = ['openCustomerModal' => 'open'];

    protected function rules()
    {
        return [
            'contact_name' => 'required|string|max:255',
        ];
    }

    public function open()
    {
        $this->resetValidation();
        $this->reset(); // Clears contact_name
        $this->showModal = true;
    }

    public function save()
    {
        $this->validate();

        $customer = Customer::create([
            'setting_id'     => session('setting_id'),
            'contact_name'   => $this->contact_name,
            'customer_name'  => '',
            'customer_phone' => '',
            'customer_email' => '',
            'identity'       => '',
            'identity_number'=> '',
            'npwp'           => '',
            'billing_address'=> '',
            'shipping_address' => '',
            'city'           => '',
            'country'        => '',
            'address'        => '',
            'additional_info'=> '',
            'payment_term_id'=> null,
            'bank_name'      => '',
            'bank_branch'    => '',
            'account_number' => '',
            'account_holder' => '',
            'tier'           => null,
        ]);

        $this->dispatch('customerSelected', $customer);
        $this->dispatch('toast', 'Pelanggan Ditambahkan!');
        $this->showModal = false;
    }

    public function render()
    {
        return view('livewire.customer.create-modal');
    }
}
