<?php

namespace Modules\Adjustment\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;

class TransferProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_id',
        'product_id',
        'quantity',
    ];

    /**
     * Relationships
     */

    // The transfer that this product is part of
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    // The product that is being transferred
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
