<?php

namespace Modules\Product\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Image\Exceptions\InvalidManipulation;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Product extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $guarded = [];

    protected $with = ['media'];

    /**
     * Relationship with the Category model.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id', 'id');
    }

    /**
     * Relationship with the Unit model as the primary unit.
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /**
     * Relationship with the Brand model as the primary unit.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    /**
     * Relationship with the Unit model as the base unit for conversions.
     */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /**
     * Relationship with the ProductUnitConversion model.
     */
    public function conversions(): HasMany
    {
        return $this->hasMany(ProductUnitConversion::class);
    }

    /**
     * Relationship with the Setting model.
     */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }

    /**
     * Register media collections for the product.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useFallbackUrl('/images/fallback_product_image.png');
    }

    /**
     * Register media conversions for the product.
     *
     * @throws InvalidManipulation
     */
    public function registerMediaConversions(Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(50)
            ->height(50);
    }

    /**
     * Mutator to set the product cost.
     */
    public function setProductCostAttribute($value): void
    {
        $this->attributes['product_cost'] = ($value * 100);
    }

    /**
     * Accessor to get the product cost.
     */
    public function getProductCostAttribute($value): float|int
    {
        return ($value / 100);
    }

    /**
     * Mutator to set the product price.
     */
    public function setProductPriceAttribute($value): void
    {
        $this->attributes['product_price'] = ($value * 100);
    }

    /**
     * Accessor to get the product price.
     */
    public function getProductPriceAttribute($value): float|int
    {
        return ($value / 100);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function serialNumbers(): HasMany
    {
        return $this->hasMany(ProductSerialNumber::class);
    }

    /**
     * Define the relationship with the Tax model for purchase tax.
     */
    public function purchaseTax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'purchase_tax_id');
    }

    /**
     * Define the relationship with the Tax model for sale tax.
     */
    public function saleTax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'sale_tax_id');
    }
}
