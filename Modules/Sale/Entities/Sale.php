<?php

namespace Modules\Sale\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\People\Entities\Customer;
use Modules\Setting\Entities\Setting;

class Sale extends BaseModel
{
    protected $guarded = [];

    const STATUS_DRAFTED = 'DRAFTED';
    const STATUS_WAITING_APPROVAL = 'WAITING_APPROVAL';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_REJECTED = 'REJECTED';
    const STATUS_DISPATCHED_PARTIALLY = 'DISPATCHED PARTIALLY';
    const STATUS_DISPATCHED = 'DISPATCHED';
    const STATUS_RETURNED = 'RETURNED';
    const STATUS_RETURNED_PARTIALLY = 'RETURNED PARTIALLY';

    public function saleDetails(): HasMany
    {
        return $this->hasMany(SaleDetails::class, 'sale_id', 'id');
    }

    public function salePayments(): HasMany
    {
        return $this->hasMany(SalePayment::class, 'sale_id', 'id');
    }

    public function saleDispatches(): HasMany
    {
        return $this->hasMany(Dispatch::class);
    }

    public static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            $year = now()->year;
            $month = now()->month;

            // Fetch the latest reference for the current year and month
            $latestReference = Sale::whereYear('created_at', $year)
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

            // Grab the setting (find(null) simply returns null)
            $setting = Setting::find(session('setting_id'));

            // Build prefix:
            // 1) take document_prefix if truthy, else empty string
            // 2) then take sale_prefix_document if truthy, else fallback to 'SL'
            $prefix = (optional($setting)->document_prefix ?: '') . '-'
                . (optional($setting)->sale_prefix_document ?: 'SL');

            $model->reference = make_reference_id($prefix, $year, $month, $nextNumber);
        });
    }

    public function scopeCompleted($query) {
        return $query->where('status', 'Completed');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }
}
