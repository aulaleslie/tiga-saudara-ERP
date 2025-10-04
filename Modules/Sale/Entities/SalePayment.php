<?php

namespace Modules\Sale\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Setting\Entities\PaymentMethod;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class SalePayment extends BaseModel implements HasMedia
{
    use InteractsWithMedia;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
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
}
