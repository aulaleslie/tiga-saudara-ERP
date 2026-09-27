<?php

namespace Modules\People\Http\Controllers;

use App\Services\IdempotencyService;
use Modules\People\DataTables\SuppliersDataTable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\People\Rules\UniqueCustomerField;

class SuppliersController extends Controller
{

    public function __construct()
    {
        $this->middleware('idempotency')->only('store');
    }

    public function index(SuppliersDataTable $dataTable)
    {
        abort_if(Gate::denies('suppliers.access'), 403);

        return $dataTable->render('people::suppliers.index');
    }


    public function create(Request $request)
    {
        abort_if(Gate::denies('suppliers.create'), 403);

        // Ambil data PaymentTerm untuk dropdown
        $paymentTerms = PaymentTerm::all();

        $idempotencyToken = IdempotencyService::tokenFromRequest($request);

        return view('people::suppliers.create', compact('paymentTerms', 'idempotencyToken'));
    }


    public function store(Request $request)
    {
        abort_if(Gate::denies('suppliers.create'), 403);

        // Validate the request data
        $request->validate([
            'contact_name' => [
                'nullable',
                'string',
                'max:255',
                (new UniqueCustomerField('contact_name', null, 'suppliers'))->setMessage('Nama kontak sudah digunakan.'),
            ],
            'supplier_name' => [
                'required',
                'string',
                'max:255',
                (new UniqueCustomerField('supplier_name', null, 'suppliers'))->setMessage('Nama pemasok sudah digunakan.'),
            ],
            'identity' => 'nullable|string|max:50',
            'identity_number' => [
                'nullable',
                'required_if:identity,KTP,SIM,Passport',
                'string',
                'max:100',
            ],
            'payment_term_id' => 'nullable|exists:payment_terms,id',

            // Bank fields validation, mandatory only if one is filled
            'bank_name' => 'nullable|required_with:bank_branch,account_number,account_holder|string|max:255',
            'bank_branch' => 'nullable|required_with:bank_name,account_number,account_holder|string|max:255',
            'account_number' => 'nullable|required_with:bank_name,bank_branch,account_holder|string|max:255',
            'account_holder' => 'nullable|required_with:bank_name,bank_branch,account_number|string|max:255',

            'supplier_phone' => 'nullable|string|max:255',
            'supplier_email' => [
                'nullable',
                'email',
                'max:255',
            ],
        ], [
            'supplier_name.required' => 'Nama pemasok wajib diisi.',

            'bank_name.required_with' => 'Nama bank wajib diisi jika salah satu informasi bank diisi.',
            'bank_branch.required_with' => 'Cabang bank wajib diisi jika salah satu informasi bank diisi.',
            'account_number.required_with' => 'Nomor rekening wajib diisi jika salah satu informasi bank diisi.',
            'account_holder.required_with' => 'Pemegang akun wajib diisi jika salah satu informasi bank diisi.',

            'identity_number.required_if' => 'Nomor identitas wajib diisi jika identitas dipilih.',
        ]);

        // Create the supplier
        Supplier::create([
            'setting_id' => session('setting_id'),
            'payment_term_id' => $request->payment_term_id,
            'contact_name' => $request->contact_name,
            'supplier_name' => $request->supplier_name,
            'supplier_phone' => $request->supplier_phone ?? "",
            'identity' => $request->input('identity') ?: null,
            'identity_number' => $request->input('identity_number') ?: null,
            'billing_address' => $request->billing_address ?? "",
            'shipping_address' => $request->shipping_address ?? "",
            'npwp' => $request->npwp ?? "",
            'supplier_email' => $request->supplier_email ?? "",
            'city' => $request->city ?? "",
            'country' => $request->country ?? "",
            'address' => $request->address ?? "",

            // Optional Bank information
            'bank_name' => $request->bank_name ?? "",
            'bank_branch' => $request->bank_branch ?? "",
            'account_number' => $request->account_number ?? "",
            'account_holder' => $request->account_holder ?? "",
        ]);

        toast('Pemasok Ditambahkan!', 'success');

        return redirect()->route('suppliers.index');
    }


    public function show(Supplier $supplier)
    {
        abort_if(Gate::denies('suppliers.show'), 403);

        return view('people::suppliers.show', compact('supplier'));
    }


    public function edit(Supplier $supplier)
    {
        abort_if(Gate::denies('suppliers.edit'), 403);

        // Ambil data PaymentTerm untuk dropdown
        $paymentTerms = PaymentTerm::all();

        return view('people::suppliers.edit', compact('supplier', 'paymentTerms'));
    }



