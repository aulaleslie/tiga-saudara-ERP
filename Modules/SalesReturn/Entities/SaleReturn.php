<?php

namespace Modules\SalesReturn\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

class SaleReturn extends BaseModel
{
    protected $guarded = [];

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
        'settled_at'       => 'datetime',
    ];

    public function saleReturnDetails(): Builder|HasMany|SaleReturn
    {
        return $this->hasMany(SaleReturnDetail::class, 'sale_return_id', 'id');
    }

    public function saleReturnPayments(): Builder|HasMany|SaleReturn
    {
        return $this->hasMany(SaleReturnPayment::class, 'sale_return_id', 'id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id', 'id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'id');
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id', 'id');
    }

    public function saleReturnGoods(): Builder|HasMany|SaleReturnGood
    {
        return $this->hasMany(SaleReturnGood::class, 'sale_return_id', 'id');
    }

    public function customerCredit(): HasOne|Builder|CustomerCredit
    {
        return $this->hasOne(CustomerCredit::class, 'sale_return_id', 'id');
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

    public function scopeApproved($query)
    {
        return $query->whereRaw('LOWER(approval_status) = ?', ['approved']);
    }

    public function scopePending($query)
    {
        return $query->whereRaw('LOWER(approval_status) = ?', ['pending']);
    }

    public function scopeRejected($query)
    {
        return $query->whereRaw('LOWER(approval_status) = ?', ['rejected']);
    }

    public function scopeDraft($query)
    {
        return $query->whereRaw('LOWER(approval_status) = ?', ['draft']);
    }
}
