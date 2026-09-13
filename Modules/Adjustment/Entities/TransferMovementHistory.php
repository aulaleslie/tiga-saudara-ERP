<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferMovementHistory extends BaseModel
{
    public const ACTION_CREATED   = 'CREATED';
    public const ACTION_UPDATED   = 'UPDATED';
    public const ACTION_SUBMITTED = 'SUBMITTED';
    public const ACTION_APPROVED  = 'APPROVED';
    public const ACTION_REJECTED  = 'REJECTED';
    public const ACTION_CANCELLED = 'CANCELLED';

    public const ACTIONS = [
        self::ACTION_CREATED,
        self::ACTION_UPDATED,
        self::ACTION_SUBMITTED,
        self::ACTION_APPROVED,
        self::ACTION_REJECTED,
        self::ACTION_CANCELLED,
    ];

    protected $fillable = [
        'transfer_movement_id',
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

    public function movement(): BelongsTo
    {
        return $this->belongsTo(TransferMovement::class, 'transfer_movement_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
