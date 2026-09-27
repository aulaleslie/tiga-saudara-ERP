<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosCheckoutPaymentAllocation extends BaseModel
{
    protected bool $uppercaseAllText = false;

    protected $table = 'pos_checkout_payment_allocations';

    protected $fillable = [
        'pos_checkout_id',
        'pos_checkout_payment_id',
        'split_group_key',
        'allocated_amount_minor_units',
    ];

    protected $casts = [
        'allocated_amount_minor_units' => 'integer',
    ];

    /**
     * Get the checkout this allocation belongs to.
     */
    public function checkout(): BelongsTo
    {
        return $this->belongsTo(PosCheckout::class, 'pos_checkout_id');
    }

    /**
     * Get the payment this allocation belongs to.
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PosCheckoutPayment::class, 'pos_checkout_payment_id');
    }

    /**
     * Get the allocated amount as decimal (allocated_amount_minor_units / 100).
     */
    public function getAllocatedAmountAttribute(): float
    {
        return $this->allocated_amount_minor_units / 100;
    }
}
