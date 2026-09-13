<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\Entities\Product;

class TransferMovementLine extends BaseModel
{
    protected $fillable = [
        'transfer_movement_id',
        'product_id',
        'quantity',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function movement(): BelongsTo
    {
        return $this->belongsTo(TransferMovement::class, 'transfer_movement_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function serials(): HasMany
    {
        return $this->hasMany(TransferMovementSerial::class);
    }
}
