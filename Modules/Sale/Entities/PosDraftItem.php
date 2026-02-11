<?php

namespace Modules\Sale\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;

class PosDraftItem extends Model
{
    protected $fillable = [
        'pos_draft_id',
        'product_id',
        'product_name',
        'quantity',
        'unit_price',
        'sub_total',
        'payload',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'sub_total' => 'decimal:2',
        'payload' => 'array',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(PosDraft::class, 'pos_draft_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
