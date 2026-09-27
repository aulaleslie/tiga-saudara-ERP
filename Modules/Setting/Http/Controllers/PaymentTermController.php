<?php

namespace Modules\Setting\Http\Controllers;

use App\Services\IdempotencyService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Purchase\Entities\PaymentTerm;

class PaymentTermController extends Controller
{
    public function __construct()
    {
        $this->middleware('idempotency')->only('store');
    }
    /**
     * Display a listing of the resource.
     * @return Factory|Application|View|\Illuminate\Contracts\Foundation\Application
     */
    public function index(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('paymentTerms.access'), 403);
        $query = PaymentTerm::query();

        if (request()->filled('status')) {
            $status = request('status');
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $payment_terms = $query->get();

        return view('setting::payment_terms.index', [
            'payment_terms' => $payment_terms
        ]);
    }

    /**
     * Show the form for creating a new resource.
     * @return Factory|Application|View|\Illuminate\Contracts\Foundation\Application
     */
    public function create(Request $request): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('paymentTerms.create'), 403);
        $idempotencyToken = IdempotencyService::tokenFromRequest($request);

        return view('setting::payment_terms.create', compact('idempotencyToken'));
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('paymentTerms.create'), 403);

        $request->validate([
            'name'      => 'required|string|max:255|unique:payment_terms,name',
            'longevity' => 'required|integer|min:0', // allow 0
        ], [
            'longevity.min' => 'Tempo tidak boleh lebih kecil dari 0',
            'longevity.integer' => 'Tempo harus berupa angka hari (bulat).',
        ]);

        PaymentTerm::create([
            'name'       => $request->name,
            'longevity'  => (int) $request->longevity,
        ]);

        toast('Term Pembayaran Berhasil ditambahkan!', 'success');
        return redirect()->route('payment-terms.index');
    }

    /**
     * Show the specified resource.
     * @param PaymentTerm $payment_term
     * @return Factory|Application|View|\Illuminate\Contracts\Foundation\Application
     */
    public function edit(PaymentTerm $payment_term): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('paymentTerms.edit'), 403);
        return view('setting::payment_terms.edit', [
            'payment_term' => $payment_term
        ]);
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param PaymentTerm $payment_term
     * @return RedirectResponse
     */
    public function update(Request $request, PaymentTerm $payment_term): RedirectResponse
    {
        abort_if(Gate::denies('paymentTerms.edit'), 403);

        $request->validate([
            'name'      => 'required|string|max:255|unique:payment_terms,name,' . $payment_term->id,
            'longevity' => 'required|integer|min:0', // allow 0
        ], [
            'longevity.min' => 'Tempo tidak boleh lebih kecil dari 0',
            'longevity.integer' => 'Tempo harus berupa angka hari (bulat).',
        ]);

        $payment_term->update([
            'name'      => $request->name,
            'longevity' => (int) $request->longevity,
        ]);

        toast('Term Pembayaran diperbaharui!', 'info');
        return redirect()->route('payment-terms.index');
    }

    public function toggleStatus(PaymentTerm $payment_term, \App\Services\MasterDataLifecycleService $lifecycleService): RedirectResponse
    {
        abort_if(! Gate::allows('paymentTerms.edit') && ! Gate::allows('paymentTerms.delete'), 403);

        try {
            if ($payment_term->is_active) {
                $lifecycleService->deactivate($payment_term);
                toast('Syarat pembayaran berhasil dinonaktifkan!', 'info');
            } else {
                $lifecycleService->reactivate($payment_term);
                toast('Syarat pembayaran berhasil diaktifkan kembali!', 'success');
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            toast($e->getMessage(), 'error');
        }

        return redirect()->back();
    }

    /**
     * Remove the specified resource from storage.
     * @param PaymentTerm $payment_term
     * @return RedirectResponse
     */
    public function destroy(PaymentTerm $payment_term, \App\Services\MasterDataLifecycleService $lifecycleService): RedirectResponse
    {
        abort_if(! Gate::allows('paymentTerms.edit') && ! Gate::allows('paymentTerms.delete'), 403);

        try {
            $lifecycleService->deactivate($payment_term);
            toast('Syarat pembayaran berhasil dinonaktifkan!', 'info');
        } catch (\Illuminate\Validation\ValidationException $e) {
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('payment-terms.index');
    }
}
