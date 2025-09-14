<?php

namespace Modules\SalesReturn\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;

class SaleReturnDetail extends BaseModel
{
    protected $guarded = [];

    protected $with = ['product'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturnPayment::class, 'sale_return_id', 'id');
    }

    public function getPriceAttribute($value): float|int
    {
        return $value / 100;
    }

    public function getUnitPriceAttribute($value): float|int
    {
        return $value / 100;
    }

    public function getSubTotalAttribute($value): float|int
    {
        return $value / 100;
    }

    public function getProductDiscountAmountAttribute($value): float|int
    {
        return $value / 100;
    }

    public function getProductTaxAmountAttribute($value): float|int
    {
        return $value / 100;
    }
}
