<?php

namespace Modules\Product\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\User;

class ProductMergeAudit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'migrated_relations_snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    public $timestamps = false; // We only have created_at, handled manually if needed, or by saving.

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            $model->created_at = $model->created_at ?: $model->freshTimestamp();
        });
    }

    public function survivorProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'survivor_product_id');
    }

    public function retiredProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'retired_product_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function referenceMigrations(): HasMany
    {
        return $this->hasMany(ProductReferenceMigration::class, 'audit_id');
    }
}
