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
        $query = PaymentMethod::with('chartOfAccount');

        if (request()->filled('status')) {
            $status = request('status');
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $paymentMethods = $query->get();

        return view('setting::payment_methods.index', compact('paymentMethods'));
    }

    public function create()
    {
        abort_if(Gate::denies('paymentMethods.create'), 403);
        // Get chart of accounts for the dropdown
        $chartOfAccounts = ChartOfAccount::where('is_active', true)->get();

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
        // Get chart of accounts for the dropdown, including current COA even if inactive
        $chartOfAccounts = ChartOfAccount::where('is_active', true)
            ->orWhere('id', $paymentMethod->coa_id)
            ->get();

        return view('setting::payment_methods.edit', compact('paymentMethod', 'chartOfAccounts'));
    }

    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod)
    {
        // Update the payment method
        $paymentMethod->update($request->validated());

        toast('Payment method updated successfully!', 'info');
        return redirect()->route('payment-methods.index');
    }

    public function toggleStatus(PaymentMethod $paymentMethod, \App\Services\MasterDataLifecycleService $lifecycleService)
    {
        abort_if(! Gate::allows('paymentMethods.edit') && ! Gate::allows('paymentMethods.delete'), 403);

        try {
            if ($paymentMethod->is_active) {
                $lifecycleService->deactivate($paymentMethod);
                toast('Metode pembayaran berhasil dinonaktifkan!', 'info');
            } else {
                $lifecycleService->reactivate($paymentMethod);
                toast('Metode pembayaran berhasil diaktifkan kembali!', 'success');
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            toast($e->getMessage(), 'error');
        }

        return redirect()->back();
    }

    public function destroy(PaymentMethod $paymentMethod, \App\Services\MasterDataLifecycleService $lifecycleService)
    {
        abort_if(! Gate::allows('paymentMethods.edit') && ! Gate::allows('paymentMethods.delete'), 403);

        try {
            $lifecycleService->deactivate($paymentMethod);
            toast('Metode pembayaran berhasil dinonaktifkan!', 'info');
        } catch (\Illuminate\Validation\ValidationException $e) {
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('payment-methods.index');
    }
}
