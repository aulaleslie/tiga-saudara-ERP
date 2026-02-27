<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosSessionCashEvent extends BaseModel
{
    public const EVENT_OPEN_FLOAT = 'OPEN_FLOAT';

    public const EVENT_CASH_SALE_IN = 'CASH_SALE_IN';

    public const EVENT_SAFE_DROP_OUT = 'SAFE_DROP_OUT';

    public const EVENT_CLOSE_COUNT = 'CLOSE_COUNT';

    public const DIRECTION_IN = 'IN';

    public const DIRECTION_OUT = 'OUT';

    public const DIRECTION_NEUTRAL = 'NEUTRAL';

    protected $table = 'pos_session_cash_events';

    protected $fillable = [
        'setting_id',
        'pos_session_id',
        'event_type',
        'direction',
        'amount',
        'denominations',
        'reference_type',
        'reference_id',
        'performed_by',
        'approved_by',
        'notes',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'denominations' => 'array',
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
