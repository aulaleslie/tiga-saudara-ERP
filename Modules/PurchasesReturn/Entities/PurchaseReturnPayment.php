<?php

namespace Modules\PurchasesReturn\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;

class PurchaseReturnPayment extends Model
{
    use HasFactory;

    protected $guarded = [];

    // ✅ Casts replace old cents mutators
    protected $casts = [
        'amount' => 'decimal:2',
        'date'   => 'date',
    ];

    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id', 'id');
    }

    // ✅ New: relational payment method (after migration adds payment_method_id)
    public function paymentMethod()
    {
        return $this->belongsTo(\App\Models\PaymentMethod::class, 'payment_method_id', 'id');
    }
}
