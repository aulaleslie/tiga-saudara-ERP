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
        $assignmentId = session('pos_location_assignment_id');

        if ($assignmentId) {
            $assignment = SettingSaleLocation::query()
                ->select(['id', 'setting_id', 'location_id'])
                ->find($assignmentId);

            if ($assignment) {
                $settingId = $assignment->setting_id;
            }
        }

        $settingId ??= session('setting_id');

        if (!$settingId) {
            return collect();
        }

        $cacheKey = "pos_location_ids_{$settingId}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($settingId) {
            return SettingSaleLocation::query()
                ->where('setting_id', $settingId)
                ->where('is_pos', true)
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('location_id')
                ->map(static fn ($locationId) => (int) $locationId)
                ->values();
        });
    }

    /**
     * Resolve the primary POS location id (first configured location).
     */
    public static function resolveId(?int $settingId = null): ?int
    {
        return static::resolveLocationIds($settingId)->first();
    }

    /**
     * Resolve all tenant assignments for a given POS location.
     */
    public static function resolveTenantsForLocation(int $locationId): Collection
    {
        return SettingSaleLocation::query()
            ->with('setting:id,company_name')
            ->forLocation($locationId)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * Persist the selected tenant-location assignment into the session.
     */
    public static function setActiveAssignment(?int $assignmentId): void
    {
        if (!$assignmentId) {
            session()->forget('pos_location_assignment_id');

            return;
        }

        $assignment = SettingSaleLocation::query()
            ->select(['id', 'setting_id'])
            ->find($assignmentId);

        if (! $assignment) {
            return;
        }

        session([
            'pos_location_assignment_id' => $assignment->id,
            'setting_id' => $assignment->setting_id,
        ]);
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
