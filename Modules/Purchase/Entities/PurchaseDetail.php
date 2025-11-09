<?php

namespace Modules\Purchase\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Tax;

class PurchaseDetail extends BaseModel
{
    protected $fillable = [
        'purchase_id',
        'product_id',
        'quantity',
        'tax_id',
        'unit_price',
        'product_discount_type',
        'sub_total',
        'product_discount_amount',
        'product_name',
        'product_code',
        'price',
        'product_tax_amount',
    ];

    protected $with = ['product'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id', 'id');
    }

    public function getPriceAttribute($value): float|int
    {
        return $value;
    }

    public function getUnitPriceAttribute($value) {
        return $value;
    }

    public function getSubTotalAttribute($value) {
        return $value;
    }

    public function getProductDiscountAmountAttribute($value) {
        return $value;
    }

    public function getProductTaxAmountAttribute($value) {
        return $value;
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'tax_id', 'id');
    }

    /**
     * Relationship with ReceivedNoteDetail
     * A PurchaseDetail can have multiple ReceivedNoteDetails.
     */
    public function receivedNoteDetails(): HasMany
    {
        return $this->hasMany(ReceivedNoteDetail::class, 'po_detail_id');
    }
}
