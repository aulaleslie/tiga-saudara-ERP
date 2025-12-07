<?php

namespace App\Livewire\Modules\People\Modals;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PaymentTerm;

class SupplierQuickAddModal extends Component
{
    public $showModal = false;
    public $supplier_name;
    public $contact_name;
    public $email;
    public $phone;
    public $address;
    public $payment_term_id;

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
        $this->email = '';
        $this->phone = '';
        $this->address = '';
        $this->payment_term_id = '';
    }

    public function save()
    {
        $this->validate([
            'supplier_name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'payment_term_id' => 'nullable|exists:payment_terms,id',
        ], [
            'supplier_name.required' => 'Nama pemasok wajib diisi.',
        ]);

        try {
            $setting_id = session('setting_id');
            if (!$setting_id) {
                throw new \Exception('Setting ID tidak ditemukan. Silakan login kembali.');
            }

            // Generate unique identifiers if email/phone are not provided
            $uniqueId = uniqid();
            $email = $this->email ?: "noemail-{$uniqueId}@placeholder.local";
            $phone = $this->phone ?: "nophone-{$uniqueId}";

            $supplier = Supplier::create([
                'supplier_name' => $this->supplier_name,
                'contact_name' => $this->contact_name,
                'supplier_email' => $email,
                'supplier_phone' => $phone,
                'address' => $this->address,
                'city' => '',
                'country' => '',
                'setting_id' => $setting_id,
                'payment_term_id' => $this->payment_term_id ?: null,
            ]);

            $supplierArray = $supplier->toArray();

            // Dispatch globally so all components can listen for it
            $this->dispatch('supplierCreated', $supplierArray);
            
            $this->closeModal();

            session()->flash('success', 'Pemasok berhasil ditambahkan!');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Supplier creation failed: ' . $e->getMessage(), [
                'exception' => $e,
                'data' => [
                    'supplier_name' => $this->supplier_name,
                    'email' => $this->email,
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