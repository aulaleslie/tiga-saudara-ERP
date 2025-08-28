<?php

namespace Modules\PurchasesReturn\Entities;

class PurchasePaymentCreditApplication extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function purchasePayment()
    {
        return $this->belongsTo(\Modules\Purchases\Entities\PurchasePayment::class, 'purchase_payment_id', 'id');
    }

    public function supplierCredit()
    {
        return $this->belongsTo(SupplierCredit::class, 'supplier_credit_id', 'id');
    }
}
