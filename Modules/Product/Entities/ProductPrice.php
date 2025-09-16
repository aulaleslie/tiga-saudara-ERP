<?php

namespace Modules\Product\Entities;

use App\Models\BaseModel;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;

/**
 * @method static Builder|ProductPrice forProduct(int $productId)
 * @method static Builder|ProductPrice forSetting(int $settingId)
 * @mixin Eloquent
 */
class ProductPrice extends BaseModel
{
    protected $table = 'product_prices';

    protected $fillable = [
        'product_id',
        'setting_id',
        'sale_price',
        'tier_1_price',
        'tier_2_price',
        'last_purchase_price',
        'average_purchase_price',
        'purchase_tax_id',
        'sale_tax_id',
    ];

    protected $casts = [
        'sale_price'             => 'decimal:2',
        'tier_1_price'           => 'decimal:2',
        'tier_2_price'           => 'decimal:2',
        'last_purchase_price'    => 'decimal:2',
        'average_purchase_price' => 'decimal:2',
    ];

    // --- Relationships
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }

    public function purchaseTax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'purchase_tax_id');
    }

    public function saleTax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'sale_tax_id');
    }

    // --- Scopes
    public function scopeForProduct(Builder $query, int $productId): Builder
    {
        return $query->where('product_id', $productId);
    }

    public function scopeForSetting(Builder $query, int $settingId): Builder
    {
        return $query->where('setting_id', $settingId);
    }

    // --- Helper
    /**
     * Idempotent writer honoring the (product_id, setting_id) uniqueness.
     */
    public static function upsertFor(array $attributes): self
    {
        return static::updateOrCreate(
            [
                'product_id' => $attributes['product_id'],
                'setting_id' => $attributes['setting_id'],
            ],
            $attributes
        );
    }
}
