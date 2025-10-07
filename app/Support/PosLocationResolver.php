<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Modules\Setting\Entities\Location;

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
            return Location::query()
                ->where('setting_id', $settingId)
                ->where('is_pos', true)
                ->value('id');
        });
    }
}
