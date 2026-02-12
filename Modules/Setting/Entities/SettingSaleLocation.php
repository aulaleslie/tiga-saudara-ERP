<?php

namespace Modules\Setting\Entities;

use App\Models\BaseModel;
use App\Support\SalesLocationResolver;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SettingSaleLocation extends BaseModel
{
    protected $fillable = [
        'setting_id',
        'location_id',
        'position',
    ];

    protected $casts = [

        'position' => 'int',
    ];

    protected $table = 'setting_sale_locations';

    protected static function booted(): void
    {
        static::creating(function (SettingSaleLocation $assignment) {
            if (!is_null($assignment->position)) {
                return;
            }

            $settingId = $assignment->setting_id;

            if (!$settingId) {
                $assignment->position = 1;

                return;
            }

            $maxPosition = static::query()
                ->where('setting_id', $settingId)
                ->max('position');

            $assignment->position = ($maxPosition ?? 0) + 1;
        });

        static::created(function (SettingSaleLocation $assignment) {
            SalesLocationResolver::forget($assignment->setting_id);
        });

        static::updated(function (SettingSaleLocation $assignment) {
            if (
                $assignment->wasChanged('setting_id') ||
                $assignment->wasChanged('location_id') ||

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
}
