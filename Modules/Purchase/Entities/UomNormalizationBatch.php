<?php

namespace Modules\Purchase\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;

class UomNormalizationBatch extends BaseModel
{
    const STATUS_PENDING = 'PENDING';
    const STATUS_EXECUTED = 'EXECUTED';

    protected $table = 'uom_normalization_batches';

    protected array $uppercaseExcept = [
        'reason',
    ];

    protected $fillable = [
        'setting_id',
        'product_id',
        'product_unit_conversion_id',
        'actor_user_id',
        'status',
        'reason',
        'source_unit_id',
        'base_unit_id',
        'conversion_factor',
        'executed_at',
        'cost_outcome',
    ];

    protected $casts = [
        'conversion_factor' => 'decimal:6',
        'cost_outcome' => 'array',
        'executed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnitConversion(): BelongsTo
    {
        return $this->belongsTo(ProductUnitConversion::class, 'product_unit_conversion_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function sourceUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'source_unit_id');
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(UomNormalizationLine::class, 'batch_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isExecuted(): bool
    {
        return $this->status === self::STATUS_EXECUTED;
    }
}