    public function update(Request $request, Supplier $supplier)
    {
        abort_if(Gate::denies('suppliers.edit'), 403);

        // Validate the request data
        $request->validate([
            'contact_name' => [
                'nullable',
                'string',
                'max:255',
                (new UniqueCustomerField('contact_name', $supplier->id, 'suppliers'))->setMessage('Nama kontak sudah digunakan.'),
            ],
            'supplier_name' => [
                'required',
                'string',
                'max:255',
                (new UniqueCustomerField('supplier_name', $supplier->id, 'suppliers'))->setMessage('Nama pemasok sudah digunakan.'),
            ],
            'payment_term_id' => 'nullable|exists:payment_terms,id',
            'supplier_phone' => 'nullable|string|max:255',
            'identity' => 'nullable|string|max:50',
            'identity_number' => [
                'nullable',
                'required_if:identity,KTP,SIM,Passport',
                'string',
                'max:100',
            ],
            'supplier_email' => [
                'nullable',
                'email',
                'max:255',
            ],
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',

            // Bank fields validation, mandatory only if one is filled
            'bank_name' => 'nullable|required_with:bank_branch,account_number,account_holder|string|max:255',
            'bank_branch' => 'nullable|required_with:bank_name,account_number,account_holder|string|max:255',
            'account_number' => 'nullable|required_with:bank_name,bank_branch,account_holder|string|max:255',
            'account_holder' => 'nullable|required_with:bank_name,bank_branch,account_number|string|max:255',
        ], [
            'supplier_name.required' => 'Nama pemasok wajib diisi.',

            'bank_name.required_with' => 'Nama bank wajib diisi jika salah satu informasi bank diisi.',
            'bank_branch.required_with' => 'Cabang bank wajib diisi jika salah satu informasi bank diisi.',
            'account_number.required_with' => 'Nomor rekening wajib diisi jika salah satu informasi bank diisi.',
            'account_holder.required_with' => 'Pemegang akun wajib diisi jika salah satu informasi bank diisi.',

            'identity_number.required_if' => 'Nomor identitas wajib diisi jika identitas dipilih.',
        ]);

        // Update the supplier
        $supplier->update([
            'payment_term_id' => $request->payment_term_id, // Update PaymentTerm ID
            'contact_name' => $request->contact_name,
            'supplier_name' => $request->supplier_name,
            'supplier_phone' => $request->supplier_phone ?? "",
            'identity' => $request->input('identity') ?: null,
            'identity_number' => $request->input('identity_number') ?: null,
            'billing_address' => $request->billing_address ?? "",
            'shipping_address' => $request->shipping_address ?? "",
            'npwp' => $request->npwp ?? "",
            'supplier_email' => $request->supplier_email ?? "",
            'city' => $request->city ?? "",
            'country' => $request->country ?? "",
            'address' => $request->address ?? "",

            // Optional Bank information
            'bank_name' => $request->bank_name ?? "",
            'bank_branch' => $request->bank_branch ?? "",
            'account_number' => $request->account_number ?? "",
            'account_holder' => $request->account_holder ?? "",
        ]);

        toast('Data Pemasok Diperbaharui!', 'info');

        return redirect()->route('suppliers.index');
    }


    public function toggleStatus(Supplier $supplier, \App\Services\MasterDataLifecycleService $lifecycleService): \Illuminate\Http\RedirectResponse
    {
        abort_if(! Gate::allows('suppliers.edit') && ! Gate::allows('suppliers.delete'), 403);

        try {
            if ($supplier->is_active) {
                $lifecycleService->deactivate($supplier);
                toast('Pemasok berhasil dinonaktifkan!', 'info');
            } else {
                $lifecycleService->reactivate($supplier);
                toast('Pemasok berhasil diaktifkan kembali!', 'success');
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            toast($e->getMessage(), 'error');
        }

        return redirect()->back();
    }

    public function destroy(Supplier $supplier, \App\Services\MasterDataLifecycleService $lifecycleService): \Illuminate\Http\RedirectResponse
    {
        abort_if(! Gate::allows('suppliers.edit') && ! Gate::allows('suppliers.delete'), 403);

        try {
            $lifecycleService->deactivate($supplier);
            toast('Pemasok berhasil dinonaktifkan!', 'info');
        } catch (\Illuminate\Validation\ValidationException $e) {
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('suppliers.index');
    }
}
