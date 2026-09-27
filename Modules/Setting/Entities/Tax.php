<?php

namespace Modules\Setting\Entities;

use App\Models\BaseModel;

class Tax extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeEligible($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    protected static function booted(): void
    {
        static::saved(function (self $tax): void {
            if (! $tax->is_default) {
                return;
            }

            static::query()
                ->whereKeyNot($tax->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        });
    }
}
