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
use App\Traits\Archivable;
use Modules\Pos\Entities\PosReturn;

class SaleReturn extends BaseModel
{
    use Archivable;
    
    protected $fillable = [
        'date',
        'reference',
        'sale_id',
        'sale_reference',
        'customer_id',
        'customer_name',
        'setting_id',
        'location_id',
        'tax_percentage',
        'tax_amount',
        'discount_percentage',
        'discount_amount',
        'shipping_amount',
        'total_amount',
        'paid_amount',
        'due_amount',
        'status',
        'payment_status',
        'payment_method',
        'note',
        'pos_return_id',
        'approval_status',
        'return_type',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'settled_at',
        'settled_by',
    ];

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
        'received_at'      => 'datetime',
        'archived_at'      => 'datetime',
    ];

    public function posReturn(): BelongsTo
    {
        return $this->belongsTo(PosReturn::class, 'pos_return_id');
    }

    public function saleReturnDetails(): Builder|HasMany|SaleReturn
    {
        return $this->hasMany(SaleReturnDetail::class, 'sale_return_id', 'id');
    }

    public function saleReturnPayments(): Builder|HasMany|SaleReturn
    {
        return $this->hasMany(SaleReturnPayment::class, 'sale_return_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(\Modules\People\Entities\Customer::class, 'customer_id', 'id');
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

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by', 'id');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by', 'id');
    }

    public function scopeActive(Builder $query)
    {
        return $query->where('status', '!=', 'Cancelled');
    }

    public function scopePending(Builder $query)
    {
        return $query->where('status', 'Pending');
    }

    public function scopeCompleted(Builder $query)
    {
        return $query->where('status', 'Completed');
    }

    /**
     * Get the credit associated with this return.
     */
    public function customerCredit(): HasOne
    {
        return $this->hasOne(CustomerCredit::class, 'sale_return_id', 'id');
    }

    /**
     * Get the goods received associated with this return.
     */
    public function returnGoods(): HasMany
    {
        return $this->hasMany(SaleReturnGood::class, 'sale_return_id', 'id');
    }
}
