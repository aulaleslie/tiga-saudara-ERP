<?php

namespace Modules\People\Http\Controllers;

use App\Services\IdempotencyService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Modules\People\DataTables\CustomersDataTable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Customer;
use Modules\Purchase\Entities\PaymentTerm;

class CustomersController extends Controller
{

    public function __construct()
    {
        $this->middleware('idempotency')->only('store');
    }

    public function index(CustomersDataTable $dataTable)
    {
        abort_if(Gate::denies('customers.access'), 403);

        return $dataTable->render('people::customers.index');
    }


    public function create(Request $request): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('customers.create'), 403);

        $paymentTerms = PaymentTerm::all(); // Ambil semua PaymentTerm
        $idempotencyToken = IdempotencyService::tokenFromRequest($request);

        return view('people::customers.create', compact('paymentTerms', 'idempotencyToken'));
    }



    public function store(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('customers.create'), 403);

        // Validate the request data
        $request->validate([
            'contact_name' => 'required|string|max:255',
            'customer_phone' => 'required|string|max:255',
            'payment_term_id' => 'nullable|exists:payment_terms,id', // Validasi PaymentTerm

            // Bank fields validation, mandatory only if one is filled
            'bank_name' => 'nullable|required_with:bank_branch,account_number,account_holder|string|max:255',
            'bank_branch' => 'nullable|required_with:bank_name,account_number,account_holder|string|max:255',
            'account_number' => 'nullable|required_with:bank_name,bank_branch,account_holder|string|max:255',
            'account_holder' => 'nullable|required_with:bank_name,bank_branch,account_number|string|max:255',

            'customer_name' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'identity' => 'nullable|string|max:50',
            'identity_number' => 'nullable|required_if:identity,KTP,SIM,Passport|string|max:100',  // Required if identity is selected
            'npwp' => 'nullable|string|max:100',
            'billing_address' => 'nullable|string|max:500',
            'shipping_address' => 'nullable|string|max:500',
            'additional_info' => 'nullable|string|max:1000',
            'tier' => 'nullable|in:WHOLESALER,RESELLER',
        ], [
            'contact_name.required' => 'Nama kontak wajib diisi.',
            'customer_phone.required' => 'Nomor telepon wajib diisi.',

            'bank_name.required_with' => 'Nama bank wajib diisi jika salah satu informasi bank diisi.',
            'bank_branch.required_with' => 'Cabang bank wajib diisi jika salah satu informasi bank diisi.',
            'account_number.required_with' => 'Nomor rekening wajib diisi jika salah satu informasi bank diisi.',
            'account_holder.required_with' => 'Pemegang akun wajib diisi jika salah satu informasi bank diisi.',

            'identity_number.required_if' => 'Nomor identitas wajib diisi jika identitas dipilih.',
        ]);

        // Assign the setting_id from the session
        $settingId = session('setting_id');

        // Create the customer
        Customer::create([
            'setting_id' => $settingId,
            'payment_term_id' => $request->payment_term_id, // Menyimpan payment_term_id
            'contact_name' => $request->contact_name,
            'customer_name' => $request->customer_name ?? '',
            'customer_phone' => $request->customer_phone,
            'customer_email' => $request->customer_email ?? '',
            'identity' => $request->identity,
            'identity_number' => $request->identity_number,
            'npwp' => $request->npwp,
            'billing_address' => $request->billing_address,
            'shipping_address' => $request->shipping_address,
            'city' => $request->city ?? '',
            'country' => $request->country ?? '',
            'address' => $request->address ?? '',
            'additional_info' => $request->additional_info ?? '',

            // Optional Bank information
            'bank_name' => $request->bank_name,
            'bank_branch' => $request->bank_branch,
            'account_number' => $request->account_number,
            'account_holder' => $request->account_holder,
            'tier' => $request->tier,
        ]);

        toast('Pelanggan Ditambahkan!', 'success');

        return redirect()->route('customers.index');
    }


    public function show(Customer $customer): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('customers.show'), 403);
        $customer->load('paymentTerm');
        return view('people::customers.show', compact('customer'));
    }


    public function edit(Customer $customer): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('customers.edit'), 403);

        $paymentTerms = PaymentTerm::all(); // Ambil semua PaymentTerm
        return view('people::customers.edit', compact('customer', 'paymentTerms'));
    }


    public function update(Request $request, Customer $customer): RedirectResponse
    {
        abort_if(Gate::denies('customers.edit'), 403);

        $request->validate([
            'contact_name' => 'required|string|max:255',
            'customer_phone' => 'required|max:255',
            'payment_term_id' => 'nullable|exists:payment_terms,id', // Validasi PaymentTerm
            'customer_email' => 'nullable|email|max:255',
            'identity' => 'nullable|string|max:50',
            'identity_number' => 'nullable|string|max:100',
            'fax' => 'nullable|string|max:100',
            'npwp' => 'nullable|string|max:100',
            'billing_address' => 'nullable|string|max:500',
            'shipping_address' => 'nullable|string|max:500',
            'bank_name' => 'nullable|string|max:255',
            'bank_branch' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'account_holder' => 'nullable|string|max:255',
            'tier' => 'nullable|in:WHOLESALER,RESELLER',
        ]);

        $customer->update([
            'contact_name' => $request->contact_name,
            'customer_phone' => $request->customer_phone,
            'payment_term_id' => $request->payment_term_id, // Menyimpan payment_term_id
            'customer_email' => $request->customer_email ?? '',
            'identity' => $request->identity,
            'identity_number' => $request->identity_number,
            'fax' => $request->fax,
            'npwp' => $request->npwp,
            'billing_address' => $request->billing_address,
            'shipping_address' => $request->shipping_address,
            'bank_name' => $request->bank_name,
            'bank_branch' => $request->bank_branch,
            'account_number' => $request->account_number,
            'account_holder' => $request->account_holder,
            'additional_info' => $request->additional_info,
            'tier' => $request->tier,
        ]);

        toast('Data Pelanggan Diperbaharui!', 'info');

        return redirect()->route('customers.index');
    }


    public function destroy(Customer $customer): RedirectResponse
    {
        abort_if(Gate::denies('customers.delete'), 403);

        $customer->delete();

        toast('Data Pelanggan Dihapus!', 'warning');

        return redirect()->route('customers.index');
    }
}
