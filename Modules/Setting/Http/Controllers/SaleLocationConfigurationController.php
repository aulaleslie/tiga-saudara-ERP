<?php

namespace Modules\Setting\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Support\PosLocationResolver;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;

class SaleLocationConfigurationController extends Controller
{
    public function index(): Factory|View|Application
    {
        abort_if(Gate::denies('saleLocations.access'), 403);

        $currentSettingId = (int) session('setting_id');
        $setting = Setting::with(['saleLocations.setting:id,company_name'])
            ->findOrFail($currentSettingId);

        $assignedLocations = $setting->saleLocations
            ->load('saleAssignment')
            ->sortBy('name')
            ->values();

        $availableLocations = Location::query()
            ->with(['setting:id,company_name', 'saleAssignment'])
            ->where('setting_id', '!=', $currentSettingId)
            ->where(function ($query) {
                $query->whereDoesntHave('saleAssignment')
                    ->orWhereHas('saleAssignment', function ($q) {
                        $q->whereColumn('setting_sale_locations.setting_id', 'locations.setting_id');
                    });
            })
            ->orderBy('name')
            ->get();

        return view('setting::sale-locations.index', [
            'setting'            => $setting,
            'assignedLocations'  => $assignedLocations,
            'availableLocations' => $availableLocations,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('saleLocations.edit'), 403);

        $validated = $request->validate([
            'location_id' => 'required|exists:locations,id',
        ]);

        $currentSettingId = (int) session('setting_id');
        $location = Location::with(['setting', 'saleAssignment'])
            ->findOrFail($validated['location_id']);

        $assignment = $location->saleAssignment;

        if ($assignment && $assignment->setting_id === $currentSettingId) {
            toast('Lokasi sudah dikonfigurasi untuk bisnis ini.', 'info');
            return redirect()->route('sales-location-configurations.index');
        }

        if ($assignment && $assignment->setting_id !== $location->setting_id) {
            toast('Lokasi sedang digunakan oleh konfigurasi bisnis lain.', 'error');
            return redirect()->route('sales-location-configurations.index');
        }

        $previousSettingId = $assignment?->setting_id;

        $location->saleAssignment()->updateOrCreate(
            ['location_id' => $location->id],
            ['setting_id' => $currentSettingId]
        );

        PosLocationResolver::forget($currentSettingId, $previousSettingId, $location->setting_id);

        toast('Lokasi berhasil ditambahkan ke konfigurasi penjualan.', 'success');

        return redirect()->route('sales-location-configurations.index');
    }

    public function destroy(int $locationId): RedirectResponse
    {
        abort_if(Gate::denies('saleLocations.edit'), 403);

        $currentSettingId = (int) session('setting_id');

        $assignment = SettingSaleLocation::with('location.setting')
            ->where('setting_id', $currentSettingId)
            ->where('location_id', $locationId)
            ->firstOrFail();

        $location = $assignment->location;
        $ownerId = $location?->setting_id;

        if (!$location || !$ownerId) {
            toast('Lokasi tidak valid.', 'error');
            return redirect()->route('sales-location-configurations.index');
        }

        if ($ownerId === $currentSettingId) {
            toast('Lokasi bawaan bisnis tidak dapat dihapus.', 'warning');
            return redirect()->route('sales-location-configurations.index');
        }

        $assignment->update(['setting_id' => $ownerId]);

        PosLocationResolver::forget($currentSettingId, $ownerId);
        toast('Lokasi dikembalikan ke bisnis asal.', 'success');

        return redirect()->route('sales-location-configurations.index');
    }
}
