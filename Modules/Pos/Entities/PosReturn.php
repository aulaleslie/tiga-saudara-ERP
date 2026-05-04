<?php

namespace Modules\Pos\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;
use Modules\SalesReturn\Entities\SaleReturn;

class PosReturn extends Model
{
    use HasFactory;

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

    public function scopeActive($query)
    {
        return $query->where('is_reversed', false);
    }

    public function requiresManualCorrection(): bool
    {
        return $this->manual_correction_required_at !== null
            || $this->status === self::STATUS_MANUAL_CORRECTION_REQUIRED;
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
