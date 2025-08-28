<?php

namespace Modules\PurchasesReturn\Entities;

class PurchaseReturnGood extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'quantity'   => 'integer',
        'unit_value' => 'decimal:2',
        'sub_total'  => 'decimal:2',
        'received_at'=> 'datetime',
    ];

    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id', 'id');
    }

    public function product()
    {
        return $this->belongsTo(\App\Models\Product::class, 'product_id', 'id');
    }
}
