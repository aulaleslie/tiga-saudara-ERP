<?php

namespace Modules\Purchase\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Tax;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Tags\HasTags;

class Purchase extends Model implements Auditable
{
    use HasFactory;
    use \OwenIt\Auditing\Auditable;
    use HasTags;

    protected $fillable = [
        'date',
        'due_date',
        'reference',
        'supplier_id',
        'tax_id',
        'tax_percentage',
        'tax_amount',
        'discount_percentage',
        'discount_amount',
        'payment_term_id',
        'shipping_amount',
        'total_amount',
        'due_amount',
        'status',
        'payment_status',
        'payment_method',
        'note',
        'setting_id',
        'paid_amount',
        'is_tax_included'
    ];

    const STATUS_DRAFTED = 'DRAFTED';
    const STATUS_WAITING_APPROVAL = 'WAITING_APPROVAL';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_REJECTED = 'REJECTED';
    const STATUS_RECEIVED_PARTIALLY = 'RECEIVED PARTIALLY';
    const STATUS_RECEIVED = 'RECEIVED';
    const STATUS_RETURNED = 'RETURNED';
    const STATUS_RETURNED_PARTIALLY = 'RETURNED PARTIALLY';

    public static function getStatuses(): array
    {
        return [
            self::STATUS_DRAFTED,
            self::STATUS_WAITING_APPROVAL,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_RECEIVED_PARTIALLY,
            self::STATUS_RECEIVED,
        ];
    }

    public function purchaseDetails() {
        return $this->hasMany(PurchaseDetail::class, 'purchase_id', 'id');
    }

    public function purchasePayments() {
        return $this->hasMany(PurchasePayment::class, 'purchase_id', 'id');
    }

    public static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            $year = now()->year;
            $month = now()->month;

            // Fetch the latest reference for the current year and month
            $latestReference = Purchase::whereYear('created_at', $year)
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
            $model->reference = make_reference_id('PR', $year, $month, $nextNumber);
        });
    }

    public function scopeCompleted($query) {
        return $query->where('status', 'Completed');
    }

    public function getShippingAmountAttribute($value) {
        return $value;
    }

    public function getPaidAmountAttribute($value) {
        return $value;
    }

    public function getTotalAmountAttribute($value) {
        return $value;
    }

    public function getDueAmountAttribute($value) {
        return $value;
    }

    public function getTaxAmountAttribute($value) {
        return $value;
    }

    public function getDiscountAmountAttribute($value) {
        return $value;
    }
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_term_id');
    }
}
