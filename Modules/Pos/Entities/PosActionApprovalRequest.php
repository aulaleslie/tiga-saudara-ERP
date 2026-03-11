<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PosActionApprovalRequest extends BaseModel
{
    public const STATUS_PENDING   = 'PENDING';
    public const STATUS_APPROVED  = 'APPROVED';
    public const STATUS_REJECTED  = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_EXPIRED   = 'EXPIRED';
    public const STATUS_CONSUMED  = 'CONSUMED';

    public const ACTION_CART_CLEAR = 'CART_CLEAR';
    public const ACTION_LINE_REMOVE = 'LINE_REMOVE';
    public const ACTION_QTY_REDUCE = 'QTY_REDUCE';

    protected $table = 'pos_action_approval_requests';

    protected $fillable = [
        'setting_id',
        'pos_session_id',
        'action_type',
        'target_type',
        'target_id',
        'request_payload',
        'requested_by',
        'status',
        'decided_by',
        'decided_at',
        'decision_reason',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'decided_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    public function token(): HasOne
    {
        return $this->hasOne(PosActionApprovalToken::class, 'approval_request_id');
    }
}
