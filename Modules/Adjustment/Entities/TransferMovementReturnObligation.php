<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;

class TransferMovementReturnObligation extends BaseModel
{
    public const STATUS_OUTSTANDING = 'OUTSTANDING';
    public const STATUS_FULFILLED   = 'FULFILLED';

    public const STATUSES = [
        self::STATUS_OUTSTANDING,
        self::STATUS_FULFILLED,
    ];

    public const CONDITION_GOOD     = 'GOOD';
    public const CONDITION_BREAKAGE = 'BREAKAGE';

    protected $fillable = [
        'transfer_id',
        'transfer_route_policy_id',
        'receipt_movement_id',
        'product_id',
        'stock_condition',
        'required_quantity',
        'returned_quantity',
        'status',
    ];

    protected $casts = [
        'required_quantity' => 'integer',
        'returned_quantity' => 'integer',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function routePolicy(): BelongsTo
    {
        return $this->belongsTo(TransferRoutePolicy::class, 'transfer_route_policy_id');
    }

    public function receiptMovement(): BelongsTo
    {
        return $this->belongsTo(TransferMovement::class, 'receipt_movement_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function outstandingQuantity(): int
    {
        return max(0, $this->required_quantity - $this->returned_quantity);
    }
}
