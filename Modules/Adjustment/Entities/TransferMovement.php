<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Setting\Entities\Location;
use RuntimeException;

class TransferMovement extends BaseModel
{
    public const TYPE_FORWARD_DISPATCH = 'FORWARD_DISPATCH';
    public const TYPE_FORWARD_RECEIPT  = 'FORWARD_RECEIPT';
    public const TYPE_RETURN_DISPATCH   = 'RETURN_DISPATCH';
    public const TYPE_RETURN_RECEIPT    = 'RETURN_RECEIPT';

    public const TYPES = [
        self::TYPE_FORWARD_DISPATCH,
        self::TYPE_FORWARD_RECEIPT,
        self::TYPE_RETURN_DISPATCH,
        self::TYPE_RETURN_RECEIPT,
    ];

    /**
     * Fixed lineage discriminator written for every FORWARD_DISPATCH, FORWARD_RECEIPT, and
     * RETURN_RECEIPT movement (types that still have exactly one lineage per (transfer_id, type)).
     * return_batch_id is NOT NULL on every row so the (transfer_id, type, return_batch_id, revision)
     * unique index cannot be defeated by "NULLs are distinct" — only RETURN_DISPATCH movements get
     * a real per-batch UUID here instead.
     */
    public const SINGLETON_LINEAGE = '00000000-0000-0000-0000-000000000000';

    public const STATUS_DRAFT     = 'DRAFT';
    public const STATUS_PENDING   = 'PENDING';
    public const STATUS_APPROVED  = 'APPROVED';
    public const STATUS_REJECTED  = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    public const CONDITION_GOOD     = 'GOOD';
    public const CONDITION_BREAKAGE = 'BREAKAGE';

    public const CONDITIONS = [
        self::CONDITION_GOOD,
        self::CONDITION_BREAKAGE,
    ];

    protected $fillable = [
        'transfer_id',
        'type',
        'revision',
        'lock_version',
        'transfer_revision',
        'status',
        'stock_condition',
        'origin_location_id',
        'destination_location_id',
        'source_movement_id',
        'supersedes_movement_id',
        'return_batch_id',
        'created_by',
        'updated_by',
        'submitted_by',
        'reviewed_by',
        'submitted_at',
        'reviewed_at',
        'rejection_reason',
        'cancellation_reason',
        'empty_count_confirmed',
        'empty_count_confirmed_by',
        'empty_count_confirmed_at',
        'tax_setting_id',
        'tax_id',
        'tax_name',
        'tax_rate',
        'tax_resolver_provenance',
        'tax_resolved_at',
        'metadata',
    ];

    protected $casts = [
        'revision'                 => 'integer',
        'lock_version'            => 'integer',
        'transfer_revision'        => 'integer',
        'empty_count_confirmed'    => 'boolean',
        'tax_rate'                 => 'decimal:4',
        'submitted_at'             => 'datetime',
        'reviewed_at'              => 'datetime',
        'empty_count_confirmed_at' => 'datetime',
        'tax_resolved_at'          => 'datetime',
        'metadata'                 => 'array',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function originLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'origin_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function sourceMovement(): BelongsTo
    {
        return $this->belongsTo(TransferMovement::class, 'source_movement_id');
    }

    public function supersedesMovement(): BelongsTo
    {
        return $this->belongsTo(TransferMovement::class, 'supersedes_movement_id');
    }

    public function taxSetting(): BelongsTo
    {
        return $this->belongsTo(\Modules\Setting\Entities\Setting::class, 'tax_setting_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(\Modules\Setting\Entities\Tax::class, 'tax_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function emptyCountConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'empty_count_confirmed_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TransferMovementLine::class);
    }

    public function serials(): HasMany
    {
        return $this->hasMany(TransferMovementSerial::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(TransferMovementHistory::class);
    }

    public function returnObligationReservations(): HasMany
    {
        return $this->hasMany(TransferReturnObligationReservation::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    protected static function booted(): void
    {
        static::creating(function (self $movement): void {
            if (empty($movement->return_batch_id)) {
                $movement->return_batch_id = self::SINGLETON_LINEAGE;
            }
        });
    }
}
