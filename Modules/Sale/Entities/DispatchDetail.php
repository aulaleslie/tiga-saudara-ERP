<?php

namespace Modules\Sale\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;

class DispatchDetail extends BaseModel
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

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
