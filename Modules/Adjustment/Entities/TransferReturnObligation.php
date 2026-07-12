<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferReturnObligation extends BaseModel
{
    protected $fillable = [
        'transfer_id',
        'transfer_product_id',
        'required_quantity_tax',
        'required_quantity_broken_tax',
        'return_dispatched_quantity_tax',
        'return_dispatched_quantity_broken_tax',
        'return_received_quantity_tax',
        'return_received_quantity_broken_tax',
        'exact_serialized_obligations',
    ];

    protected $casts = [
        'required_quantity_tax' => 'integer',
        'required_quantity_broken_tax' => 'integer',
        'return_dispatched_quantity_tax' => 'integer',
        'return_dispatched_quantity_broken_tax' => 'integer',
        'return_received_quantity_tax' => 'integer',
        'return_received_quantity_broken_tax' => 'integer',
        'exact_serialized_obligations' => 'array',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function transferProduct(): BelongsTo
    {
        return $this->belongsTo(TransferProduct::class);
    }
}
