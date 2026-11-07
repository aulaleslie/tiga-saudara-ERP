<?php

namespace Modules\SalesReturn\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;

/**
 * Held custody balance of returned goods (one row per physical occurrence).
 * held = received - released - dispatched. It lives OUTSIDE ProductStock / product_quantity.
 */
class SaleReturnCustody extends BaseModel
{
    protected $table = 'sale_return_custodies';
    protected $guarded = [];

    protected $casts = [
        'quantity_received' => 'decimal:3',
        'quantity_released' => 'decimal:3',
        'quantity_dispatched' => 'decimal:3',
        'version' => 'integer',
    ];

    public function getHeldQuantityAttribute(): float
    {
        // decimal(15,3): never truncate to an integer.
        return round(max(0.0, (float) $this->quantity_received - (float) $this->quantity_released - (float) $this->quantity_dispatched), 3);
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function detail(): BelongsTo
    {
        return $this->belongsTo(SaleReturnDetail::class, 'sale_return_detail_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function serialNumber(): BelongsTo
    {
        return $this->belongsTo(ProductSerialNumber::class, 'product_serial_number_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(SaleReturnCustodyMovement::class, 'sale_return_custody_id');
    }
}
