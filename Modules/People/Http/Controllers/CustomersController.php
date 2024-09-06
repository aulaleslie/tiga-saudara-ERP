<?php

namespace Modules\People\Http\Controllers;

use Modules\People\DataTables\CustomersDataTable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Customer;

class CustomersController extends Controller
{

    public function index(CustomersDataTable $dataTable) {
        abort_if(Gate::denies('access_customers'), 403);

        return $dataTable->render('people::customers.index');
    }


    public function create() {
        abort_if(Gate::denies('create_customers'), 403);

        return view('people::customers.create');
    }


    public function store(Request $request) {
        abort_if(Gate::denies('create_customers'), 403);

        $request->validate([
            'customer_name'      => 'required|string|max:255',
            'customer_phone'     => 'required|max:255',
            'customer_email'     => 'required|email|max:255',
            'identity'           => 'nullable|string|max:50',
            'identity_number'    => 'nullable|string|max:100',
            'telephone'          => 'nullable|string|max:100',
            'fax'                => 'nullable|string|max:100',
            'npwp'               => 'nullable|string|max:100',
            'billing_address'    => 'nullable|string|max:500',
            'shipping_address'   => 'nullable|string|max:500',
            'additional_info'    => 'nullable|string|max:1000',
            'bank_name'          => 'nullable|string|max:255',
            'bank_branch'        => 'nullable|string|max:255',
            'account_number'     => 'nullable|string|max:255',
            'account_holder'     => 'nullable|string|max:255',
        ]);

        Customer::create([
            'customer_name'      => $request->customer_name,
            'customer_phone'     => $request->customer_phone,
            'customer_email'     => $request->customer_email,
            'identity'           => $request->identity,
            'identity_number'    => $request->identity_number,
            'telephone'          => $request->telephone,
            'fax'                => $request->fax,
            'npwp'               => $request->npwp,
            'billing_address'    => $request->billing_address,
            'shipping_address'   => $request->shipping_address,
            'additional_info'    => $request->additional_info,
            'bank_name'          => $request->bank_name,
            'bank_branch'        => $request->bank_branch,
            'account_number'     => $request->account_number,
            'account_holder'     => $request->account_holder,
        ]);

        toast('Pelanggan Ditambahkan!', 'success');

        return redirect()->route('customers.index');
    }


    public function show(Customer $customer) {
        abort_if(Gate::denies('show_customers'), 403);

        return view('people::customers.show', compact('customer'));
    }


    public function edit(Customer $customer) {
        abort_if(Gate::denies('edit_customers'), 403);

        return view('people::customers.edit', compact('customer'));
    }


    public function update(Request $request, Customer $customer) {
        abort_if(Gate::denies('update_customers'), 403);

        $request->validate([
            'customer_name'      => 'required|string|max:255',
            'customer_phone'     => 'required|max:255',
            'customer_email'     => 'required|email|max:255',
            'identity'           => 'nullable|string|max:50',
            'identity_number'    => 'nullable|string|max:100',
            'telephone'          => 'nullable|string|max:100',
            'fax'                => 'nullable|string|max:100',
            'npwp'               => 'nullable|string|max:100',
            'billing_address'    => 'nullable|string|max:500',
            'shipping_address'   => 'nullable|string|max:500',
            'additional_info'    => 'nullable|string|max:1000',
            'bank_name'          => 'nullable|string|max:255',
            'bank_branch'        => 'nullable|string|max:255',
            'account_number'     => 'nullable|string|max:255',
            'account_holder'     => 'nullable|string|max:255',
        ]);

        $customer->update([
            'customer_name'      => $request->customer_name,
            'customer_phone'     => $request->customer_phone,
            'customer_email'     => $request->customer_email,
            'identity'           => $request->identity,
            'identity_number'    => $request->identity_number,
            'telephone'          => $request->telephone,
            'fax'                => $request->fax,
            'npwp'               => $request->npwp,
            'billing_address'    => $request->billing_address,
            'shipping_address'   => $request->shipping_address,
            'additional_info'    => $request->additional_info,
            'bank_name'          => $request->bank_name,
            'bank_branch'        => $request->bank_branch,
            'account_number'     => $request->account_number,
            'account_holder'     => $request->account_holder,
        ]);

        toast('Data Pelanggan Diperbaharui!', 'info');

        return redirect()->route('customers.index');
    }


    public function destroy(Customer $customer) {
        abort_if(Gate::denies('delete_customers'), 403);

        $customer->delete();

        toast('Data Pelanggan Dihapus!', 'warning');

        return redirect()->route('customers.index');
    }
}
