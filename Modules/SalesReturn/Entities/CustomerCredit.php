<?php

namespace Modules\SalesReturn\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\People\Entities\Customer;

class CustomerCredit extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'amount'           => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class, 'sale_return_id', 'id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(SalePaymentCreditApplication::class, 'customer_credit_id', 'id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', 'closed');
    }
}
