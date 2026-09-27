<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Setting\Entities\PaymentMethod;

class PosCheckoutPayment extends BaseModel
{
    protected bool $uppercaseAllText = false;

    protected $table = 'pos_checkout_payments';

    protected $fillable = [
        'pos_checkout_id',
        'payment_method_id',
        'amount_minor_units',
        'reference',
        'sequence_order',
    ];

    protected $casts = [
        'amount_minor_units' => 'integer',
        'sequence_order' => 'integer',
    ];

    /**
     * Get the checkout this payment belongs to.
     */
    public function checkout(): BelongsTo
    {
        return $this->belongsTo(PosCheckout::class, 'pos_checkout_id');
    }

    /**
     * Get the payment method.
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    /**
     * Get the allocations of this payment to split groups.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PosCheckoutPaymentAllocation::class, 'pos_checkout_payment_id');
    }

    /**
     * Get the payment amount as decimal (amount_minor_units / 100).
     */
    public function getAmountAttribute(): float
    {
        return $this->amount_minor_units / 100;
    }
}
