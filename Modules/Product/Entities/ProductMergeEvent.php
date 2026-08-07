<?php

namespace Modules\Product\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\User;

class ProductMergeEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public $timestamps = false; // We only have created_at

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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function mergeAudits(): HasMany
    {
        return $this->hasMany(ProductMergeAudit::class, 'merge_event_id');
    }
}
