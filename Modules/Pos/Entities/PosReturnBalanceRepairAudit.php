<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sale\Entities\Sale;

class PosReturnBalanceRepairAudit extends BaseModel
{
    protected bool $uppercaseAllText = false;

    protected $table = 'pos_return_balance_repair_audits';

    protected $fillable = [
        'pos_transaction_id',
        'pos_checkout_id',
        'sale_id',
        'actor_id',
        'reason',
        'evidence_fingerprint',
        'before_values',
        'after_values',
    ];

    protected $casts = [
        'before_values' => 'array',
        'after_values' => 'array',
    ];

    public function posTransaction(): BelongsTo
    {
        return $this->belongsTo(PosTransaction::class, 'pos_transaction_id');
    }

    public function posCheckout(): BelongsTo
    {
        return $this->belongsTo(PosCheckout::class, 'pos_checkout_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
