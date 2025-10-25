<?php

namespace Modules\Product\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Setting\Entities\Unit;

/**
 * @property-read \Illuminate\Support\Collection<int, ProductUnitConversionPrice> $prices
 */
class ProductUnitConversion extends BaseModel
{
    protected $fillable = ['product_id', 'unit_id', 'base_unit_id', 'conversion_factor', 'barcode'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductUnitConversionPrice::class, 'product_unit_conversion_id');
    }

    public function priceForSetting(int $settingId): ?ProductUnitConversionPrice
    {
        if ($this->relationLoaded('prices')) {
            return $this->prices->firstWhere('setting_id', $settingId);
        }

        return $this->prices()->where('setting_id', $settingId)->first();
    }

    public function priceValueForSetting(int $settingId): float
    {
        return (float) optional($this->priceForSetting($settingId))->price ?? 0.0;
    }
}
