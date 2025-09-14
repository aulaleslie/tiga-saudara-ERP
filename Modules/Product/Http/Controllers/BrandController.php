<?php

namespace Modules\Product\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Product\DataTables\BrandDataTable;
use Modules\Product\Entities\Brand;

class BrandController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(BrandDataTable $dataTable)
    {
        abort_if(Gate::denies('brands.access'), 403);
        return $dataTable->render('product::brands.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('brands.create'), 403);
        return view('product::brands.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('brands.create'), 403);
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $currentSettingId = session('setting_id');

        Brand::create([
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'setting_id' => $currentSettingId,
            'created_by' => auth()->id(),
        ]);

        toast('Merek Ditambahkan!', 'success');

        return redirect()->route('brands.index');
    }

    /**
     * Show the specified resource.
     */
    public function show($id): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('brands.view'), 403);
        $brand = Brand::findOrFail($id);
        return view('product::brands.show', compact('brand'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('brands.edit'), 403);
        $brand = Brand::findOrFail($id);
        return view('product::brands.edit', compact('brand'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Brand $brand): RedirectResponse
    {
        abort_if(Gate::denies('brands.edit'), 403);
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $brand->update([
            'name' => $request->input('name'),
            'description' => $request->input('description'),
        ]);

        toast('Merek Telah Berhasil Diubah!', 'info');

        return redirect()->route('brands.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Brand $brand): RedirectResponse
    {
        abort_if(Gate::denies('brands.delete'), 403);
        $brand->delete();

        // Toast a success message
        toast('Merek Telah Dihapus!', 'success');

        // Redirect to the settings index page
        return redirect()->route('brands.index');
    }
}
