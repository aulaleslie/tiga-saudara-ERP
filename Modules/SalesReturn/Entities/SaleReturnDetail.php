<?php

namespace Modules\SalesReturn\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Tax;

class SaleReturnDetail extends BaseModel
{
    protected $guarded = [];

    protected $with = ['product'];

    protected $casts = [
        'quantity'                => 'integer',
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

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class, 'sale_return_id', 'id');
    }

    public function saleDetail(): BelongsTo
    {
        return $this->belongsTo(SaleDetails::class, 'sale_detail_id', 'id');
    }

    public function dispatchDetail(): BelongsTo
    {
        return $this->belongsTo(DispatchDetail::class, 'dispatch_detail_id', 'id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'tax_id', 'id');
    }
}
