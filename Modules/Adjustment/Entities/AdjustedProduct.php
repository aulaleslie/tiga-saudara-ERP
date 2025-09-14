<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use Modules\Product\Entities\Product;

class AdjustedProduct extends BaseModel
{
    protected $guarded = [];

    protected $with = ['product'];

    public function adjustment() {
        return $this->belongsTo(Adjustment::class, 'adjustment_id', 'id');
    }

    public function product() {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
}
