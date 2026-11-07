<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Setting;

class PosReplacementAccountingRepairAudit extends BaseModel
{
    public const STATUS_APPLIED = 'APPLIED';

    protected bool $uppercaseAllText = false;

    protected $table = 'pos_replacement_accounting_repair_audits';

    protected $fillable = [
        'setting_id',
        'pos_transaction_id',
        'pos_checkout_id',
        'pos_return_id',
        'actor_id',
        'reason',
        'status',
        'evidence_fingerprint',
        'source_sale_ids',
        'replacement_sale_ids',
        'original_payment_ids',
        'invalidated_payment_ids',
        'transfer_ids',
        'before_values',
        'after_values',
    ];

    protected $casts = [
        'source_sale_ids' => 'array',
        'replacement_sale_ids' => 'array',
        'original_payment_ids' => 'array',
        'invalidated_payment_ids' => 'array',
        'transfer_ids' => 'array',
        'before_values' => 'array',
        'after_values' => 'array',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }

    public function posTransaction(): BelongsTo
    {
        return $this->belongsTo(PosTransaction::class, 'pos_transaction_id');
    }

    public function posCheckout(): BelongsTo
    {
        return $this->belongsTo(PosCheckout::class, 'pos_checkout_id');
    }

    public function posReturn(): BelongsTo
    {
        return $this->belongsTo(PosReturn::class, 'pos_return_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
