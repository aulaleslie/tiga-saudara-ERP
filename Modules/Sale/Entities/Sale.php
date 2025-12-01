<?php

namespace Modules\Sale\Entities;

use App\Models\BaseModel;
use App\Models\PosSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use App\Models\PosReceipt;

class Sale extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_amount' => 'decimal:2',
    ];

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

    public function dispatchDetails(): HasMany
    {
        return $this->hasMany(DispatchDetail::class, 'sale_id', 'id');
    }

    public static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            $year = now()->year;
            $month = now()->month;

            // Determine if this is a POS sale
            $isPosSale = !empty($model->pos_receipt_id);

            // Fetch the latest reference for the current year and month, filtered by setting_id and POS status
            $latestReferenceQuery = Sale::whereYear('created_at', $year)
                ->whereMonth('created_at', $month)
                ->where('setting_id', $model->setting_id);

            if ($isPosSale) {
                $latestReferenceQuery->whereNotNull('pos_receipt_id');
            } else {
                $latestReferenceQuery->whereNull('pos_receipt_id');
            }

            $latestReference = $latestReferenceQuery->latest('id')->value('reference');

            // Extract the number from the latest reference
            $nextNumber = 1; // Default to 1 if no reference exists
            if ($latestReference) {
                $parts = explode('-', $latestReference);
                $lastNumber = (int) end($parts);
                $nextNumber = $lastNumber + 1;
            }

            // Grab the setting for this sale's tenant
            $setting = Setting::find($model->setting_id);

            // Build prefix based on POS or regular sale
            $documentPrefix = optional($setting)->document_prefix ?: '';
            if ($isPosSale) {
                // For POS sales, use pos_document_prefix if set, otherwise fallback to sale_prefix_document
                $salePrefix = optional($setting)->pos_document_prefix ?: (optional($setting)->sale_prefix_document ?: 'SL');
            } else {
                // For regular sales, use sale_prefix_document
                $salePrefix = optional($setting)->sale_prefix_document ?: 'SL';
            }

            $prefix = $documentPrefix . '-' . $salePrefix;

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

    /**
     * Get all serial numbers associated with this sale through sale details.
     */
    public function serialNumbers(): HasManyThrough
    {
        return $this->hasManyThrough(
            ProductSerialNumber::class,
            SaleDetails::class,
            'sale_id',
            'id'
        )->distinct();
    }

    /**
     * Get the seller (user) who created this sale.
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /**
     * Get the setting (tenant) for this sale through the location.
     */
    public function tenantSetting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id', 'id');
    }

    /**
     * Get the location associated with this sale.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'id');
    }

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    public function posReceipt(): BelongsTo
    {
        return $this->belongsTo(PosReceipt::class, 'pos_receipt_id');
    }
}
