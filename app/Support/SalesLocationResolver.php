<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Setting\Entities\SettingSaleLocation;

class SalesLocationResolver
{
    public static function resolveLocationIds(?int $settingId = null): Collection
    {
        $settingId ??= session('setting_id');

        if (! $settingId) {
            return collect();
        }

        $cacheKey = "sale_location_ids_{$settingId}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($settingId) {
            return SettingSaleLocation::query()
                ->join('locations', 'setting_sale_locations.location_id', '=', 'locations.id')
                ->where('setting_sale_locations.setting_id', $settingId)
                ->where('setting_sale_locations.is_enabled', true)
                ->orderByRaw('CASE WHEN locations.setting_id = setting_sale_locations.setting_id THEN 0 ELSE 1 END')
                ->orderBy('locations.name')
                ->orderBy('locations.id')
                ->pluck('setting_sale_locations.location_id')
                ->map(static fn ($locationId) => (int) $locationId)
                ->values();
        });
    }

    public static function resolveId(?int $settingId = null): ?int
    {
        return static::resolveLocationIds($settingId)->first();
    }

    public static function resolve(?int $settingId = null): ?\Modules\Setting\Entities\Location
    {
        $id = static::resolveId($settingId);

        return $id ? \Modules\Setting\Entities\Location::find($id) : null;
    }

    public static function resolveTenantsForLocation(int $locationId): Collection
    {
        return SettingSaleLocation::query()
            ->with('setting:id,company_name')
            ->forLocation($locationId)
            ->orderBy('is_enabled', 'desc')
            ->orderBy('id')
            ->get();
    }

    public static function forget(?int ...$settingIds): void
    {
        collect($settingIds)
            ->filter()
            ->each(function ($id) {
                Cache::forget("sale_location_ids_{$id}");
                // Backward compatibility cleanup for previous key names.
                Cache::forget("pos_location_id_{$id}");
                Cache::forget("pos_location_ids_{$id}");
            });
    }
}
