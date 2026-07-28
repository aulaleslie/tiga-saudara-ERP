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

        // Repair missing owned location assignments before querying
        SettingSaleLocation::repairMissingOwnedAssignments($currentSettingId);

        // Active collection: enabled assignments for the current setting, ordered by position
        $activeLocations = Location::query()
            ->with(['setting:id,company_name'])
            ->join('setting_sale_locations', function ($join) use ($currentSettingId) {
                $join->on('locations.id', '=', 'setting_sale_locations.location_id')
                    ->where('setting_sale_locations.setting_id', '=', $currentSettingId)
                    ->where('setting_sale_locations.is_enabled', true);
            })
            ->select([
                'locations.*',
                'setting_sale_locations.position'
            ])
            ->orderBy('setting_sale_locations.position')
            ->get()
            ->map(function ($location) use ($currentSettingId) {
                $location->is_owned = $location->setting_id === $currentSettingId;
                $location->is_enabled = true;
                return $location;
            });

        // Available collection: disabled or unassigned locations (foreign only)
        $availableLocations = Location::query()
            ->with(['setting:id,company_name'])
            ->where('locations.setting_id', '!=', $currentSettingId)
            ->leftJoin('setting_sale_locations', function ($join) use ($currentSettingId) {
                $join->on('locations.id', '=', 'setting_sale_locations.location_id')
                    ->where('setting_sale_locations.setting_id', '=', $currentSettingId);
            })
            ->select([
                'locations.*',
                'setting_sale_locations.is_enabled'
            ])
            ->where(function ($query) use ($currentSettingId) {
                $query->whereNull('setting_sale_locations.id')
                    ->orWhere('setting_sale_locations.is_enabled', false);
            })
            ->orderBy('locations.name')
            ->orderBy('locations.id')
            ->get()
            ->map(function ($location) use ($currentSettingId) {
                $location->is_owned = false;
                $location->is_enabled = false;
                return $location;
            });

        return view('setting::sale-locations.index', [
            'setting'   => $setting,
            'activeLocations' => $activeLocations,
            'availableLocations' => $availableLocations,
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

        DB::transaction(function () use ($currentSettingId, $locationId, $validated) {
            $assignment = SettingSaleLocation::where('setting_id', $currentSettingId)
                ->where('location_id', $locationId)
                ->first();

            if ($validated['is_enabled']) {
                // Enabling: create new or update existing
                if (!$assignment) {
                    // New assignment: create will trigger the creating hook
                    SettingSaleLocation::create([
                        'setting_id' => $currentSettingId,
                        'location_id' => $locationId,
                        'is_enabled' => true,
                    ]);
                } else {
                    // Re-enabling: append new position, clear old position first
                    $maxPosition = SettingSaleLocation::where('setting_id', $currentSettingId)
                        ->where('is_enabled', true)
                        ->max('position');
                    $assignment->update([
                        'is_enabled' => true,
                        'position' => ($maxPosition ?? 0) + 1,
                    ]);
                }
            } else {
                // Disabling: set is_enabled to false and clear position
                if ($assignment) {
                    $assignment->update([
                        'is_enabled' => false,
                        'position' => null,
                    ]);
                }
            }
        });

        $statusMsg = $validated['is_enabled'] ? 'diaktifkan' : 'dinonaktifkan';
        toast("Lokasi berhasil $statusMsg.", 'success');

        return redirect()->route('sales-location-configurations.index');
    }

    public function order(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('saleLocations.edit'), 403);

        $validated = $request->validate([
            'location_ids' => 'required|array',
            'location_ids.*' => 'integer',
        ]);

        $currentSettingId = (int) session('setting_id');
        $locationIds = $validated['location_ids'];

        // Get the exact set of enabled assignment IDs for the current setting
        $enabledLocationIds = SettingSaleLocation::where('setting_id', $currentSettingId)
            ->where('is_enabled', true)
            ->pluck('location_id')
            ->toArray();

        // Check for exact match: same count, same IDs, no duplicates
        $submittedIds = array_unique($locationIds);
        if (count($locationIds) !== count($submittedIds)) {
            toast('Urutan lokasi tidak valid atau tidak lengkap.', 'error');
            return redirect()->route('sales-location-configurations.index');
        }

        if (count($locationIds) !== count($enabledLocationIds) || count(array_diff($locationIds, $enabledLocationIds)) > 0) {
            toast('Urutan lokasi tidak valid atau tidak lengkap.', 'error');
            return redirect()->route('sales-location-configurations.index');
        }

        DB::transaction(function () use ($currentSettingId, $locationIds) {
            foreach ($locationIds as $index => $locationId) {
                SettingSaleLocation::where('setting_id', $currentSettingId)
                    ->where('location_id', $locationId)
                    ->update(['position' => $index + 1]);
            }
        });

        SalesLocationResolver::forget($currentSettingId);

        toast('Urutan prioritas lokasi berhasil disimpan.', 'success');

        return redirect()->route('sales-location-configurations.index');
    }
}
