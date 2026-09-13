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
        'count_confirmed',
        'applied_quantity_non_tax',
        'applied_quantity_tax',
        'applied_quantity_broken_non_tax',
        'applied_quantity_broken_tax',
        'stock_snapshot_before',
        'stock_snapshot_after',
        'inventory_transaction_reference',
        'metadata',
    ];

    protected $casts = [
        'quantity'                        => 'decimal:4',
        'count_confirmed'                 => 'boolean',
        'applied_quantity_non_tax'        => 'decimal:4',
        'applied_quantity_tax'            => 'decimal:4',
        'applied_quantity_broken_non_tax' => 'decimal:4',
        'applied_quantity_broken_tax'     => 'decimal:4',
        'stock_snapshot_before'           => 'array',
        'stock_snapshot_after'            => 'array',
        'metadata'                        => 'array',
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
