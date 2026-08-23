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

class LocationController extends Controller
{
    /**
     * Display a listing of the locations.
     */
    public function index(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('locations.access'), 403);
        $currentSettingId = session('setting_id');
        $query = Location::with(['setting:id,company_name'])
            ->where('setting_id', $currentSettingId);

        if (request()->filled('status')) {
            $status = request('status');
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $locations = $query->get();

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
        return view('setting::locations.create');
    }

    /**
     * Store a newly created location in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('locations.create'), 403);

        $request->validate([
            'name' => 'required|string|max:255|unique:locations,name,NULL,id,setting_id,' . session('setting_id'),
            'is_consignment' => 'nullable|boolean',
        ]);

        $settingId = session('setting_id');

        $location = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $settingId) {
            return Location::create([
                'name'           => $request->name,
                'setting_id'     => $settingId,
                'is_consignment' => $request->boolean('is_consignment'),
            ]);
        });

        toast('Lokasi Berhasil ditambahkan!', 'success');

        return redirect()->route('locations.index');
    }

    /**
     * Show the form for editing the specified location.
     */
    public function edit(Location $location): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('locations.edit'), 403);
        abort_if($location->setting_id !== session('setting_id'), 403);

        return view('setting::locations.edit', [
            'location' => $location,
        ]);
    }

    /**
     * Update the specified location in storage.
     */
    public function update(Request $request, Location $location): RedirectResponse
    {
        abort_if(Gate::denies('locations.edit'), 403);
        abort_if($location->setting_id !== session('setting_id'), 403);

        $request->validate([
            'name' => 'required|string|max:255|unique:locations,name,' . $location->id . ',id,setting_id,' . session('setting_id'),
            'is_consignment' => 'nullable|boolean',
        ]);

        $newIsConsignment = $request->boolean('is_consignment');

        if ($location->is_consignment !== $newIsConsignment) {
            // Guard: check for any active stock in location
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
                return redirect()->back()
                    ->withInput()
                    ->withErrors(['is_consignment' => 'Klasifikasi lokasi tidak dapat diubah karena lokasi masih memiliki stok aktif.']);
            }
        }

        $location->update([
            'name' => $request->name,
            'is_consignment' => $newIsConsignment,
        ]);

        toast('Lokasi diperbaharui!', 'info');

        return redirect()->route('locations.index');
    }

    public function toggleStatus(Location $location, \App\Services\MasterDataLifecycleService $lifecycleService): RedirectResponse
    {
        abort_if(Gate::denies('locations.edit'), 403);
        abort_if($location->setting_id !== session('setting_id'), 403);

        try {
            if ($location->is_active) {
                $lifecycleService->deactivate($location);
                toast('Lokasi berhasil dinonaktifkan!', 'info');
            } else {
                $lifecycleService->reactivate($location);
                toast('Lokasi berhasil diaktifkan kembali!', 'success');
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            toast($e->getMessage(), 'error');
        }

        return redirect()->back();
    }

    /**
     * Remove the specified location from storage.
     */
    public function destroy(Location $location, \App\Services\MasterDataLifecycleService $lifecycleService): RedirectResponse
    {
        abort_if(Gate::denies('locations.edit'), 403);
        abort_if($location->setting_id !== session('setting_id'), 403);

        try {
            $lifecycleService->deactivate($location);
            toast('Lokasi berhasil dinonaktifkan!', 'info');
        } catch (\Illuminate\Validation\ValidationException $e) {
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('locations.index');
    }
}
