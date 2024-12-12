<?php

namespace Modules\People\Http\Controllers;

use Modules\People\DataTables\SuppliersDataTable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Supplier;
use Modules\Purchase\DataTables\PurchaseDataTable;

class SuppliersController extends Controller
{

    public function index(SuppliersDataTable $dataTable)
    {
        abort_if(Gate::denies('supplier.access'), 403);

        return $dataTable->render('people::suppliers.index');
    }


    public function create()
    {
        abort_if(Gate::denies('supplier.create'), 403);

        return view('people::suppliers.create');
    }


    public function store(Request $request)
    {
        abort_if(Gate::denies('create_suppliers'), 403);

        // Validate the request data
        $request->validate([
            'contact_name' => 'required|string|max:255',
            'supplier_name' => 'required|string|max:255',
            'identity' => 'nullable|string|max:50',
            'identity_number' => 'nullable|required_if:identity,KTP,SIM,Passport|string|max:100',  // Required if identity is selected

            // Bank fields validation, mandatory only if one is filled
            'bank_name' => 'nullable|required_with:bank_branch,account_number,account_holder|string|max:255',
            'bank_branch' => 'nullable|required_with:bank_name,account_number,account_holder|string|max:255',
            'account_number' => 'nullable|required_with:bank_name,bank_branch,account_holder|string|max:255',
            'account_holder' => 'nullable|required_with:bank_name,bank_branch,account_number|string|max:255',
        ], [
            'contact_name.required' => 'Nama kontak wajib diisi.',
            'company_name.required' => 'Nama pemasok wajib diisi.',

            'bank_name.required_with' => 'Nama bank wajib diisi jika salah satu informasi bank diisi.',
            'bank_branch.required_with' => 'Cabang bank wajib diisi jika salah satu informasi bank diisi.',
            'account_number.required_with' => 'Nomor rekening wajib diisi jika salah satu informasi bank diisi.',
            'account_holder.required_with' => 'Pemegang akun wajib diisi jika salah satu informasi bank diisi.',

            'identity_number.required_if' => 'Nomor identitas wajib diisi jika identitas dipilih.',
        ]);

        $settingId = session('setting_id');
        // Create the supplier
        Supplier::create([
            'setting_id' => $settingId,
            'contact_name' => $request->contact_name,
            'supplier_name' => $request->supplier_name,
            'supplier_phone' => $request->supplier_phone ?? "",
            'identity' => $request->identity ?? "",
            'identity_number' => $request->identity_number ?? "",
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


    public function show(Supplier $supplier, PurchaseDataTable $dataTable)
    {
        abort_if(Gate::denies('show_suppliers'), 403);

        // Pass the supplier_id to the DataTable
        return $dataTable->with(['supplier_id' => $supplier->id])->render('people::suppliers.show', compact('supplier'));
    }


    public function edit(Supplier $supplier)
    {
        abort_if(Gate::denies('supplier.edit'), 403);

        return view('people::suppliers.edit', compact('supplier'));
    }


    public function update(Request $request, Supplier $supplier)
    {
        abort_if(Gate::denies('edit_suppliers'), 403);

        // Validate the request data
        $request->validate([
            'contact_name' => 'required|string|max:255',
            'supplier_name' => 'required|string|max:255',
            'supplier_phone' => 'nullable|string|max:255',
            'identity' => 'nullable|string|max:50',
            'identity_number' => 'nullable|required_if:identity,KTP,SIM,Passport|string|max:100',  // Required if identity is selected
            'supplier_email' => 'nullable|email|max:255',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',

            // Bank fields validation, mandatory only if one is filled
            'bank_name' => 'nullable|required_with:bank_branch,account_number,account_holder|string|max:255',
            'bank_branch' => 'nullable|required_with:bank_name,account_number,account_holder|string|max:255',
            'account_number' => 'nullable|required_with:bank_name,bank_branch,account_holder|string|max:255',
            'account_holder' => 'nullable|required_with:bank_name,bank_branch,account_number|string|max:255',
        ], [
            'contact_name.required' => 'Nama kontak wajib diisi.',
            'supplier_name.required' => 'Nama pemasok wajib diisi.',

            'bank_name.required_with' => 'Nama bank wajib diisi jika salah satu informasi bank diisi.',
            'bank_branch.required_with' => 'Cabang bank wajib diisi jika salah satu informasi bank diisi.',
            'account_number.required_with' => 'Nomor rekening wajib diisi jika salah satu informasi bank diisi.',
            'account_holder.required_with' => 'Pemegang akun wajib diisi jika salah satu informasi bank diisi.',

            'identity_number.required_if' => 'Nomor identitas wajib diisi jika identitas dipilih.',
        ]);

        // Update the supplier
        $supplier->update([
            'contact_name' => $request->contact_name,
            'supplier_name' => $request->supplier_name,
            'supplier_phone' => $request->supplier_phone ?? "",
            'identity' => $request->identity ?? "",
            'identity_number' => $request->identity_number ?? "",
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


    public function destroy(Supplier $supplier)
    {
        abort_if(Gate::denies('supplier.delete'), 403);

        $supplier->delete();

        toast('Data Pemasok Dihapus!', 'warning');

        return redirect()->route('suppliers.index');
    }
}
