<?php

namespace Modules\Sale\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispatchDetail extends Model
{
    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }
}
