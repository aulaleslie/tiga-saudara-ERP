<?php

namespace Modules\Setting\Entities;

use App\Models\BaseModel;
use App\Support\SalesLocationResolver;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class SettingSaleLocation extends BaseModel
{
    protected $fillable = [
        'setting_id',
        'location_id',
        'is_enabled',
        'position',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'position' => 'integer',
    ];

    protected $table = 'setting_sale_locations';

    protected static function booted(): void
    {
        static::creating(function (SettingSaleLocation $assignment) {
            if (is_null($assignment->position) && $assignment->is_enabled) {
                $maxPosition = static::where('setting_id', $assignment->setting_id)
                    ->where('is_enabled', true)
                    ->max('position');
                $assignment->position = ($maxPosition ?? 0) + 1;
            }
        });
        static::created(function (SettingSaleLocation $assignment) {
            SalesLocationResolver::forget($assignment->setting_id);
        });

        static::updated(function (SettingSaleLocation $assignment) {
            if (
                $assignment->wasChanged('setting_id') ||
                $assignment->wasChanged('location_id') ||
                $assignment->wasChanged('is_enabled') ||
                $assignment->wasChanged('position')
            ) {
                $settingIds = collect([
                    $assignment->setting_id,
                    $assignment->getOriginal('setting_id'),
                ])->filter()->unique()->all();

                if (!empty($settingIds)) {
                    SalesLocationResolver::forget(...$settingIds);
                }
            }
        });

        static::deleted(function (SettingSaleLocation $assignment) {
            $settingIds = collect([
                $assignment->getOriginal('setting_id'),
                $assignment->setting_id,
            ])->filter()->unique()->all();

            if (!empty($settingIds)) {
                SalesLocationResolver::forget(...$settingIds);
            }
        });

        if (method_exists(static::class, 'forceDeleted')) {
            static::forceDeleted(function (SettingSaleLocation $assignment) {
                $settingIds = collect([
                    $assignment->getOriginal('setting_id'),
                    $assignment->setting_id,
                ])->filter()->unique()->all();

                if (!empty($settingIds)) {
                    SalesLocationResolver::forget(...$settingIds);
                }
            });
        }
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function scopeForSetting($query, ?int $settingId)
    {
        return $query->when($settingId, fn ($q) => $q->where('setting_id', $settingId));
    }

    public function scopeForLocation($query, ?int $locationId)
    {
        return $query->when($locationId, fn ($q) => $q->where('location_id', $locationId));
    }

    public static function repairMissingOwnedAssignments(int $settingId): void
    {
        $setting = Setting::findOrFail($settingId);

        $ownedLocationsWithoutAssignment = Location::where('setting_id', $settingId)
            ->whereNotExists(function ($query) use ($settingId) {
                $query->select('id')
                    ->from('setting_sale_locations')
                    ->where('setting_sale_locations.setting_id', $settingId)
                    ->where('setting_sale_locations.location_id', '=', \DB::raw('locations.id'))
                    ->where('setting_sale_locations.is_enabled', true);
            })
            ->get();

        DB::transaction(function () use ($settingId, $ownedLocationsWithoutAssignment) {
            foreach ($ownedLocationsWithoutAssignment as $location) {
                $assignment = static::where('setting_id', $settingId)
                    ->where('location_id', $location->id)
                    ->first();

                if (!$assignment) {
                    // Create new: triggers the creating hook for position assignment
                    static::create([
                        'setting_id' => $settingId,
                        'location_id' => $location->id,
                        'is_enabled' => true,
                    ]);
                } else {
                    // Re-enable existing: append new position
                    $maxPosition = static::where('setting_id', $settingId)
                        ->where('is_enabled', true)
                        ->max('position');
                    $assignment->update([
                        'is_enabled' => true,
                        'position' => ($maxPosition ?? 0) + 1,
                    ]);
                }
            }
        });
    }
}
