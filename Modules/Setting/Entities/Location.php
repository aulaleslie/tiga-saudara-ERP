<?php

namespace Modules\Setting\Entities;

use App\Models\BaseModel;
use App\Support\PosLocationResolver;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Setting\Entities\SettingSaleLocation;

class Location extends BaseModel
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::created(function (Location $location) {
            $location->saleAssignment()->updateOrCreate(
                ['location_id' => $location->id],
                ['setting_id' => $location->setting_id]
            );

            PosLocationResolver::forget($location->setting_id);
        });

        static::updated(function (Location $location) {
            if ($location->wasChanged('setting_id')) {
                $originalSettingId = $location->getOriginal('setting_id');
                $nextPosition = (int) SettingSaleLocation::query()
                    ->where('setting_id', $location->setting_id)
                    ->max('position');

                $location->saleAssignment()->updateOrCreate(
                    ['location_id' => $location->id],
                    [
                        'setting_id' => $location->setting_id,
                        'position'   => ($nextPosition ?: 0) + 1,
                    ]
                );

                PosLocationResolver::forget($location->setting_id, $originalSettingId);
            }

        });
    }

    /**
     * Get the setting (business) that owns the location.
     */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    public function saleAssignment(): HasOne
    {
        return $this->hasOne(SettingSaleLocation::class);
    }
}
