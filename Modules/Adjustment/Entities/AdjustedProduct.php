<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;

class AdjustedProduct extends BaseModel
{
    protected array $uppercaseExcept = [
        'serial_numbers',     // <- this field stays as typed
    ];

    protected $guarded = [];

    protected $with = ['product'];

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(Adjustment::class, 'adjustment_id', 'id');
    }

    public function product() {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
}
