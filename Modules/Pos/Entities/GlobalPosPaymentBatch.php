<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\People\Entities\Customer;
use Modules\Setting\Entities\PaymentMethod;

class GlobalPosPaymentBatch extends BaseModel
{
    protected bool $uppercaseAllText = false;

    protected $table = 'global_pos_payment_batches';

    protected $fillable = [
        'customer_id',
        'user_id',
        'date',
        'reference',
        'payment_method_id',
        'note',
        'idempotency_key',
        'total_amount',
    ];

    protected $casts = [
        'date' => 'date',
        'total_amount' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(GlobalPosPaymentAllocation::class, 'global_pos_payment_batch_id');
    }
}
