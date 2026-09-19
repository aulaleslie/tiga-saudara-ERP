<?php

namespace Modules\PurchasesReturn\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnSettlement extends BaseModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXECUTING = 'executing';
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Menunggu Persetujuan',
        self::STATUS_APPROVED => 'Disetujui',
        self::STATUS_REJECTED => 'Ditolak',
        self::STATUS_EXECUTING => 'Sedang Diproses',
        self::STATUS_COMPLETED => 'Selesai',
    ];

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[strtolower($this->status)] ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    protected $guarded = [];

    protected $casts = [
        'submitted_at' => 'datetime',
        'approved_at'  => 'datetime',
        'rejected_at'  => 'datetime',
    ];

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
