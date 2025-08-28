<?php

namespace Modules\PurchasesReturn\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;

class PurchaseReturnDetail extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'price'                   => 'decimal:2',
        'unit_price'              => 'decimal:2',
        'sub_total'               => 'decimal:2',
        'product_discount_amount' => 'decimal:2',
        'product_tax_amount'      => 'decimal:2',
        'serial_number_ids'       => 'array',
    ];

    public function product() {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function purchaseReturn() {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id', 'id');
    }

    public function purchase() {
        return $this->belongsTo(Purchase::class, 'po_id', 'id');
    }
}
