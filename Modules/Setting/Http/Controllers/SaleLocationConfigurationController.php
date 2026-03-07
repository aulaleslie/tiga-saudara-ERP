<?php

namespace Modules\Setting\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Support\SalesLocationResolver;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
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
        $setting = Setting::findOrFail($currentSettingId);

        // Load all locations, join with their enable/disable status for the current setting
        $locations = Location::query()
            ->with(['setting:id,company_name'])
            ->leftJoin('setting_sale_locations', function ($join) use ($currentSettingId) {
                $join->on('locations.id', '=', 'setting_sale_locations.location_id')
                    ->where('setting_sale_locations.setting_id', '=', $currentSettingId);
            })
            ->select([
                'locations.*',
                'setting_sale_locations.is_enabled'
            ])
            ->orderByRaw('CASE WHEN locations.setting_id = ? THEN 0 ELSE 1 END', [$currentSettingId])
            ->orderBy('locations.name')
            ->orderBy('locations.id')
            ->get()
            ->map(function ($location) use ($currentSettingId) {
                $location->is_owned = $location->setting_id === $currentSettingId;
                // If it's an owned location, it's always enabled. Otherwise use the pivot table value, or false if not yet backed.
                if ($location->is_owned) {
                    $location->is_enabled = true;
                } else {
                    $location->is_enabled = (bool) $location->is_enabled;
                }
                return $location;
            });

        return view('setting::sale-locations.index', [
            'setting'   => $setting,
            'locations' => $locations,
        ]);
    }

    public function toggle(Request $request, int $locationId): RedirectResponse
    {
        abort_if(Gate::denies('saleLocations.edit'), 403);

        $validated = $request->validate([
            'is_enabled' => 'required|boolean',
        ]);

        $currentSettingId = (int) session('setting_id');
        
        $location = Location::findOrFail($locationId);

        if ($location->setting_id === $currentSettingId && !$validated['is_enabled']) {
            toast('Lokasi milik bisnis tidak dapat dinonaktifkan.', 'warning');
            return redirect()->route('sales-location-configurations.index');
        }

        SettingSaleLocation::updateOrCreate(
            [
                'setting_id' => $currentSettingId,
                'location_id' => $locationId,
            ],
            [
                'is_enabled' => $validated['is_enabled'],
            ]
        );

        $statusMsg = $validated['is_enabled'] ? 'diaktifkan' : 'dinonaktifkan';
        toast("Lokasi berhasil $statusMsg.", 'success');

        return redirect()->route('sales-location-configurations.index');
    }
}
