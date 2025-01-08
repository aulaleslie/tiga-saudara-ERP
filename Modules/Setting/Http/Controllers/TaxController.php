<?php

namespace Modules\Setting\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Setting\Entities\Tax;

class TaxController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Factory|Application|View|\Illuminate\Contracts\Foundation\Application
     */
    public function index(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        $currentSettingId = session('setting_id');
        $taxes = Tax::where('setting_id', $currentSettingId)->get();

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
        return view('setting::taxes.create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:taxes,name,NULL,id,setting_id,' . session('setting_id'),
            'value' => 'required|numeric|gt:0|lte:100',
        ]);

        Tax::create([
            'name' => $request->name,
            'value' => $request->value,
            'setting_id' => session('setting_id'),  // Get setting_id from session
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
        $request->validate([
            'name' => 'required|string|max:255|unique:taxes,name,' . $tax->id . ',id,setting_id,' . session('setting_id'),
            'value' => 'required|numeric|gt:0|lte:100',
        ]);

        $tax->update([
            'name' => $request->name,
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
        $tax->delete();

        toast('Pajak Berhasil dihapus!', 'warning');

        return redirect()->route('taxes.index');
    }
}
