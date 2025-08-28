<?php

namespace Modules\PurchasesReturn\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\People\Entities\Supplier;

class PurchaseReturn extends Model
{
    use HasFactory;

    protected $guarded = [];

    // ✅ Cast money & dates
    protected $casts = [
        'tax_amount'       => 'decimal:2',
        'discount_amount'  => 'decimal:2',
        'shipping_amount'  => 'decimal:2',
        'total_amount'     => 'decimal:2',
        'paid_amount'      => 'decimal:2',
        'due_amount'       => 'decimal:2',
        'date'             => 'date',
        'approved_at'      => 'datetime',
        'rejected_at'      => 'datetime',
    ];

    public function purchaseReturnDetails() {
        return $this->hasMany(PurchaseReturnDetail::class, 'purchase_return_id', 'id');
    }

    public function purchaseReturnPayments() {
        return $this->hasMany(PurchaseReturnPayment::class, 'purchase_return_id', 'id');
    }

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $year = now()->year;
            $month = now()->month;

            // Fetch the latest reference for the current year and month
            $latestReference = PurchaseReturn::whereYear('created_at', $year)
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
        return $this->belongsTo(\App\Models\User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'rejected_by');
    }
    public function goods()
    {
        return $this->hasMany(\App\Models\PurchaseReturnGood::class, 'purchase_return_id');
    }
    public function supplierCredit()
    {
        return $this->hasOne(\App\Models\SupplierCredit::class, 'purchase_return_id');
    }

    public function scopeApproved($q)
    {
        return $q->where('approval_status', 'approved');
    }
    public function scopePending($q)
    {
        return $q->where('approval_status', 'pending');
    }
    public function scopeRejected($q)
    {
        return $q->where('approval_status', 'rejected');
    }
    public function scopeDraft($q)
    {
        return $q->where('approval_status', 'draft');
    }
}
