<?php

namespace Modules\PurchasesReturn\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;

class PurchaseReturnDetail extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'price'                   => 'decimal:2',
        'unit_price'              => 'decimal:2',
        'sub_total'               => 'decimal:2',
        'product_discount_amount' => 'decimal:2',
        'product_tax_amount'      => 'decimal:2',
        'serial_number_ids'       => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id', 'id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'po_id', 'id');
    }

    /**
     * Get the serial numbers associated with the return detail.
     */
    public function getSerialNumbers()
    {
        if (empty($this->serial_number_ids)) {
            return collect();
        }

        return \Modules\Product\Entities\ProductSerialNumber::whereIn('id', $this->serial_number_ids)
            ->pluck('serial_number');
    }
}
