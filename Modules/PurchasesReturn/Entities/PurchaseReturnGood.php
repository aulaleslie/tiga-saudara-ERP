<?php

namespace Modules\PurchasesReturn\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;

class PurchaseReturnGood extends BaseModel
{
    protected $fillable = ['purchase_return_id', 'product_id', 'product_name', 'product_code', 'quantity', 'unit_value', 'sub_total', 'received_at', 'received_quantity', 'note', 'received_by', 'serial_number'];

    protected $casts = [
        'quantity'   => 'integer',
        'received_quantity' => 'integer',
        'unit_value' => 'decimal:2',
        'sub_total'  => 'decimal:2',
        'received_at'=> 'datetime',
    ];

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id', 'id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'received_by');
    }
}
