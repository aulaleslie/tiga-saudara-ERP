<?php

namespace Modules\Product\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductBundle extends BaseModel
{
    protected $guarded = [];

    protected $attributes = [
        'is_active' => true,
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'active_from' => 'date',
        'active_to' => 'date',
    ];

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

    /**
     * The setting that this bundle belongs to.
     */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(\Modules\Setting\Entities\Setting::class);
    }
}
