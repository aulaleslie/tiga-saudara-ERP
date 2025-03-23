<?php

namespace Modules\Sale\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;

class SaleBundleItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * Relationship to the sale detail this bundle item belongs to.
     */
    public function saleDetail(): BelongsTo
    {
        return $this->belongsTo(SaleDetails::class, 'sale_detail_id', 'id');
    }

    /**
     * Relationship to the parent sale.
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id', 'id');
    }

    /**
     * Relationship to the bundled product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
}
