<?php

namespace Modules\Product\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductBundle extends Model
{
    protected $guarded = [];

    /**
     * The parent product for this bundle.
     */
    public function parentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_product_id');
    }

    /**
     * The items (products) included in this bundle.
     */
    public function items(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class, 'bundle_id');
    }
}
