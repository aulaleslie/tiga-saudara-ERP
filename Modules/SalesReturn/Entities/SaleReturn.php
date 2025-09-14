<?php

namespace Modules\SalesReturn\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleReturn extends BaseModel
{
    protected $guarded = [];

    public function saleReturnDetails(): Builder|HasMany|SaleReturn
    {
        return $this->hasMany(SaleReturnDetail::class, 'sale_return_id', 'id');
    }

    public function saleReturnPayments(): Builder|HasMany|SaleReturn
    {
        return $this->hasMany(SaleReturnPayment::class, 'sale_return_id', 'id');
    }

    public static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            $year = now()->year;
            $month = now()->month;

            // Fetch the latest reference for the current year and month
            $latestReference = SaleReturn::whereYear('created_at', $year)
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
            $model->reference = make_reference_id('SLRN', $year, $month, $nextNumber);
        });
    }

    public function scopeCompleted($query) {
        return $query->where('status', 'Completed');
    }

    public function getShippingAmountAttribute($value): float|int
    {
        return $value / 100;
    }

    public function getPaidAmountAttribute($value): float|int
    {
        return $value / 100;
    }

    public function getTotalAmountAttribute($value): float|int
    {
        return $value / 100;
    }

    public function getDueAmountAttribute($value): float|int
    {
        return $value / 100;
    }

    public function getTaxAmountAttribute($value): float|int
    {
        return $value / 100;
    }

    public function getDiscountAmountAttribute($value): float|int
    {
        return $value / 100;
    }
}
