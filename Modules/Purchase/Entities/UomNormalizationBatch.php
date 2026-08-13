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

/**
 * Legacy/new column compatibility note:
 *
 * The originally executed production migration
 * (2026_08_13_000001_create_uom_normalization_tables) created this table
 * with product_unit_conversion_id, source_unit_id, and base_unit_id
 * columns, modeling a single existing-conversion normalization. The
 * base-UOM-correction feature that replaced it uses separate old/new
 * primary and base Unit facts instead (old_primary_unit_id,
 * new_primary_unit_id, old_base_unit_id, new_base_unit_id) plus additional
 * audit columns, added by the additive
 * 2026_08_13_100000_add_base_uom_correction_audit_columns_to_uom_normalization_batches
 * migration.
 *
 * Both sets of columns coexist in the schema. New execution writes ONLY
 * populate the new old/new primary/base columns and leave the legacy
 * columns NULL. Historical rows written before the upgrade have the legacy
 * columns populated and the new columns NULL — those facts are never
 * fabricated. Use oldBaseUnit()/newBaseUnit() (etc.) for current reads;
 * they resolve against the new columns only, since a legacy row's old/new
 * distinction cannot be honestly reconstructed from a single conversion
 * snapshot.
 */
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
        'actor_user_id',
        'status',
        'reason',
        'old_primary_unit_id',
        'new_primary_unit_id',
        'old_base_unit_id',
        'new_base_unit_id',
        'conversion_factor',
        'rounding_amount',
        'is_acknowledged',
        'is_sales_price_warning_acknowledged',
        'conversion_barcode_changes',
        'location_snapshots',
        'executed_at',
        'cost_outcome',
    ];

    protected $casts = [
        'conversion_factor' => 'decimal:6',
        'rounding_amount' => 'decimal:6',
        'is_acknowledged' => 'boolean',
        'is_sales_price_warning_acknowledged' => 'boolean',
        'conversion_barcode_changes' => 'array',
        'location_snapshots' => 'array',
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



    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function oldPrimaryUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'old_primary_unit_id');
    }

    public function newPrimaryUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'new_primary_unit_id');
    }

    public function oldBaseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'old_base_unit_id');
    }

    public function newBaseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'new_base_unit_id');
    }

    /**
     * Explicit legacy read path for batches written before the base-UOM
     * correction upgrade, when only a single existing conversion/unit
     * snapshot was recorded. Prefer oldBaseUnit()/newBaseUnit() for current
     * writes; use these only to display historical rows honestly.
     */
    public function legacyProductUnitConversion(): BelongsTo
    {
        return $this->belongsTo(ProductUnitConversion::class, 'product_unit_conversion_id');
    }

    public function legacySourceUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'source_unit_id');
    }

    public function legacyBaseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /**
     * True when this row was written before the base-UOM correction upgrade
     * (new old/new primary/base columns are null, legacy columns populated).
     */
    public function isLegacyFormat(): bool
    {
        return is_null($this->old_base_unit_id) && !is_null($this->base_unit_id);
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
