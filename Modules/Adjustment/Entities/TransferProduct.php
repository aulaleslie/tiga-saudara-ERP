<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;

class TransferProduct extends BaseModel
{
    protected $fillable = [
        'transfer_id',
        'product_id',
        'quantity',

        // breakdowns
        'quantity_tax',
        'quantity_non_tax',
        'quantity_broken_tax',
        'quantity_broken_non_tax',

        // serial tracking
        'serial_numbers',

        // dispatched info
        'dispatched_at',
        'dispatched_by',
        'dispatched_quantity',
        'dispatched_quantity_tax',
        'dispatched_quantity_non_tax',
        'dispatched_quantity_broken_tax',
        'dispatched_quantity_broken_non_tax',
        'dispatched_serial_numbers',
    ];

    protected $casts = [
        'serial_numbers' => 'array',
        'dispatched_serial_numbers' => 'array',
        'dispatched_at' => 'datetime',
    ];

    /**
     * Relationships
     */

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }
}
