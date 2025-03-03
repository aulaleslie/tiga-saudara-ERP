<?php

namespace Modules\Product\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBundleItem extends Model
{
    protected $guarded = [];

    /**
     * The bundle header this item belongs to.
     */
    public function bundle(): BelongsTo
    {
        return $this->belongsTo(ProductBundle::class, 'bundle_id');
    }

    /**
     * The product that is part of this bundle.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
