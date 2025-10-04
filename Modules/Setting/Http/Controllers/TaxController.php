<?php

namespace Modules\Setting\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Modules\Setting\Entities\Tax;

class TaxController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Factory|Application|View|\Illuminate\Contracts\Foundation\Application
     */
    public function index(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('taxes.access'), 403);
        $taxes = Tax::all();

        return view('setting::taxes.index', [
            'taxes' => $taxes
        ]);
    }

    /**
     * Show the form for creating a new resource.
     * @return Factory|Application|View|\Illuminate\Contracts\Foundation\Application
     */
    public function create(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('taxes.create'), 403);
        return view('setting::taxes.create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('taxes.create'), 403);

        // Normalize text inputs first (so validation sees canonical values)
        $request->merge([
            'name' => mb_strtoupper(trim((string) $request->input('name')), 'UTF-8'),
        ]);

        $request->validate([
            'name'  => 'required|string|max:255|unique:taxes,name',
            'value' => 'required|numeric|gt:0|lte:100',
        ]);

        Tax::create([
            'name'       => $request->name,         // already uppercased
            'value'      => $request->value,
        ]);

        toast('Pajak Berhasil ditambahkan!', 'success');
        return redirect()->route('taxes.index');
    }

    /**
     * Show the specified resource.
     * @param Tax $tax
     * @return Factory|Application|View|\Illuminate\Contracts\Foundation\Application
     */
    public function edit(Tax $tax): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('taxes.edit'), 403);
        return view('setting::taxes.edit', [
            'tax' => $tax
        ]);
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param Tax $tax
     * @return RedirectResponse
     */
    public function update(Request $request, Tax $tax): RedirectResponse
    {
        abort_if(Gate::denies('taxes.edit'), 403);

        $request->merge([
            'name' => mb_strtoupper(trim((string) $request->input('name')), 'UTF-8'),
        ]);

        $request->validate([
            'name'  => 'required|string|max:255|unique:taxes,name,' . $tax->id,
            'value' => 'required|numeric|gt:0|lte:100',
        ]);

        $tax->update([
            'name'  => $request->name,   // already uppercased
            'value' => $request->value,
        ]);

        toast('Pajak diperbaharui!', 'info');
        return redirect()->route('taxes.index');
    }

    /**
     * Remove the specified resource from storage.
     * @param Tax $tax
     * @return RedirectResponse
     */
    public function destroy(Tax $tax): RedirectResponse
    {
        abort_if(Gate::denies('taxes.delete'), 403);
        $tax->delete();

        toast('Pajak Berhasil dihapus!', 'warning');

        return redirect()->route('taxes.index');
    }
}
