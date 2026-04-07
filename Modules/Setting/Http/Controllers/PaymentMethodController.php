<?php

namespace Modules\Setting\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Http\Requests\StorePaymentMethodRequest;
use Modules\Setting\Http\Requests\UpdatePaymentMethodRequest;

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

    public function store(StorePaymentMethodRequest $request)
    {
        // Create a new payment method
        PaymentMethod::create($request->validated());

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

    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod)
    {
        // Update the payment method
        $paymentMethod->update($request->validated());

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
