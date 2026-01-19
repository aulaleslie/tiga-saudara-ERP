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

class PurchaseReturn extends BaseModel implements HasMedia
{
    use InteractsWithMedia;
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
            $latestReference = PurchaseReturn::where('setting_id', $settingId)
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

            // Generate the new reference ID
            $model->reference = make_reference_id('PRRN', $year, $month, $nextNumber);
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
        return $query->where('status', 'Completed');
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
     */
    public function getSettlementStatusAttribute(): string
    {
        $items = $this->relationLoaded('settlementItems') 
            ? $this->settlementItems 
            : $this->settlementItems()->get();
        
        if ($items->isEmpty()) {
            return 'Awaiting Settlement';
        }
        
        $approvedStatuses = ['APPROVED', 'APPROVED_AWAITING_RECEIVE', 'RECEIVED'];
        $allApproved = $items->every(fn($i) => in_array(strtoupper($i->status), $approvedStatuses));
        $anyApproved = $items->contains(fn($i) => in_array(strtoupper($i->status), $approvedStatuses));
        
        if ($allApproved) {
            return 'Settled';
        } elseif ($anyApproved) {
            return 'Settled Partially';
        }
        
        return 'Awaiting Settlement';
    }
}
