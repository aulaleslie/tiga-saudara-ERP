<?php

namespace App\Livewire\Modules\People\Modals;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\People\Rules\UniqueCustomerField;

class SupplierQuickAddModal extends Component
{
    public $showModal = false;
    public $supplier_name;
    public $contact_name;
    public $supplier_email;
    public $supplier_phone;
    public $identity;
    public $identity_number;
    public $npwp;
    public $billing_address;
    public $shipping_address;
    public $bank_name;
    public $bank_branch;
    public $account_number;
    public $account_holder;
    public $payment_term_id;
    public int $formResetVersion = 1;

    public $listeners = [
        'openSupplierModal' => 'openModal',
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
        $this->supplier_name = '';
        $this->contact_name = '';
        $this->supplier_email = '';
        $this->supplier_phone = '';
        $this->identity = '';
        $this->identity_number = '';
        $this->npwp = '';
        $this->billing_address = '';
        $this->shipping_address = '';
        $this->bank_name = '';
        $this->bank_branch = '';
        $this->account_number = '';
        $this->account_holder = '';
        $this->payment_term_id = '';
        $this->formResetVersion++;
        $this->resetErrorBag();
        $this->resetValidation();
    }

    public function save()
    {
        $this->validate([
            'supplier_name' => [
                'required',
                'string',
                'max:255',
                (new UniqueCustomerField('supplier_name', null, 'suppliers'))->setMessage('Nama pemasok sudah digunakan.'),
            ],
            'contact_name' => [
                'nullable',
                'string',
                'max:255',
                (new UniqueCustomerField('contact_name', null, 'suppliers'))->setMessage('Nama kontak sudah digunakan.'),
            ],
            'supplier_email' => 'nullable|email|max:255',
            'supplier_phone' => 'nullable|string|max:20',
            'identity' => 'nullable|string|max:50',
            'identity_number' => 'nullable|required_if:identity,KTP,SIM,Passport|string|max:100',
            'npwp' => 'nullable|string|max:255',
            'billing_address' => 'nullable|string',
            'shipping_address' => 'nullable|string',
            'payment_term_id' => 'nullable|exists:payment_terms,id',
            'bank_name' => 'nullable|required_with:bank_branch,account_number,account_holder|string|max:255',
            'bank_branch' => 'nullable|required_with:bank_name,account_number,account_holder|string|max:255',
            'account_number' => 'nullable|required_with:bank_name,bank_branch,account_holder|string|max:255',
            'account_holder' => 'nullable|required_with:bank_name,bank_branch,account_number|string|max:255',
        ], [
            'supplier_name.required' => 'Nama pemasok wajib diisi.',
            'identity_number.required_if' => 'Nomor identitas wajib diisi jika identitas dipilih.',
            'bank_name.required_with' => 'Nama bank wajib diisi jika salah satu informasi bank diisi.',
            'bank_branch.required_with' => 'Cabang bank wajib diisi jika salah satu informasi bank diisi.',
            'account_number.required_with' => 'Nomor rekening wajib diisi jika salah satu informasi bank diisi.',
            'account_holder.required_with' => 'Pemegang akun wajib diisi jika salah satu informasi bank diisi.',
        ]);

        try {
            $setting_id = session('setting_id');
            if (!$setting_id) {
                throw new \Exception('Setting ID tidak ditemukan. Silakan login kembali.');
            }

            // Generate unique identifiers if email/phone are not provided
            $uniqueId = uniqid();
            $email = $this->supplier_email ?: "noemail-{$uniqueId}@placeholder.local";
            $phone = $this->supplier_phone ?: "nophone-{$uniqueId}";

            // Normalize optional identity fields so empty values do not collide with unique index
            $identity = is_string($this->identity) ? trim($this->identity) : $this->identity;
            $identityNumber = is_string($this->identity_number) ? trim($this->identity_number) : $this->identity_number;
            $identity = $identity === '' ? null : $identity;
            $identityNumber = $identityNumber === '' ? null : $identityNumber;

            $supplier = Supplier::create([
                'supplier_name' => $this->supplier_name,
                'contact_name' => $this->contact_name,
                'supplier_email' => $email,
                'supplier_phone' => $phone,
                'identity' => $identity,
                'identity_number' => $identityNumber,
                'npwp' => $this->npwp,
                'billing_address' => $this->billing_address,
                'shipping_address' => $this->shipping_address,
                'address' => $this->billing_address ?: '',
                'city' => '',
                'country' => '',
                'setting_id' => $setting_id,
                'payment_term_id' => $this->payment_term_id ?: null,
                'bank_name' => $this->bank_name,
                'bank_branch' => $this->bank_branch,
                'account_number' => $this->account_number,
                'account_holder' => $this->account_holder,
            ]);

            $supplierArray = $supplier->toArray();
            $supplierArray['display_name'] = $supplier->contact_name
                ? "{$supplier->contact_name} - {$supplier->supplier_name}"
                : $supplier->supplier_name;

            // Dispatch globally so all components can listen for it
            $this->dispatch('supplierCreated', $supplierArray);
            
            $this->closeModal();

            session()->flash('success', 'Pemasok berhasil ditambahkan!');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Supplier creation failed: ' . $e->getMessage(), [
                'exception' => $e,
                'data' => [
                    'supplier_name' => $this->supplier_name,
                    'email' => $this->supplier_email,
                ]
            ]);
            session()->flash('error', 'Gagal menambahkan pemasok. Silakan coba lagi.');
        }
    }

    public function render(): Factory|Application|View|\Illuminate\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.modules.people.modals.supplier-quick-add-modal', [
            'paymentTerms' => PaymentTerm::all(),
        ]);
    }
}
