<?php

namespace Modules\Product\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Scout\Searchable;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Image\Exceptions\InvalidManipulation;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Product extends BaseModel implements HasMedia
{
    use InteractsWithMedia, Searchable;

    protected $guarded = [];

    protected $with = ['media', 'brand:id,name', 'category:id,category_name'];

    // (Scout requires an index name; we’ll override per-setting at query time)
    public function searchableAs(): string
    {
        return 'products';
    }

    public function toSearchableArray(): array
    {
        // Keep this small; relations should be eager-loaded during reindex
        return [
            'id'            => $this->id,
            'product_name'  => $this->product_name,
            'product_code'  => $this->product_code,
            'barcode'       => $this->barcode,
            'brand'         => optional($this->brand)->name,
            'brand_id'      => $this->brand_id,
            'category'      => optional($this->category)->category_name,
            'category_id'   => $this->category_id,
            // price_active will be injected at reindex time per-setting
        ];
    }

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

    public function bundles(): HasMany
    {
        return $this->hasMany(ProductBundle::class, 'parent_product_id');
    }

    public function bundledIn(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class, 'product_id');
    }

    /** All price rows for this product (across settings). */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /** Price row for this product’s own setting_id (unique per product × setting). */
    public function price(): HasOne
    {
        // Uses the product’s current setting_id
        return $this->hasOne(ProductPrice::class)->where('setting_id', $this->setting_id);
    }

    /** Fetch price row for a specific setting id. */
    public function priceForSetting(int $settingId)
    {
        // if already eager loaded, avoid an extra query
        if ($this->relationLoaded('prices')) {
            return $this->prices->firstWhere('setting_id', $settingId);
        }
        return $this->prices()->where('setting_id', $settingId)->first();
    }

    /** Internal: get the price row for $settingId (or current product setting). */
    protected function priceRow(?int $settingId = null)
    {
        $sid = $settingId ?? $this->setting_id;

        if ($sid === null) {
            return null;
        }

        if ($this->relationLoaded('prices')) {
            return $this->prices->firstWhere('setting_id', $sid);
        }
        return $this->prices()->where('setting_id', $sid)->first();
    }

    /** Get sale price (string like "123.45") from the tenant-specific price row. */
    public function salePrice(?int $settingId = null): ?string
    {
        $row = $this->priceRow($settingId);
        return $row?->sale_price;
    }

    /** Get tier 1 price. */
    public function tier1Price(?int $settingId = null): ?string
    {
        $row = $this->priceRow($settingId);
        return $row?->tier_1_price;
    }

    /** Get tier 2 price. */
    public function tier2Price(?int $settingId = null): ?string
    {
        $row = $this->priceRow($settingId);
        return $row?->tier_2_price;
    }

    /** Get last purchase price. */
    public function lastPurchasePrice(?int $settingId = null): ?string
    {
        $row = $this->priceRow($settingId);
        return $row?->last_purchase_price;
    }

    /** Get average purchase price. */
    public function averagePurchasePrice(?int $settingId = null): ?string
    {
        $row = $this->priceRow($settingId);
        return $row?->average_purchase_price;
    }
}
