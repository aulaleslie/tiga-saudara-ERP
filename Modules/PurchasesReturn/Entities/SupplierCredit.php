<?php

namespace Modules\PurchasesReturn\Entities;

use Illuminate\Database\Eloquent\Model;

class SupplierCredit extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'amount'           => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

    public function supplier()
    {
        return $this->belongsTo(\Modules\People\Entities\Supplier::class, 'supplier_id', 'id');
    }

    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id', 'id');
    }

    public function applications()
    {
        return $this->hasMany(PurchasePaymentCreditApplication::class, 'supplier_credit_id', 'id');
    }

    public function scopeOpen($q)  { return $q->where('status', 'open'); }
    public function scopeClosed($q){ return $q->where('status', 'closed'); }
}
