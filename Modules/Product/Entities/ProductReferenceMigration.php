<?php

namespace Modules\Product\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReferenceMigration extends Model
{
    protected $guarded = [];

    public $timestamps = false; // No timestamps needed on this log

    public function audit(): BelongsTo
    {
        return $this->belongsTo(ProductMergeAudit::class, 'audit_id');
    }

    public function oldProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'old_product_id');
    }

    public function newProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'new_product_id');
    }
}
