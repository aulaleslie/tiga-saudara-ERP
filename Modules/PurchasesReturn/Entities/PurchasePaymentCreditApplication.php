<?php

namespace Modules\PurchasesReturn\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Purchase\Entities\PurchasePayment;

class PurchasePaymentCreditApplication extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function purchasePayment(): BelongsTo
    {
        return $this->belongsTo(PurchasePayment::class, 'purchase_payment_id', 'id');
    }

    public function supplierCredit(): BelongsTo
    {
        return $this->belongsTo(SupplierCredit::class, 'supplier_credit_id', 'id');
    }
}
