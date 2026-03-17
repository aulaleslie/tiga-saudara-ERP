<?php

namespace Modules\Sale\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Setting\Entities\PaymentMethod;
use Modules\SalesReturn\Entities\SalePaymentCreditApplication;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class SalePayment extends BaseModel implements HasMedia
{
    use InteractsWithMedia;

    protected $guarded = [];

    protected $fillable = [
        'sale_id',
        'payment_method_id',
        'amount',
        'date',
        'reference',
        'payment_method',
        'note',
        'stage_order',
        'edc_reference',
        'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
        'stage_order' => 'integer',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id', 'id');
    }

    public function getDateAttribute($value): string
    {
        return Carbon::parse($value)->format('d M, Y');
    }

    public function scopeBySale($query) {
        return $query->where('sale_id', request()->route('sale_id'));
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')->singleFile(); // Single file for each payment
    }

    /**
     * Relationship with PaymentMethod
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id', 'id');
    }

    public function creditApplications(): HasMany
    {
        return $this->hasMany(SalePaymentCreditApplication::class, 'sale_payment_id', 'id');
    }
}
