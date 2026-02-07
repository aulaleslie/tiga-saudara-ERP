<?php

namespace Modules\PurchasesReturn\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use App\Traits\Archivable;

class PurchaseReturn extends BaseModel implements HasMedia
{
    use InteractsWithMedia, Archivable;
    protected $guarded = [];

    // ✅ Cast money & dates
    protected $casts = [
        'tax_amount'       => 'decimal:2',
        'discount_amount'  => 'decimal:2',
        'shipping_amount'  => 'decimal:2',
        'total_amount'     => 'decimal:2',
        'paid_amount'      => 'decimal:2',
        'due_amount'       => 'decimal:2',
        'return_shipping_amount' => 'decimal:2',
        'date'             => 'date',
        'approved_at'      => 'datetime',
        'rejected_at'      => 'datetime',
        'settled_at'       => 'datetime',
        'return_dispatched_at' => 'datetime',
        'dispatch_requested_at' => 'datetime',
        'dispatch_approved_at' => 'datetime',
        'dispatch_rejected_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('return_awb_attachments');
    }

    public function purchaseReturnDetails(): Builder|HasMany|PurchaseReturn
    {
        return $this->hasMany(PurchaseReturnDetail::class, 'purchase_return_id', 'id');
    }

    public function purchaseReturnPayments(): Builder|HasMany|PurchaseReturn
    {
        return $this->hasMany(PurchaseReturnPayment::class, 'purchase_return_id', 'id');
    }

    public static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            $year = now()->year;
            $month = now()->month;
            $settingId = $model->setting_id;

            // Fetch the latest reference for the current year, month, and setting
            $latestReference = PurchaseReturn::withArchived()
                ->where('setting_id', $settingId)
                ->whereYear('created_at', $year)
                ->whereMonth('created_at', $month)
                ->latest('id')
                ->value('reference');

            // Extract the number from the latest reference
            $nextNumber = 1; // Default to 1 if no reference exists
            if ($latestReference) {
                $parts = explode('-', $latestReference);
                $lastNumber = (int) end($parts);
                $nextNumber = $lastNumber + 1;
            }

            // Grab the setting from model (works during queue processing)
            $setting = Setting::find($settingId);

            // Build prefix:
            // 1) take document_prefix if truthy, else empty string
            // 2) then take purchase_return_prefix_document if truthy, else fallback to 'PRRN'
            $docPrefix = optional($setting)->document_prefix;
            $returnPrefix = optional($setting)->purchase_return_prefix_document ?: 'PRRN';
            
            $prefix = ($docPrefix ? $docPrefix . '-' : '') . $returnPrefix;

