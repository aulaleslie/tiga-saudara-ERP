<?php

namespace Modules\Sale\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispatchDetail extends Model
{
    protected $fillable = [
        'dispatch_id',
        'sale_id',
        'product_id',
        'dispatched_quantity',
        'location_id',
        'serial_numbers',
        'tax_id',
    ];

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }
}
