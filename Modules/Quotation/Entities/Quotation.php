<?php

namespace Modules\Quotation\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\People\Entities\Customer;

class Quotation extends BaseModel
{
    protected $guarded = [];

    public function quotationDetails(): Builder|HasMany|Quotation
    {
        return $this->hasMany(QuotationDetails::class, 'quotation_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            $year = now()->year;
            $month = now()->month;

            // Fetch the latest reference for the current year and month
            $latestReference = Quotation::whereYear('created_at', $year)
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
            $model->reference = make_reference_id('QT', $year, $month, $nextNumber);
        });
    }

    public function getDateAttribute($value): string
    {
        return Carbon::parse($value)->format('d M, Y');
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
