<?php

namespace Modules\Purchase\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Setting\Entities\PaymentMethod;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class PurchasePayment extends BaseModel implements HasMedia
{
    use InteractsWithMedia;

    const STATUS_ACTIVE = 'ACTIVE';
    const STATUS_INVALIDATED = 'INVALIDATED';

    protected $guarded = [];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected array $uppercaseExcept = [
        'note',
    ];

    protected $casts = [
        'invalidated_at' => 'datetime',
        'date' => 'date',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id', 'id')->withArchived();
    }

    public function invalidatedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'invalidated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeByPurchase($query) {
        return $query->where('purchase_id', request()->route('purchase_id'));
    }

    /**
     * Check if payment is active
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if payment is invalidated
     */
    public function isInvalidated(): bool
    {
        return $this->status === self::STATUS_INVALIDATED;
    }

    /**
     * Scope to filter invalidated payments only
     */
    public function scopeInvalidated($query)
    {
        return $query->where('status', self::STATUS_INVALIDATED);
    }

    /**
     * Invalidate all active payments for this purchase.
     * 
     * @param int $purchaseId
     * @param string $source Invalidation source (e.g., 'MODIFY_PURCHASE_SETTLEMENT')
     * @param int|null $sourceId Invalidation source ID (e.g., settlement item ID)
     * @return int Number of payments invalidated
     */
    public static function invalidateAllActiveForPurchase(
        int $purchaseId, 
        string $source, 
        ?int $sourceId = null
    ): int {
        return static::where('purchase_id', $purchaseId)
            ->active()
            ->update([
                'status' => self::STATUS_INVALIDATED,
                'invalidated_at' => now(),
                'invalidated_by' => auth()->id(),
                'invalidation_source' => $source,
                'invalidation_source_id' => $sourceId,
            ]);
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

    /**
     * Check if payment is eligible for physical deletion.
     * Payments with automated invalidation lineage cannot be deleted.
     */
    public function isEligibleForDeletion(): bool
    {
        if (! empty($this->invalidation_source)) {
            return false;
        }

        return true;
    }
}
