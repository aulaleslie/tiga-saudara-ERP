<?php

namespace Modules\Setting\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

class LocationController extends Controller
{
    /**
     * Display a listing of the locations.
     */
    public function index(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('locations.access'), 403);
        $currentSettingId = session('setting_id');
        $locations = Location::with(['setting:id,company_name', 'saleAssignment'])
            ->where('setting_id', $currentSettingId)
            ->get();

        return view('setting::locations.index', [
            'locations' => $locations
        ]);
    }

    /**
     * Show the form for creating a new location.
     */
    public function create(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('locations.create'), 403);
        $defaultCashThreshold = optional(Setting::find(session('setting_id')))->pos_default_cash_threshold;

        return view('setting::locations.create', [
            'defaultCashThreshold' => $defaultCashThreshold,
        ]);
    }

    /**
     * Store a newly created location in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('locations.create'), 403);

        $request->validate([
            'name' => 'required|string|max:255',
            'pos_cash_threshold' => 'nullable|numeric|min:0',
        ]);

        $settingId = session('setting_id');

        $cashThreshold = $request->filled('pos_cash_threshold')
            ? round((float) $request->pos_cash_threshold, 2)
            : null;

        $location = Location::create([
            'name'       => $request->name,
            'setting_id' => $settingId,
            'pos_cash_threshold' => $cashThreshold,
        ]);

        toast('Lokasi Berhasil ditambahkan!', 'success');

        return redirect()->route('locations.index');
    }

    /**
     * Show the form for editing the specified location.
     */
    public function edit(Location $location): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('locations.edit'), 403);
        $location->loadMissing('setting');

        return view('setting::locations.edit', [
            'location' => $location,
            'defaultCashThreshold' => optional($location->setting)->pos_default_cash_threshold,
        ]);
    }

    /**
     * Update the specified location in storage.
     */
    public function update(Request $request, Location $location): RedirectResponse
    {
        abort_if(Gate::denies('locations.edit'), 403);

        $request->validate([
            'name' => 'required|string|max:255',
            'pos_cash_threshold' => 'nullable|numeric|min:0',
        ]);

        $cashThreshold = $request->filled('pos_cash_threshold')
            ? round((float) $request->pos_cash_threshold, 2)
            : null;

        $location->update([
            'name' => $request->name,
            'pos_cash_threshold' => $cashThreshold,
        ]);

        toast('Lokasi diperbaharui!', 'info');

        return redirect()->route('locations.index');
    }

    /**
     * Remove the specified location from storage.
     */
    public function destroy(Location $location): RedirectResponse
    {
        abort_if(Gate::denies('locations.edit'), 403);

        $hasStock = ProductStock::where('location_id', $location->id)
            ->where(function ($q) {
                $q->where('quantity', '>', 0)
                    ->orWhere('broken_quantity', '>', 0)
                    ->orWhere('quantity_tax', '>', 0)
                    ->orWhere('quantity_non_tax', '>', 0)
                    ->orWhere('broken_quantity_tax', '>', 0)
                    ->orWhere('broken_quantity_non_tax', '>', 0);
            })
            ->exists();

        if ($hasStock) {
            toast('Lokasi tidak bisa dihapus: masih ada stok di lokasi ini.', 'error');
            return redirect()->route('locations.index');
        }

        $location->delete();

        toast('Lokasi Berhasil dihapus!', 'warning');

        return redirect()->route('locations.index');
    }
}
