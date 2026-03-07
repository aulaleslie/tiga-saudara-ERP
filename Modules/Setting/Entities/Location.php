<?php

namespace Modules\Setting\Entities;

use App\Models\BaseModel;
use App\Support\SalesLocationResolver;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Setting; // Added this import for Setting::query()

use Illuminate\Database\Eloquent\Factories\HasFactory;

class Location extends BaseModel
{
    use HasFactory;

    protected static function newFactory()
    {
        return \Database\Factories\LocationFactory::new();
    }
    
    protected $guarded = [];

    protected static function booted(): void
    {
        static::created(function (Location $location) {
            $settings = Setting::query()->pluck('id');
            $now = now();

            $maxPositions = SettingSaleLocation::query()
                ->selectRaw('setting_id, MAX(position) as max_pos')
                ->groupBy('setting_id')
                ->pluck('max_pos', 'setting_id');

            $chunks = $settings->chunk(500);
            foreach ($chunks as $chunk) {
                $payload = $chunk->map(fn ($settingId) => [
                    'setting_id'  => $settingId,
                    'location_id' => $location->id,
                    'is_enabled'  => true,
                    'position'    => ($maxPositions[$settingId] ?? 0) + 1,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ])->all();

                SettingSaleLocation::insertOrIgnore($payload);
            }

            SalesLocationResolver::forget();
        });

        static::updated(function (Location $location) {
            if ($location->wasChanged('setting_id')) {
                $originalSettingId = $location->getOriginal('setting_id');

                $location->saleAssignments()->updateOrCreate(
                    ['setting_id' => $location->setting_id],
                    ['is_enabled' => true]
                );

                SalesLocationResolver::forget($location->setting_id, $originalSettingId);
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

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Setting::class, 'setting_sale_locations')
            ->withPivot(['is_enabled'])
            ->withTimestamps();
    }

    public function saleAssignments(): HasMany
    {
        return $this->hasMany(SettingSaleLocation::class);
    }
}
