<?php

namespace Modules\Sale\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;

class SaleDetails extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'sub_total' => 'decimal:2',
        'product_discount_amount' => 'decimal:2',
        'product_tax_amount' => 'decimal:2',
        'serial_number_ids' => 'array',
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

    public function dispatchDetails(): HasMany
    {
        return $this->hasMany(DispatchDetail::class, 'sale_details_id', 'id');
    }

    /**
     * Get all serial numbers for this sale detail.
     * This relationship retrieves ProductSerialNumber records whose IDs are in the serial_number_ids JSON array.
     */
    public function serialNumbers(): HasMany
    {
        return $this->hasMany(ProductSerialNumber::class, 'id')
            ->whereIn('id', $this->serial_number_ids ?? []);
    }
}
