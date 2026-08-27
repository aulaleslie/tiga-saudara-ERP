<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;

class ConsignmentBillingConfirmation extends BaseModel
{
    use HasFactory;

    protected $table = 'consignment_billing_confirmations';

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_WAITING_APPROVAL = 'WAITING_APPROVAL';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';

    protected $fillable = [
        'setting_id',
        'supplier_id',
        'confirmation_number',
        'status',
        'date',
        'notes',
        'rejection_reason',
        'created_by',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'source_hash',
        'purchase_id',
        'is_ready_for_billing',
    ];

    protected $casts = [
        'date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'is_ready_for_billing' => 'boolean',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ConsignmentBillingConfirmationLine::class, 'consignment_billing_confirmation_id');
    }

    public function serializedAllocations(): HasMany
    {
        return $this->hasMany(ConsignmentSerializedAllocation::class, 'consignment_billing_confirmation_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(ConsignmentAllocationAuditLog::class, 'consignment_billing_confirmation_id');
    }

    public function scopeForSetting(Builder $query, int $settingId): Builder
    {
        return $query->where('setting_id', $settingId);
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isWaitingApproval(): bool
    {
        return $this->status === self::STATUS_WAITING_APPROVAL;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function ($confirmation) {
            if ($confirmation->getOriginal('status') === self::STATUS_APPROVED) {
                throw new \DomainException("Cannot modify approved confirmation #{$confirmation->id}.");
            }
        });

        static::deleting(function ($confirmation) {
            if ($confirmation->isApproved() || $confirmation->isWaitingApproval()) {
                throw new \DomainException("Cannot delete confirmation in status [{$confirmation->status}].");
            }
        });
    }

    public function canEdit(): bool
    {
        return $this->isDraft() || $this->isRejected();
    }

    public function canDelete(): bool
    {
        return $this->isDraft();
    }
}
