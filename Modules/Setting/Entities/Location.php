<?php

namespace Modules\Setting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Location extends Model
{
    protected $guarded = [];

    /**
     * Get the setting (business) that owns the location.
     */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }
}
