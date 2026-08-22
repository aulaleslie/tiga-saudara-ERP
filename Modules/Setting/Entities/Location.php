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

    public function save(array $options = [])
    {
        return \Illuminate\Support\Facades\DB::transaction(fn () => parent::save($options));
    }

    protected static function newFactory()
    {
        return \Database\Factories\LocationFactory::new();
    }
    
    protected $guarded = [];

    protected $casts = [
        'is_consignment' => 'boolean',
    ];

    public function scopeConsignment($query)
    {
        return $query->where('is_consignment', true);
    }

    public function scopeStandard($query)
    {
        return $query->where('is_consignment', false);
    }

    protected static function booted(): void
    {
        static::created(function (Location $location) {
            $maxPosition = SettingSaleLocation::query()
                ->where('setting_id', $location->setting_id)
                ->where('is_enabled', true)
                ->max('position') ?? 0;

            SettingSaleLocation::create([
                'setting_id'  => $location->setting_id,
                'location_id' => $location->id,
                'is_enabled'  => true,
                'position'    => $maxPosition + 1,
            ]);
            // SettingSaleLocation::created event will naturally handle cache clearing.
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
