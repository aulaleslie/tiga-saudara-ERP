<?php

namespace Modules\Setting\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Setting\Entities\Unit;

class UnitsController extends Controller
{
    public function index(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('units.access'), 403);

        $query = Unit::query();
        if (request()->filled('status')) {
            $status = request('status');
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $units = $query->get();

        return view('setting::units.index', [
            'units' => $units
        ]);
    }

    public function create(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('units.create'), 403);
        return view('setting::units.create');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('units.create'), 403);
        $request->validate([
            'name' => 'required|string|max:255|unique:units,name,NULL,id,setting_id,' . session('setting_id'),
            'short_name' => 'required|string|max:255|unique:units,short_name,NULL,id,setting_id,' . session('setting_id'),
        ]);

        $currentSettingId = session('setting_id'); // Get setting ID from session

        Unit::create([
            'name' => $request->name,
            'short_name' => $request->short_name,
            'setting_id' => $currentSettingId, // Assign setting ID from session
        ]);

        toast('Unit Ditambahkan!', 'success');

        return redirect()->route('units.index');
    }

    public function edit(Unit $unit): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('units.edit'), 403);
        return view('setting::units.edit', [
            'unit' => $unit
        ]);
    }

    public function update(Request $request, Unit $unit): RedirectResponse
    {
        abort_if(Gate::denies('units.edit'), 403);
        $request->validate([
            'name' => 'required|string|max:255|unique:units,name,' . $unit->id . ',id,setting_id,' . session('setting_id'),
            'short_name' => 'required|string|max:255|unique:units,short_name,' . $unit->id . ',id,setting_id,' . session('setting_id'),
        ]);

        $currentSettingId = session('setting_id'); // Get setting ID from session

        $unit->update([
            'name' => $request->name,
            'short_name' => $request->short_name,
            'setting_id' => $currentSettingId, // Update setting ID from session
        ]);

        toast('Unit diperbaharui!', 'info');

        return redirect()->route('units.index');
    }

    public function toggleStatus(Unit $unit, \App\Services\MasterDataLifecycleService $lifecycleService): RedirectResponse
    {
        abort_if(! Gate::allows('units.edit') && ! Gate::allows('units.delete'), 403);

        try {
            if ($unit->is_active) {
                $lifecycleService->deactivate($unit);
                toast('Satuan berhasil dinonaktifkan!', 'info');
            } else {
                $lifecycleService->reactivate($unit);
                toast('Satuan berhasil diaktifkan kembali!', 'success');
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            toast($e->getMessage(), 'error');
        }

        return redirect()->back();
    }

    public function destroy(Unit $unit, \App\Services\MasterDataLifecycleService $lifecycleService): RedirectResponse
    {
        abort_if(! Gate::allows('units.edit') && ! Gate::allows('units.delete'), 403);

        try {
            $lifecycleService->deactivate($unit);
            toast('Satuan berhasil dinonaktifkan!', 'info');
        } catch (\Illuminate\Validation\ValidationException $e) {
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('units.index');
    }
}
