<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Setting\Entities\Setting;

class PosKasBonCollection extends BaseModel
{
    protected bool $uppercaseAllText = false;

    protected $table = 'pos_kas_bon_collections';

    protected $fillable = [
        'pos_transaction_id',
        'setting_id',
        'pos_session_id',
        'pos_terminal_id',
        'user_id',
        'reference',
        'collected_at',
        'amount',
        'cash_tender_amount',
        'change_amount',
        'idempotency_key',
        'request_hash',
        'review_snapshot',
        'receipt_snapshot',
        'notes',
    ];

    public const STATUS_COMPLETED = 'COMPLETED';

    protected $casts = [
        'collected_at' => 'datetime',
        'amount' => 'decimal:2',
        'cash_tender_amount' => 'decimal:2',
        'change_amount' => 'decimal:2',
        'review_snapshot' => 'array',
        'receipt_snapshot' => 'array',
    ];

    public function getAmountCollectedAttribute(): float
    {
        return (float) $this->amount;
    }

    public function getTenderTotalAttribute(): float
    {
        return (float) ($this->receipt_snapshot['tender_total'] ?? $this->cash_tender_amount);
    }

    public function getChangeTotalAttribute(): float
    {
        return (float) $this->change_amount;
    }

    public function getRemainingDebtAttribute(): float
    {
        return (float) ($this->receipt_snapshot['remaining_debt'] ?? 0);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PosTransaction::class, 'pos_transaction_id');
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'pos_terminal_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function methods(): HasMany
    {
        return $this->hasMany(PosKasBonCollectionMethod::class, 'pos_kas_bon_collection_id')->orderBy('sequence_order');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PosKasBonCollectionAllocation::class, 'pos_kas_bon_collection_id');
    }

    public function cashEvents(): HasMany
    {
        return $this->hasMany(PosSessionCashEvent::class, 'reference_id')
            ->where('reference_type', static::class);
    }
}
