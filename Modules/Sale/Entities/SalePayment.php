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

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_INVALIDATED = 'INVALIDATED';
    public const SPLIT_TRACE_NOTE_PREFIX = 'Auto-split from invalidated payment';

    protected $guarded = [];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected array $uppercaseExcept = [
        'note',
    ];

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
        'status',
        'invalidated_at',
        'invalidated_by',
        'invalidation_source',
        'invalidation_source_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
        'stage_order' => 'integer',
        'invalidated_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id', 'id')->withArchived();
    }

    public function getDateAttribute($value): string
    {
        return Carbon::parse($value)->format('d M, Y');
    }

    public function invalidatedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'invalidated_by');
    }

    public function scopeBySale($query) {
        return $query->where('sale_id', request()->route('sale_id'));
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeInvalidated($query)
    {
        return $query->where('status', self::STATUS_INVALIDATED);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isInvalidated(): bool
    {
        return $this->status === self::STATUS_INVALIDATED;
    }

    public static function invalidateAllActiveForSale(int $saleId, string $source, ?int $sourceId = null): int
    {
        return static::query()
            ->where('sale_id', $saleId)
            ->active()
            ->update([
                'status' => self::STATUS_INVALIDATED,
                'invalidated_at' => now(),
                'invalidated_by' => auth()->id(),
                'invalidation_source' => $source,
                'invalidation_source_id' => $sourceId,
            ]);
    }

    public static function reconcileActivePaymentsForSale(int $saleId, float $targetAmount, string $source, ?int $sourceId = null): float
    {
        $targetAmount = round(max($targetAmount, 0), 2);

        $activePayments = static::query()
            ->where('sale_id', $saleId)
            ->active()
            ->orderByDesc('stage_order')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        $activeTotal = round((float) $activePayments->sum(fn (self $payment) => (float) $payment->amount), 2);
        if ($activeTotal <= $targetAmount + 0.01) {
            return $activeTotal;
        }

        $surplusAmount = round($activeTotal - $targetAmount, 2);

        foreach ($activePayments as $payment) {
            if ($surplusAmount <= 0.01) {
                break;
            }

            $paymentAmount = round((float) $payment->amount, 2);
            $retainedAmount = round($paymentAmount - $surplusAmount, 2);

            static::invalidateActivePayment($payment, $source, $sourceId);

            if ($retainedAmount > 0.01) {
                static::createReplacementActivePayment($payment, $retainedAmount, $source, $sourceId);
                $surplusAmount = 0.0;

                break;
            }

            $surplusAmount = round($surplusAmount - $paymentAmount, 2);
        }

        return round((float) static::query()
            ->where('sale_id', $saleId)
            ->active()
            ->sum('amount'), 2);
    }

    protected static function invalidateActivePayment(self $payment, string $source, ?int $sourceId = null): void
    {
        $payment->forceFill([
            'status' => self::STATUS_INVALIDATED,
            'invalidated_at' => now(),
            'invalidated_by' => auth()->id(),
            'invalidation_source' => $source,
            'invalidation_source_id' => $sourceId,
        ])->save();
    }

    protected static function createReplacementActivePayment(self $payment, float $amount, string $source, ?int $sourceId = null): self
    {
        $traceNote = sprintf(
            '%s #%d via %s%s',
            self::SPLIT_TRACE_NOTE_PREFIX,
            $payment->id,
            $source,
            $sourceId !== null ? ' #' . $sourceId : ''
        );

        $note = collect([
            $payment->note !== null && $payment->note !== '' ? (string) $payment->note : null,
            $traceNote,
        ])->filter()->implode(PHP_EOL);

        return static::query()->create([
            'sale_id' => $payment->sale_id,
            'payment_method_id' => $payment->payment_method_id,
            'amount' => round($amount, 2),
            'date' => $payment->getRawOriginal('date') ?: $payment->date,
            'reference' => $payment->reference,
            'payment_method' => $payment->payment_method,
            'note' => $note !== '' ? $note : null,
            'stage_order' => $payment->stage_order,
            'edc_reference' => $payment->edc_reference,
            'idempotency_key' => null,
            'pos_session_id' => $payment->getAttribute('pos_session_id'),
            'pos_receipt_id' => $payment->getAttribute('pos_receipt_id'),
            'status' => self::STATUS_ACTIVE,
        ]);
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

    /**
     * Check if payment is eligible for physical deletion.
     * Payments with credit applications or automated invalidation lineage cannot be deleted.
     */
    public function isEligibleForDeletion(): bool
    {
        if (! empty($this->invalidation_source)) {
            return false;
        }

        if (isset($this->credit_applications_count)) {
            return (int) $this->credit_applications_count === 0;
        }

        if ($this->relationLoaded('creditApplications')) {
            return $this->creditApplications->isEmpty();
        }

        return ! $this->creditApplications()->exists();
    }
}
