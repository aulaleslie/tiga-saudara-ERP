<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferReturnObligationReservation extends BaseModel
{
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_CLOSED = 'CLOSED';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_CLOSED,
    ];

    protected $fillable = [
        'transfer_movement_return_obligation_id',
        'transfer_movement_id',
        'transfer_movement_line_id',
        'quantity',
        'status',
        'created_by',
        'closed_at',
    ];

    protected $casts = [
        'quantity'  => 'decimal:4',
        'closed_at' => 'datetime',
    ];

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(TransferMovementReturnObligation::class, 'transfer_movement_return_obligation_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(TransferMovement::class, 'transfer_movement_id');
    }

    public function movementLine(): BelongsTo
    {
        return $this->belongsTo(TransferMovementLine::class, 'transfer_movement_line_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
