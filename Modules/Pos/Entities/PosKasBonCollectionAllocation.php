<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;

class PosKasBonCollectionAllocation extends BaseModel
{
    protected bool $uppercaseAllText = false;

    protected $table = 'pos_kas_bon_collection_allocations';

    protected $fillable = [
        'pos_kas_bon_collection_id',
        'pos_kas_bon_collection_method_id',
        'pos_transaction_id',
        'sale_id',
        'sale_payment_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function getAllocatedAmountAttribute(): float
    {
        return (float) $this->amount;
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(PosKasBonCollection::class, 'pos_kas_bon_collection_id');
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PosKasBonCollectionMethod::class, 'pos_kas_bon_collection_method_id');
    }

    public function posTransaction(): BelongsTo
    {
        return $this->belongsTo(PosTransaction::class, 'pos_transaction_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function salePayment(): BelongsTo
    {
        return $this->belongsTo(SalePayment::class, 'sale_payment_id');
    }
}
