<?php

namespace Modules\SalesReturn\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sale\Entities\SalePayment;

class SalePaymentCreditApplication extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function salePayment(): BelongsTo
    {
        return $this->belongsTo(SalePayment::class, 'sale_payment_id', 'id');
    }

    public function customerCredit(): BelongsTo
    {
        return $this->belongsTo(CustomerCredit::class, 'customer_credit_id', 'id');
    }
}
