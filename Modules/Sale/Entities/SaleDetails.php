<?php

namespace Modules\Sale\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\Entities\Product;

class SaleDetails extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'sub_total' => 'decimal:2',
        'product_discount_amount' => 'decimal:2',
        'product_tax_amount' => 'decimal:2',
    ];

    protected $with = ['product'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id', 'id');
    }

    public function bundleItems(): Builder|HasMany|SaleDetails
    {
        return $this->hasMany(SaleBundleItem::class, 'sale_detail_id', 'id');
    }
}
