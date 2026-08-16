<?php

namespace Modules\Product\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Setting\Entities\Unit;

class ProductUomCorrectionAudit extends BaseModel
{
    public $timestamps = false;

    protected $table = 'product_uom_correction_audits';

    protected $fillable = [
        'product_id',
        'old_unit_id',
        'new_unit_id',
        'conversion_factor',
        'quantities_before',
        'quantities_after',
        'cost_basis_before',
        'cost_basis_after',
        'purchase_details_before',
        'purchase_details_after',
        'reason',
        'actor_user_id',
        'rounding_notes',
        'created_at',
    ];

    protected $casts = [
        'conversion_factor' => 'decimal:6',
        'quantities_before' => 'array',
        'quantities_after' => 'array',
        'cost_basis_before' => 'array',
        'cost_basis_after' => 'array',
        'purchase_details_before' => 'array',
        'purchase_details_after' => 'array',
        'created_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function oldUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'old_unit_id');
    }

    public function newUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'new_unit_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function removedDocuments(): HasMany
    {
        return $this->hasMany(ProductUomCorrectionRemovedDocument::class, 'audit_id');
    }
}
