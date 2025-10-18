<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Modules\Setting\Entities\SettingSaleLocation;

class PosLocationResolver
{
    /**
     * Resolve the POS-enabled location ID for the current setting context.
     */
    public static function resolveId(?int $settingId = null): ?int
    {
        $settingId ??= session('setting_id');

        if (!$settingId) {
            return null;
        }

        $cacheKey = "pos_location_id_{$settingId}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($settingId) {
            return SettingSaleLocation::query()
                ->where('setting_id', $settingId)
                ->whereHas('location', fn($query) => $query->where('is_pos', true))
                ->orderBy('id')
                ->pluck('location_id')
                ->first();
        });
    }

    public static function forget(?int ...$settingIds): void
    {
        collect($settingIds)
            ->filter()
            ->each(fn ($id) => Cache::forget("pos_location_id_{$id}"));
    }
}
