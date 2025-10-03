<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Setting\Entities\Location;

class Transfer extends BaseModel
{
    public const STATUS_PENDING           = 'PENDING';
    public const STATUS_APPROVED          = 'APPROVED';
    public const STATUS_REJECTED          = 'REJECTED';
    public const STATUS_DISPATCHED        = 'DISPATCHED';
    public const STATUS_RECEIVED          = 'RECEIVED';
    public const STATUS_RETURN_DISPATCHED = 'RETURN_DISPATCHED';
    public const STATUS_RETURN_RECEIVED   = 'RETURN_RECEIVED';

    protected $fillable = [
        'origin_location_id',
        'destination_location_id',
        'created_by',
        'approved_by',
        'rejected_by',
        'dispatched_by',
        'received_by',
        'return_dispatched_by',
        'return_received_by',
        'status',
        'transfer_date',
        'approved_at',
        'rejected_at',
        'dispatched_at',
        'received_at',
        'return_dispatched_at',
        'return_received_at',
    ];

    protected $casts = [
        'approved_at'          => 'datetime',
        'rejected_at'          => 'datetime',
        'dispatched_at'        => 'datetime',
        'received_at'          => 'datetime',
        'return_dispatched_at' => 'datetime',
        'return_received_at'   => 'datetime',
    ];

    /**
     * Relationships
     */

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function returnDispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'return_dispatched_by');
    }

    public function returnReceivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'return_received_by');
    }

    public function originLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'origin_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(TransferProduct::class);
    }

    public function requiresReturn(): bool
    {
        $origin      = $this->relationLoaded('originLocation') ? $this->originLocation : $this->originLocation()->first();
        $destination = $this->relationLoaded('destinationLocation') ? $this->destinationLocation : $this->destinationLocation()->first();

        if (! $origin || ! $destination) {
            return false;
        }

        return (int) $origin->setting_id !== (int) $destination->setting_id;
    }
}
