<?php

namespace Modules\Product\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductMergeAudit extends Model
{
    protected $guarded = [];

    public $timestamps = false; // no created_at needed since parent has it

    protected $casts = [
        'migrated_relations_snapshot' => 'array',
        'actual_migrated_counts' => 'array',
    ];

    public function mergeEvent(): BelongsTo
    {
        return $this->belongsTo(ProductMergeEvent::class, 'merge_event_id');
    }

    public function retiredProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'retired_product_id');
    }

    public function referenceMigrations(): HasMany
    {
        return $this->hasMany(ProductReferenceMigration::class, 'audit_id');
    }
}
