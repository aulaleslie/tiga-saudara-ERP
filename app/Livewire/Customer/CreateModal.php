<?php

namespace App\Livewire\Customer;

use App\Constants\CustomerTier;
use Livewire\Component;
use Modules\People\Entities\Customer;
use Modules\People\Rules\UniqueCustomerField;

class CreateModal extends Component
{
    public $showModal = false;
    public $customer_name;
    public $contact_name;
    public $tier = null;

    protected $listeners = ['openCustomerModal' => 'open'];

    protected function rules()
    {
        return [
            'customer_name' => [
                'required',
                'string',
                'max:255',
                (new UniqueCustomerField('customer_name'))->setMessage('Nama pelanggan sudah digunakan.'),
            ],
            'contact_name' => [
                'nullable',
                'string',
                'max:255',
                (new UniqueCustomerField('contact_name'))->setMessage('Nama kontak sudah digunakan.'),
            ],
            'tier' => 'nullable|in:WHOLESALER,RESELLER',
        ];
    }

    public function open()
    {
        $this->resetValidation();
        $this->reset(); // Clears inputs
        $this->showModal = true;
    }

    public function save()
    {
        $this->customer_name = trim((string)$this->customer_name);
        $this->contact_name = trim((string)$this->contact_name) ?: null;

        $this->validate();

        // Generate unique placeholder values for optional unique fields
        $uniqId = uniqid();
        $email = "noemail-{$uniqId}@placeholder.local";
        $phone = "nophone-{$uniqId}";

        $customer = Customer::create([
            'setting_id'     => session('setting_id'),
            'contact_name'   => $this->contact_name ?: null,
            'customer_name'  => $this->customer_name,
            'customer_phone' => $phone,
            'customer_email' => $email,
            'identity'       => null,
            'identity_number'=> null,
            'npwp'           => null,
            'billing_address'=> null,
            'shipping_address' => null,
            'city'           => '',
            'country'        => '',
            'address'        => '',
            'additional_info'=> null,
            'payment_term_id'=> null,
            'bank_name'      => null,
            'bank_branch'    => null,
            'account_number' => null,
            'account_holder' => null,
            'tier'           => $this->tier,
        ]);

        // Reload the customer to ensure all relationships and attributes are loaded
        $customer->refresh();

        $this->dispatch('customerSelected', $customer->toArray());
        $this->dispatch('toast', 'Pelanggan Ditambahkan!');
        $this->showModal = false;
    }

    public function render()
    {
        return view('livewire.customer.create-modal');
    }
}
