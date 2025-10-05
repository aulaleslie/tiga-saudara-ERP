<?php

namespace Modules\SalesReturn\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;

class SaleReturnGood extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'quantity'    => 'integer',
        'unit_value'  => 'decimal:2',
        'sub_total'   => 'decimal:2',
        'received_at' => 'datetime',
    ];

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class, 'sale_return_id', 'id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
}
