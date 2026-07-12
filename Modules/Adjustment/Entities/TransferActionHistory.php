<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferActionHistory extends BaseModel
{
    public const ACTION_CREATED = 'CREATED';
    public const ACTION_SUBMITTED = 'SUBMITTED';
    public const ACTION_EDITED = 'EDITED';
    public const ACTION_APPROVED = 'APPROVED';
    public const ACTION_REJECTED = 'REJECTED';
    public const ACTION_ACKNOWLEDGED = 'ACKNOWLEDGED';
    public const ACTION_RESUBMITTED = 'RESUBMITTED';
    public const ACTION_ARCHIVED = 'ARCHIVED';
    public const ACTION_DISPATCH_REVIEWED = 'DISPATCH_REVIEWED';
    public const ACTION_DISPATCHED = 'DISPATCHED';
    public const ACTION_RECEIVED = 'RECEIVED';
    public const ACTION_RETURN_DISPATCHED = 'RETURN_DISPATCHED';
    public const ACTION_RETURN_RECEIVED = 'RETURN_RECEIVED';
    public const ACTION_COMPLETED = 'COMPLETED';

    protected $fillable = [
        'transfer_id',
        'revision',
        'action',
        'from_status',
        'to_status',
        'actor_id',
        'reason',
        'metadata',
        'idempotency_key',
    ];

    protected $casts = [
        'revision' => 'integer',
        'metadata' => 'array',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
