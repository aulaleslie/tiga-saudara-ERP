<?php

namespace Modules\Setting\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Purchase\Entities\PaymentTerm;

class PaymentTermController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Factory|Application|View|\Illuminate\Contracts\Foundation\Application
     */
    public function index(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        $currentSettingId = session('setting_id');
        $payment_terms = PaymentTerm::where('setting_id', $currentSettingId)->get();

        return view('setting::payment_terms.index', [
            'payment_terms' => $payment_terms
        ]);
    }

    /**
     * Show the form for creating a new resource.
     * @return Factory|Application|View|\Illuminate\Contracts\Foundation\Application
     */
    public function create(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('setting::payment_terms.create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:payment_terms,name,NULL,id,setting_id,' . session('setting_id'),
            'longevity' => 'required|numeric|gt:0',
        ]);

        PaymentTerm::create([
            'name' => $request->name,
            'longevity' => $request->longevity,
            'setting_id' => session('setting_id'),  // Get setting_id from session
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
        $request->validate([
            'name' => 'required|string|max:255|unique:payment_terms,name,' . $payment_term->id . ',id,setting_id,' . session('setting_id'),
            'longevity' => 'required|numeric|gt:0',
        ]);

        $payment_term->update([
            'name' => $request->name,
            'longevity' => $request->longevity,
        ]);

        toast('Term Pembayaran diperbaharui!', 'info');

        return redirect()->route('payment-terms.index');
    }

    /**
     * Remove the specified resource from storage.
     * @param PaymentTerm $payment_term
     * @return RedirectResponse
     */
    public function destroy(PaymentTerm $payment_term): RedirectResponse
    {
        $payment_term->delete();

        toast('Term Pembayaran Berhasil dihapus!', 'warning');

        return redirect()->route('payment-terms.index');
    }
}
