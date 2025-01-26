<?php

namespace Modules\Purchase\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use Modules\Setting\Entities\PaymentMethod;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class PurchasePayment extends Model implements HasMedia
{
    use InteractsWithMedia;
    use HasFactory;

    protected $guarded = [];

    public function purchase() {
        return $this->belongsTo(Purchase::class, 'purchase_id', 'id');
    }

    public function setAmountAttribute($value) {
        $this->attributes['amount'] = $value * 100;
    }

    public function getAmountAttribute($value) {
        return $value / 100;
    }

    public function getDateAttribute($value) {
        return Carbon::parse($value)->format('d M, Y');
    }

    public function scopeByPurchase($query) {
        return $query->where('purchase_id', request()->route('purchase_id'));
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')->singleFile(); // Single file for each payment
    }

    /**
     * Relationship with PaymentMethod
     */
    public function paymentMethod() {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id', 'id');
    }
}
