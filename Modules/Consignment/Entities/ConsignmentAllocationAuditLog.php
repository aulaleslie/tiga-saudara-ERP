<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsignmentAllocationAuditLog extends BaseModel
{
    use HasFactory;

    protected $table = 'consignment_allocation_audit_logs';

    public const ACTION_DRAFT_CREATED = 'DRAFT_CREATED';
    public const ACTION_DRAFT_UPDATED = 'DRAFT_UPDATED';
    public const ACTION_SUBMITTED = 'SUBMITTED';
    public const ACTION_APPROVED = 'APPROVED';
    public const ACTION_REJECTED = 'REJECTED';
    public const ACTION_BILLING_CONVERTED = 'BILLING_CONVERTED';
    public const ACTION_BILLING_CONVERSION_FAILED = 'BILLING_CONVERSION_FAILED';

    protected $fillable = [
        'consignment_billing_confirmation_id',
        'action',
        'actor_id',
        'reason',
        'snapshot',
    ];

    protected $casts = [
        'snapshot' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::updating(function ($log) {
            throw new \DomainException("Audit log records are immutable and cannot be updated.");
        });

        static::deleting(function ($log) {
            throw new \DomainException("Audit log records are immutable and cannot be deleted.");
        });
    }

    public function confirmation(): BelongsTo
    {
        return $this->belongsTo(ConsignmentBillingConfirmation::class, 'consignment_billing_confirmation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
