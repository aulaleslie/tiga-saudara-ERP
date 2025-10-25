<?php

namespace Modules\Setting\Entities;

use App\Models\BaseModel;
use App\Support\PosLocationResolver;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettingSaleLocation extends BaseModel
{
    protected $fillable = [
        'setting_id',
        'location_id',
        'is_pos',
    ];

    protected $casts = [
        'is_pos' => 'bool',
    ];

    protected $table = 'setting_sale_locations';

    protected static function booted(): void
    {
        static::created(function (SettingSaleLocation $assignment) {
            PosLocationResolver::forget($assignment->setting_id);
        });

        static::updated(function (SettingSaleLocation $assignment) {
            if (
                $assignment->wasChanged('setting_id') ||
                $assignment->wasChanged('location_id') ||
                $assignment->wasChanged('is_pos')
            ) {
                $settingIds = collect([
                    $assignment->setting_id,
                    $assignment->getOriginal('setting_id'),
                ])->filter()->unique()->all();

                if (!empty($settingIds)) {
                    PosLocationResolver::forget(...$settingIds);
                }
            }
        });

        static::deleted(function (SettingSaleLocation $assignment) {
            $settingIds = collect([
                $assignment->getOriginal('setting_id'),
                $assignment->setting_id,
            ])->filter()->unique()->all();

            if (!empty($settingIds)) {
                PosLocationResolver::forget(...$settingIds);
            }
        });

        if (method_exists(static::class, 'forceDeleted')) {
            static::forceDeleted(function (SettingSaleLocation $assignment) {
                $settingIds = collect([
                    $assignment->getOriginal('setting_id'),
                    $assignment->setting_id,
                ])->filter()->unique()->all();

                if (!empty($settingIds)) {
                    PosLocationResolver::forget(...$settingIds);
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
}
