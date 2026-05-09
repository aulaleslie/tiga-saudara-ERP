<?php

namespace Modules\Pos\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;
use Modules\SalesReturn\Entities\SaleReturn;

class PosReturn extends Model
{
    use HasFactory, SoftDeletes;

    public const OPTION_CASH_RETURN = 'cash_return';
    public const OPTION_PRODUCT_REPLACEMENT = 'product_replacement';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_AWAITING_RECEIVING = 'awaiting_receiving';
    public const STATUS_AWAITING_SETTLEMENT = 'awaiting_settlement';
    public const STATUS_AWAITING_DISPATCH = 'awaiting_dispatch';
    public const STATUS_MANUAL_CORRECTION_REQUIRED = 'manual_correction_required';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_CANCELLED = 'cancelled';

    public const APPROVAL_STATUS_DRAFT = 'draft';
    public const APPROVAL_STATUS_PENDING = 'pending';
    public const APPROVAL_STATUS_APPROVED = 'approved';
    public const APPROVAL_STATUS_REJECTED = 'rejected';

    public const OPTION_LABELS = [
        self::OPTION_CASH_RETURN => 'Retur Tunai',
        self::OPTION_PRODUCT_REPLACEMENT => 'Penggantian Produk',
    ];

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_PENDING_APPROVAL => 'Menunggu Persetujuan',
        self::STATUS_APPROVED => 'Disetujui',
        self::STATUS_REJECTED => 'Ditolak',
        self::STATUS_AWAITING_RECEIVING => 'Menunggu Penerimaan',
        self::STATUS_AWAITING_SETTLEMENT => 'Menunggu Penyelesaian',
        self::STATUS_AWAITING_DISPATCH => 'Menunggu Pengiriman',
        self::STATUS_MANUAL_CORRECTION_REQUIRED => 'Koreksi Manual Diperlukan',
        self::STATUS_COMPLETED => 'Selesai',
        self::STATUS_ARCHIVED => 'Diarsipkan',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    protected $fillable = [
        'reference',
        'setting_id',
        'pos_transaction_id',
        'pos_checkout_id',
        'transaction_code',
        'receipt_number',
        'customer_id',
        'customer_name',
        'return_option',
        'status',
        'approval_status',
        'is_reversed',
        'source_snapshot',
        'source_snapshot_hash',
        'total_amount',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'received_by',
        'received_at',
        'settled_by',
        'settled_at',
        'archived_by',
        'archived_at',
        'archive_reason',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'manual_correction_action',
        'manual_correction_reason',
        'manual_correction_required_by',
        'manual_correction_required_at',
        'created_by',
        'updated_by',
        'deleted_by',
        'delete_reason',
    ];

    protected $casts = [
        'source_snapshot' => 'array',
        'is_reversed' => 'boolean',
        'total_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'received_at' => 'datetime',
        'settled_at' => 'datetime',
        'archived_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'manual_correction_required_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(PosReturnLine::class);
    }

    public function saleReturns()
    {
        return $this->hasMany(SaleReturn::class, 'pos_return_id');
    }

    public function posTransaction()
    {
        return $this->belongsTo(\Modules\Pos\Entities\PosTransaction::class);
    }

    public function posCheckout()
    {
        return $this->belongsTo(\Modules\Pos\Entities\PosCheckout::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function settledBy()
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function manualCorrectionRequiredBy()
    {
        return $this->belongsTo(User::class, 'manual_correction_required_by');
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_reversed', false);
    }

    /**
     * Statuses whose lines should reduce source returnable quantity.
     *
     * Draft and pending-approval returns are intake-only documents. They must not
     * make the source transaction look already returned until approval/execution.
     */
    public static function returnQuantityConsumingStatuses(): array
    {
        return [
            self::STATUS_APPROVED,
            self::STATUS_AWAITING_RECEIVING,
            self::STATUS_AWAITING_SETTLEMENT,
            self::STATUS_AWAITING_DISPATCH,
            self::STATUS_MANUAL_CORRECTION_REQUIRED,
            self::STATUS_COMPLETED,
        ];
    }

    public function scopeConsumesReturnQuantity($query)
    {
        return $query
            ->active()
            ->whereIn('status', self::returnQuantityConsumingStatuses());
    }

    public function requiresManualCorrection(): bool
    {
        return $this->manual_correction_required_at !== null
            || $this->status === self::STATUS_MANUAL_CORRECTION_REQUIRED;
    }

    public function isDraftEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT && $this->approval_status === self::APPROVAL_STATUS_DRAFT;
    }

    public function isRevisionEditable(): bool
    {
        return $this->isDraftEditable() || $this->isRejectedEditable();
    }

    public function isDraftSubmittable(): bool
    {
        return $this->isDraftEditable();
    }

    public function isRejectedEditable(): bool
    {
        return $this->status === self::STATUS_REJECTED && $this->approval_status === self::APPROVAL_STATUS_REJECTED;
    }

    public function isHardDeletable(): bool
    {
        return $this->isDraftEditable();
    }

    public function isRejectedSoftDeletable(): bool
    {
        return $this->isRejectedEditable();
    }

    public function isLockedFromRevision(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_APPROVED,
            self::STATUS_AWAITING_RECEIVING,
            self::STATUS_AWAITING_SETTLEMENT,
            self::STATUS_AWAITING_DISPATCH,
            self::STATUS_MANUAL_CORRECTION_REQUIRED,
            self::STATUS_COMPLETED,
            self::STATUS_ARCHIVED,
            self::STATUS_CANCELLED,
        ], true);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL => 'warning',
            self::STATUS_APPROVED => 'info',
            self::STATUS_REJECTED => 'danger',
            self::STATUS_AWAITING_RECEIVING => 'primary',
            self::STATUS_AWAITING_SETTLEMENT => 'primary',
            self::STATUS_AWAITING_DISPATCH => 'primary',
            self::STATUS_MANUAL_CORRECTION_REQUIRED => 'danger',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_ARCHIVED => 'secondary',
            self::STATUS_CANCELLED => 'secondary',
            default => 'secondary',
        };
    }
}
