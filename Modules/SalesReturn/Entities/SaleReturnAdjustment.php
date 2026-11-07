<?php

namespace Modules\SalesReturn\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable evidence of one committed standard cash-return adjustment (insert only). */
class SaleReturnAdjustment extends BaseModel
{
    protected $table = 'sale_return_adjustments';
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:3',
        'committed_value' => 'decimal:2',
        'debt_reduction' => 'decimal:2',
        'cash_refund' => 'decimal:2',
        'committed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Sale return adjustments are immutable.'));
        static::deleting(fn () => throw new \LogicException('Sale return adjustments are immutable.'));
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(SaleReturnItemSettlement::class, 'sale_return_item_settlement_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SaleReturnPayment::class, 'sale_return_payment_id');
    }
}
