<?php

namespace Modules\Setting\Entities;

use App\Models\BaseModel;

class Tax extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
    ];

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
