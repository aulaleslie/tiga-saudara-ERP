<?php

namespace Modules\Setting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Setting\Entities\Location;

class LocationController extends Controller
{
    /**
     * Display a listing of the locations.
     */
    public function index(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        $currentSettingId = session('setting_id');
        $locations = Location::where('setting_id', $currentSettingId)->get();

        return view('setting::locations.index', [
            'locations' => $locations
        ]);
    }

    /**
     * Show the form for creating a new location.
     */
    public function create(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('setting::locations.create');
    }

    /**
     * Store a newly created location in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        Location::create([
            'name' => $request->name,
            'setting_id' => session('setting_id'),  // Get setting_id from session
        ]);

        toast('Lokasi Berhasil ditambahkan!', 'success');

        return redirect()->route('locations.index');
    }

    /**
     * Show the form for editing the specified location.
     */
    public function edit(Location $location): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('setting::locations.edit', [
            'location' => $location
        ]);
    }

    /**
     * Update the specified location in storage.
     */
    public function update(Request $request, Location $location): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $location->update([
            'name' => $request->name,
        ]);

        toast('Lokasi diperbaharui!', 'info');

        return redirect()->route('locations.index');
    }

    /**
     * Remove the specified location from storage.
     */
    public function destroy(Location $location): RedirectResponse
    {
        // TODO hapus lokasi hanya dapat dilakukan jika tidak ada produk tersisa di dalam lokasi ini
        $location->delete();

        toast('Lokasi Berhasil dihapus!', 'warning');

        return redirect()->route('locations.index');
    }
}
