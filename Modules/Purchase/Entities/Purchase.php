<?php

namespace Modules\Purchase\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Tax;

class Purchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'date',
        'due_date',
        'reference',
        'supplier_id',
        'tax_id',
        'tax_percentage',
        'tax_amount',
        'discount_percentage',
        'shipping_amount',
        'total_amount',
        'due_amount',
        'status',
        'payment_status',
        'payment_method',
        'note',
        'setting_id',
    ];

    const STATUS_DRAFTED = 'DRAFTED';
    const STATUS_WAITING_APPROVAL = 'WAITING_APPROVAL';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_REJECTED = 'REJECTED';
    const STATUS_RECEIVED_PARTIALLY = 'RECEIVED PARTIALLY';
    const STATUS_RECEIVED = 'RECEIVED';

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

    public static function boot() {
        parent::boot();

        static::creating(function ($model) {
            $number = Purchase::max('id') + 1;
            $model->reference = make_reference_id('PR', $number);
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
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
