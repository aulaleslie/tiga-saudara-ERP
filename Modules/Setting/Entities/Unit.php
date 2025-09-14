<?php

namespace Modules\Setting\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductUnitConversion;

class Unit extends BaseModel
{
    protected $guarded = [];

    /**
     * Relationship with the Product model as the primary unit.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'unit_id');
    }

    /**
     * Relationship with the Product model as the base unit for conversions.
     */
    public function baseProducts(): HasMany
    {
        return $this->hasMany(Product::class, 'base_unit_id');
    }

    /**
     * Relationship with the ProductUnitConversion model for conversions involving this unit.
     */
    public function conversions(): HasMany
    {
        return $this->hasMany(ProductUnitConversion::class, 'unit_id');
    }

    /**
     * Relationship with the ProductUnitConversion model for conversions where this unit is the base.
     */
    public function baseConversions(): HasMany
    {
        return $this->hasMany(ProductUnitConversion::class, 'base_unit_id');
    }

    /**
     * Relationship with the Setting model.
     */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }
}
