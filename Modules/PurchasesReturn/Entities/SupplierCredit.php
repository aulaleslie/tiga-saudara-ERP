<?php

namespace Modules\PurchasesReturn\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\People\Entities\Supplier;

class SupplierCredit extends BaseModel
{

    protected $guarded = [];

    protected $casts = [
        'amount'           => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'id');
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id', 'id');
    }

    public function applications(): SupplierCredit|Builder|HasMany
    {
        return $this->hasMany(PurchasePaymentCreditApplication::class, 'supplier_credit_id', 'id');
    }

    public function scopeOpen($q)  { return $q->where('status', 'open'); }
    public function scopeClosed($q){ return $q->where('status', 'closed'); }
}
