<?php

namespace Modules\Product\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Setting;
use Modules\Product\Entities\ProductUnitConversion;

/**
 * @method static Builder|ProductUnitConversionPrice forConversion(int $conversionId)
 * @method static Builder|ProductUnitConversionPrice forSetting(int $settingId)
 */
class ProductUnitConversionPrice extends BaseModel
{
    protected $table = 'product_unit_conversion_prices';

    protected $fillable = [
        'product_unit_conversion_id',
        'setting_id',
        'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function conversion(): BelongsTo
    {
        return $this->belongsTo(ProductUnitConversion::class, 'product_unit_conversion_id');
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }

    public function scopeForConversion(Builder $query, int $conversionId): Builder
    {
        return $query->where('product_unit_conversion_id', $conversionId);
    }

    public function scopeForSetting(Builder $query, int $settingId): Builder
    {
        return $query->where('setting_id', $settingId);
    }

    public static function upsertFor(array $attributes): self
    {
        return static::updateOrCreate(
            [
                'product_unit_conversion_id' => $attributes['product_unit_conversion_id'],
                'setting_id'                 => $attributes['setting_id'],
            ],
            $attributes
        );
    }

    /**
     * @param iterable<int> $settingIds
     */
    public static function seedForSettings(int $conversionId, float $price, iterable $settingIds): void
    {
        foreach ($settingIds as $settingId) {
            static::upsertFor([
                'product_unit_conversion_id' => $conversionId,
                'setting_id'                 => (int) $settingId,
                'price'                      => $price,
            ]);
        }
    }
}
