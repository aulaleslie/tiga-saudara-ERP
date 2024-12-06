<?php

namespace Modules\PurchasesReturn\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PurchaseReturn extends Model
{
    use HasFactory;

    protected $guarded = [];

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

    public function scopeCompleted($query) {
        return $query->where('status', 'Completed');
    }

    public function getShippingAmountAttribute($value) {
        return $value / 100;
    }

    public function getPaidAmountAttribute($value) {
        return $value / 100;
    }

    public function getTotalAmountAttribute($value) {
        return $value / 100;
    }

    public function getDueAmountAttribute($value) {
        return $value / 100;
    }

    public function getTaxAmountAttribute($value) {
        return $value / 100;
    }

    public function getDiscountAmountAttribute($value) {
        return $value / 100;
    }
}
