<?php

namespace Modules\Quotation\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;

class QuotationDetails extends BaseModel
{
    protected $guarded = [];

    protected $with = ['product'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id', 'id');
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
    }}
