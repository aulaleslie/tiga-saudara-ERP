<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Modules\Setting\Entities\SettingSaleLocation;

class PosLocationResolver
{
    /**
     * Resolve all POS-enabled location IDs for the current setting context.
     */
    public static function resolveLocationIds(?int $settingId = null): Collection
    {
        $settingId ??= session('setting_id');

        if (!$settingId) {
            return collect();
        }

        $cacheKey = "pos_location_ids_{$settingId}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($settingId) {
            return SettingSaleLocation::query()
                ->where('setting_id', $settingId)
                ->whereHas('location', fn($query) => $query->where('is_pos', true))
                ->orderBy('id')
                ->pluck('location_id');
        });
    }

    /**
     * Resolve the primary POS location id (first configured location).
     */
    public static function resolveId(?int $settingId = null): ?int
    {
        return static::resolveLocationIds($settingId)->first();
    }

    public static function forget(?int ...$settingIds): void
    {
        collect($settingIds)
            ->filter()
            ->each(function ($id) {
                Cache::forget("pos_location_id_{$id}");
                Cache::forget("pos_location_ids_{$id}");
            });
    }
}