            // Generate the new reference ID
            $model->reference = make_reference_id($prefix, $year, $month, $nextNumber);
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }
    public function goods()
    {
        return $this->hasMany(PurchaseReturnGood::class, 'purchase_return_id');
    }

    public function settlementItems()
    {
        return $this->hasMany(PurchaseReturnItemSettlement::class, 'purchase_return_id');
    }

    /**
     * @deprecated Header-level location removed in Ticket 2.
     * Use PurchaseReturnDetail::location() for per-line location (Ticket 3).
     * Kept for legacy data compatibility.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }
    public function supplierCredit(): HasOne|Builder|PurchaseReturn
    {
        return $this->hasOne(SupplierCredit::class, 'purchase_return_id');
    }

    public function scopeApproved($q)
    {
        return $q->whereRaw('LOWER(approval_status) = ?', ['approved']);
    }

    public function scopeCompleted($query)
    {
        return $query->whereIn('status', ['Completed', self::STATUS_COMPLETED]);
    }

    public function scopePending($q)
    {
        return $q->whereRaw('LOWER(approval_status) = ?', ['pending']);
    }
    public function scopeRejected($q)
    {
        return $q->whereRaw('LOWER(approval_status) = ?', ['rejected']);
    }
    public function scopeDraft($q)
    {
        return $q->whereRaw('LOWER(approval_status) = ?', ['draft']);
    }

    public function settlement(): HasOne
    {
        return $this->hasOne(PurchaseReturnSettlement::class, 'purchase_return_id');
    }

    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'return_dispatched_by');
    }

    // Unified document status constants (precedence order)
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_AWAITING_DISPATCH = 'AWAITING_DISPATCH';
    public const STATUS_DISPATCH_PENDING_APPROVAL = 'DISPATCH_PENDING_APPROVAL';
    public const STATUS_IN_RETURN = 'IN_RETURN';
    public const STATUS_PARTIAL_SETTLEMENT = 'PARTIAL_SETTLEMENT';
    public const STATUS_COMPLETED = 'COMPLETED';

    public static function unifiedStatusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_PENDING_APPROVAL => 'Menunggu Persetujuan',
            self::STATUS_REJECTED => 'Ditolak',
            self::STATUS_AWAITING_DISPATCH => 'Menunggu Pengiriman Retur',
            self::STATUS_DISPATCH_PENDING_APPROVAL => 'Menunggu Persetujuan Dispatch',
            self::STATUS_IN_RETURN => 'Sedang Diretur',
            self::STATUS_PARTIAL_SETTLEMENT => 'Penyelesaian Sebagian',
            self::STATUS_COMPLETED => 'Selesai',
        ];
    }

    public function getUnifiedStatusAttribute(): string
    {
        $approvalStatus = strtolower($this->approval_status ?? '');
        $dispatchStatus = strtolower($this->return_dispatch_status ?? '');

        // 1. Draft: approval_status is draft or empty
        if ($approvalStatus === 'draft' || $approvalStatus === '') {
            return self::STATUS_DRAFT;
        }

        // 2. Pending Approval: waiting for document approval
        if ($approvalStatus === 'pending') {
            return self::STATUS_PENDING_APPROVAL;
        }

        // 3. Rejected: document was rejected
        if ($approvalStatus === 'rejected') {
            return self::STATUS_REJECTED;
        }

        // From here, document is approved

        // 4. Awaiting Dispatch: approved but no dispatch requested
        if ($dispatchStatus === '' || $dispatchStatus === 'rejected') {
            return self::STATUS_AWAITING_DISPATCH;
        }

        // 5. Dispatch Pending Approval: dispatch requested, awaiting approval
        if ($dispatchStatus === 'pending_approval') {
            return self::STATUS_DISPATCH_PENDING_APPROVAL;
        }

        // From here, dispatch is approved (status = 'dispatched')

        // 6-8. Check settlement status
        $items = $this->relationLoaded('settlementItems')
            ? $this->settlementItems
            : $this->settlementItems()->get();

        if ($items->isEmpty()) {
            return self::STATUS_IN_RETURN;
        }

        // Check finality by method
        $allFinal = $items->every(fn($i) => $this->isItemFinal($i));
        $anyFinal = $items->contains(fn($i) => $this->isItemFinal($i));

        if ($allFinal) {
            return self::STATUS_COMPLETED;
        }

        if ($anyFinal) {
            return self::STATUS_PARTIAL_SETTLEMENT;
        }

        return self::STATUS_IN_RETURN;
    }

    /**
     * Check if a settlement item is in final state.
     * NOTE: REJECTED items are explicitly NOT final - they remain unresolved
     * and count toward "in return process" stock until re-submitted and approved.
     * - MODIFY_PURCHASE: final at APPROVED
     * - PRODUCT_REPAIR, BROKEN_STOCK: final at RECEIVED
     * - CREDIT, CASH: final at APPROVED
     */
    protected function isItemFinal(PurchaseReturnItemSettlement $item): bool
    {
        $status = strtoupper($item->status);
        $method = strtoupper($item->method ?? '');

        // MODIFY_PURCHASE, CREDIT, CASH are final at APPROVED
        if (in_array($method, ['MODIFY_PURCHASE', 'CREDIT', 'CASH'])) {
            return $status === 'APPROVED';
        }

        // PRODUCT_REPAIR, BROKEN_STOCK are final at RECEIVED
        if (in_array($method, ['PRODUCT_REPAIR', 'BROKEN_STOCK'])) {
            return $status === 'RECEIVED';
        }

        // For unknown methods, treat APPROVED or RECEIVED as final
        return in_array($status, ['APPROVED', 'RECEIVED']);
    }

    public function getUnifiedStatusLabelAttribute(): string
    {
        return self::unifiedStatusLabels()[$this->unified_status] ?? $this->unified_status;
    }

    public function getReturnTypeLabelAttribute(): string
    {
        return match($this->return_type) {
            'exchange' => 'Tukar Barang',
            'deposit' => 'Simpan Sebagai Kredit',
            'cash' => 'Pengembalian Tunai',
            default => 'Belum ditentukan',
        };
    }

    /**
     * Compute roll-up settlement status from per-line item states.
     * Returns: 'Awaiting Settlement', 'Settled Partially', or 'Settled'
     * 
     * @return string
     * @deprecated Use unified_status or unified_status_label instead.
     */
    public function getSettlementStatusAttribute(): string
    {
        $items = $this->relationLoaded('settlementItems') 
            ? $this->settlementItems 
            : $this->settlementItems()->get();
        
        if ($items->isEmpty()) {
            return self::STATUS_IN_RETURN;
        }
        
        $approvedStatuses = ['APPROVED', 'RECEIVED'];
        $allApproved = $items->every(fn($i) => in_array(strtoupper($i->status), $approvedStatuses));
        $anyApproved = $items->contains(fn($i) => in_array(strtoupper($i->status), $approvedStatuses));
        
        if ($allApproved) {
            return self::STATUS_COMPLETED;
        } elseif ($anyApproved) {
            return self::STATUS_PARTIAL_SETTLEMENT;
        }
        
        return self::STATUS_IN_RETURN;
    }
}
