<?php

namespace Modules\PurchasesReturn\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Purchase\Entities\Purchase;

class PurchaseReturnItemSettlement extends BaseModel
{
    protected $table = 'purchase_return_item_settlements';
    protected $guarded = [];

    /** Fields to skip uppercasing */
    protected array $uppercaseExcept = [
        'received_note', // Preserve case for notes
    ];

    // Status constants for per-line approval workflow
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_SUBMITTED = 'SUBMITTED';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_APPROVED_AWAITING_RECEIVE = 'APPROVED_AWAITING_RECEIVE';
    public const STATUS_RECEIVED = 'RECEIVED';
    public const STATUS_REJECTED = 'REJECTED';

    protected $casts = [
        'nominal' => 'decimal:2',
        'received_quantity' => 'decimal:3',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function detail(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturnDetail::class, 'purchase_return_detail_id');
    }

    public function serialNumber(): BelongsTo
    {
        return $this->belongsTo(ProductSerialNumber::class, 'product_serial_number_id');
    }

    public function targetPurchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'target_purchase_id');
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

    /**
     * Check if this settlement line is editable.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED]);
    }

    /**
     * Check if this settlement line can be submitted for approval.
     */
    public function canSubmit(): bool
    {
        return $this->status === self::STATUS_DRAFT && !empty($this->method);
    }

    /**
     * Check if this settlement line can be approved/rejected.
     */
    public function canApprove(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    /**
     * Get the effective nominal for this settlement item.
     * Falls back to detail sub_total if nominal is zero or null.
     */
    public function getEffectiveNominal(): float
    {
        return $this->nominal > 0
            ? (float) $this->nominal
            : (float) ($this->detail?->sub_total ?? 0);
    }
}

