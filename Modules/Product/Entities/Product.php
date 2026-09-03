<?php

namespace Modules\Product\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Image\Exceptions\InvalidManipulation;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Product extends BaseModel implements HasMedia
{
    use InteractsWithMedia;

    protected $guarded = [];

    protected $casts = [
        // Global quantity is decimal to support fractional, weight-based units (e.g. 23.7 KG).
        'product_quantity' => 'decimal:3',
        'stock_managed' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $with = ['media', 'brand:id,name', 'category:id,category_name'];

    protected function shouldUppercase(string $key): bool
    {
        if (in_array($key, ['product_name', 'canonical_name', 'stock_state', 'formatted_available_qty'])) {
            return false;
        }
        return parent::shouldUppercase($key);
    }

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
     * Eligible conversions available for new Purchase line selection.
     * Excludes inactive units, base unit mismatches, factors <= 1, and non-integer factors for serial products.
     */
    public function eligiblePurchaseConversions(): \Illuminate\Support\Collection
    {
        $this->loadMissing(['conversions.unit', 'conversions.baseUnit']);

        $baseUnitId = (int) ($this->base_unit_id ?? $this->unit_id);
        $isSerialized = (bool) ($this->serial_number_required ?? false);

        return $this->conversions->filter(function (ProductUnitConversion $conv) use ($baseUnitId, $isSerialized) {
            if ($conv->unit && !$conv->unit->is_active) {
                return false;
            }

            $convBaseId = (int) ($conv->base_unit_id ?? $baseUnitId);
            if ($convBaseId !== $baseUnitId) {
                return false;
            }

            $factor = (float) $conv->conversion_factor;
            if ($factor <= 1.0) {
                return false;
            }

            if ($isSerialized && abs($factor - round($factor)) > 1e-6) {
                return false;
            }

            return true;
        })->values();
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

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function purchaseDetails(): HasMany
    {
        return $this->hasMany(\Modules\Purchase\Entities\PurchaseDetail::class);
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

    /** Fetch bundles for a specific setting id. */
    public function bundlesForSetting(int $settingId)
    {
        return $this->bundles()->where('setting_id', $settingId);
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
    public function productStocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    public function scopeGlobalSearch($query, $search)
    {
        if (empty($search)) {
            return $query->active();
        }

        $tokens = array_filter(explode(' ', $search), 'strlen');

        if (empty($tokens)) {
            return $query->active();
        }

        return $query->active()->where(function ($q) use ($tokens) {
            foreach ($tokens as $token) {
                $q->where(function ($sub) use ($token) {
                    $sub->where('product_name', 'like', '%' . $token . '%')
                        ->orWhere('product_code', 'like', '%' . $token . '%')
                        ->orWhere('barcode', 'like', '%' . $token . '%')
                        ->orWhereHas('category', function ($cat) use ($token) {
                            $cat->where('category_name', 'like', '%' . $token . '%');
                        })
                        ->orWhereHas('brand', function ($brand) use ($token) {
                            $brand->where('name', 'like', '%' . $token . '%');
                        });
                });
            }
        });
    }
    
    /**
     * Scope a query to only include active and eligible (non-merged) products.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->whereNull('merged_into_id');
    }

    /**
     * Alias/scope for operational transaction eligibility.
     */
    public function scopeEligible($query)
    {
        return $query->where('is_active', true)->whereNull('merged_into_id');
    }

    /**
     * Scope to query inactive products.
     */
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }
    
    /**
     * Scope a query to only include retired (merged) products.
     */
    public function scopeRetired($query)
    {
        return $query->whereNotNull('merged_into_id');
    }
    
    /**
     * The product this product was merged into.
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'merged_into_id');
    }
    
    /**
     * The products that were merged into this product.
     */
    public function mergedFrom(): HasMany
    {
        return $this->hasMany(Product::class, 'merged_into_id');
    }

    /**
     * Merge events where this product was the survivor.
     */
    public function survivorMergeEvents(): HasMany
    {
        return $this->hasMany(ProductMergeEvent::class, 'survivor_product_id');
    }

    /**
     * Merge audits where this product was retired.
     */
    public function retiredMergeAudits(): HasMany
    {
        return $this->hasMany(ProductMergeAudit::class, 'retired_product_id');
    }
}
