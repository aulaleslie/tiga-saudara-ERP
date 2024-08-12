<?php

namespace Modules\Setting\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Setting\Entities\Unit;

class UnitsController extends Controller
{
    public function index(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        $currentSettingId = session('setting_id'); // Get the current setting ID from the session

        // Retrieve only units associated with the current setting ID
        $units = Unit::where('setting_id', $currentSettingId)->get();

        return view('setting::units.index', [
            'units' => $units
        ]);
    }

    public function create(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('setting::units.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'short_name' => 'required|string|max:255',
        ]);

        $currentSettingId = session('setting_id'); // Get setting ID from session

        Unit::create([
            'name' => $request->name,
            'short_name' => $request->short_name,
            'setting_id' => $currentSettingId, // Assign setting ID from session
        ]);

        toast('Unit Created!', 'success');

        return redirect()->route('units.index');
    }

    public function edit(Unit $unit): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('setting::units.edit', [
            'unit' => $unit
        ]);
    }

    public function update(Request $request, Unit $unit): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'short_name' => 'required|string|max:255',
        ]);

        $currentSettingId = session('setting_id'); // Get setting ID from session

        $unit->update([
            'name' => $request->name,
            'short_name' => $request->short_name,
            'setting_id' => $currentSettingId, // Update setting ID from session
        ]);

        toast('Unit Updated!', 'info');

        return redirect()->route('units.index');
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        // Check if the unit is associated with any products
        if ($unit->products()->exists() || $unit->baseProducts()->exists()) {
            return redirect()->route('units.index')->withErrors('Cannot delete this unit because it is associated with one or more products.');
        }

        $unit->delete();

        toast('Unit Deleted!', 'warning');

        return redirect()->route('units.index');
    }
}
