<?php

namespace Modules\Setting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class Location extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    protected $guarded = [];

    /**
     * Get the setting (business) that owns the location.
     */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }
}
