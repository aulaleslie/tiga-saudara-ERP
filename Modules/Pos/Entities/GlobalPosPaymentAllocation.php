<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;

class GlobalPosPaymentAllocation extends BaseModel
{
    protected bool $uppercaseAllText = false;

    protected $table = 'global_pos_payment_allocations';

    protected $fillable = [
        'global_pos_payment_batch_id',
        'pos_transaction_id',
        'sale_id',
        'sale_payment_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(GlobalPosPaymentBatch::class, 'global_pos_payment_batch_id');
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
