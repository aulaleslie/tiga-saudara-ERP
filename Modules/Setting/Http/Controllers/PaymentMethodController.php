<?php

namespace Modules\Setting\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\PaymentMethod;

class PaymentMethodController extends Controller
{
    public function index()
    {
        abort_if(Gate::denies('paymentMethods.access'), 403);
        // Get payment methods filtered by setting_id
        $paymentMethods = PaymentMethod::with('chartOfAccount')->get();

        return view('setting::payment_methods.index', compact('paymentMethods'));
    }

    public function create()
    {
        abort_if(Gate::denies('paymentMethods.create'), 403);
        // Get chart of accounts for the dropdown
        $chartOfAccounts = ChartOfAccount::all();

        return view('setting::payment_methods.create', compact('chartOfAccounts'));
    }

    public function store(Request $request)
    {
        abort_if(Gate::denies('paymentMethods.create'), 403);
        // Validate the request
        $request->validate([
            'name' => 'required|string|max:255',
            'coa_id' => 'required|exists:chart_of_accounts,id',
        ]);

        // Create a new payment method
        PaymentMethod::create([
            'name' => $request->name,
            'coa_id' => $request->coa_id,
            'setting_id' => session('setting_id'), // Autofill setting_id from session
        ]);

        toast('Payment method created successfully!', 'success');
        return redirect()->route('payment-methods.index');
    }

    public function edit(PaymentMethod $paymentMethod)
    {
        abort_if(Gate::denies('paymentMethods.edit'), 403);
        // Get chart of accounts for the dropdown
        $chartOfAccounts = ChartOfAccount::all();

        return view('setting::payment_methods.edit', compact('paymentMethod', 'chartOfAccounts'));
    }

    public function update(Request $request, PaymentMethod $paymentMethod)
    {
        abort_if(Gate::denies('paymentMethods.edit'), 403);
        // Validate the request
        $request->validate([
            'name' => 'required|string|max:255',
            'coa_id' => 'required|exists:chart_of_accounts,id',
        ]);

        // Update the payment method
        $paymentMethod->update([
            'name' => $request->name,
            'coa_id' => $request->coa_id,
        ]);

        toast('Payment method updated successfully!', 'info');
        return redirect()->route('payment-methods.index');
    }

    public function destroy(PaymentMethod $paymentMethod)
    {
        abort_if(Gate::denies('paymentMethods.delete'), 403);
        // Delete the payment method
        $paymentMethod->delete();

        toast('Payment method deleted successfully!', 'warning');
        return redirect()->route('payment-methods.index');
    }
}
