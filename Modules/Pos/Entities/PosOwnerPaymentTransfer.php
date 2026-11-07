<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Setting\Entities\PaymentMethod;

class PosOwnerPaymentTransfer extends BaseModel
{
    public const SOURCE_LIVE_EXECUTION = 'LIVE_EXECUTION';
    public const SOURCE_HISTORICAL_REPAIR = 'HISTORICAL_REPAIR';

    protected bool $uppercaseAllText = false;

    protected $table = 'pos_owner_payment_transfers';

    protected $fillable = [
        'execution_key',
        'pos_transaction_id',
        'pos_checkout_id',
        'pos_return_id',
        'pos_return_line_id',
        'sale_return_id',
        'sale_return_detail_id',
        'source_sale_id',
        'replacement_sale_id',
        'original_sale_payment_id',
        'predecessor_transfer_id',
        'invalidated_synthetic_payment_id',
        'payment_method_id',
        'payment_method',
        'amount',
        'amount_minor',
        'transfer_source',
        'actor_id',
        'executed_at',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_minor' => 'integer',
        'executed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public static function buildExecutionKey(
        int $saleReturnDetailId,
        int $originalSalePaymentId,
        ?int $predecessorTransferId = null
    ): string {
        return sprintf(
            'srd:%d:pay:%d:pred:%d',
            $saleReturnDetailId,
            $originalSalePaymentId,
            (int) ($predecessorTransferId ?? 0)
        );
    }

    protected static function booted(): void
    {
        static::saving(function (self $transfer): void {
            $saleReturnDetailId = (int) ($transfer->sale_return_detail_id ?? 0);
            $originalPaymentId = (int) ($transfer->original_sale_payment_id ?? 0);
            $sourceSaleId = (int) ($transfer->source_sale_id ?? 0);
            $replacementSaleId = (int) ($transfer->replacement_sale_id ?? 0);
            $predecessorId = $transfer->predecessor_transfer_id ? (int) $transfer->predecessor_transfer_id : null;

            if ($saleReturnDetailId <= 0 || $originalPaymentId <= 0 || $sourceSaleId <= 0 || $replacementSaleId <= 0) {
                throw new \InvalidArgumentException('PosOwnerPaymentTransfer requires valid sale_return_detail_id, original_sale_payment_id, source_sale_id, and replacement_sale_id.');
            }

            if ($sourceSaleId === $replacementSaleId) {
                throw new \InvalidArgumentException('PosOwnerPaymentTransfer source_sale_id and replacement_sale_id must belong to distinct sales.');
            }

            if (empty($transfer->execution_key)) {
                $transfer->execution_key = self::buildExecutionKey($saleReturnDetailId, $originalPaymentId, $predecessorId);
            }

            $amountFloat = round((float) ($transfer->amount ?? 0), 2);
            $amountMinor = $transfer->amount_minor !== null
                ? (int) $transfer->amount_minor
                : (int) round($amountFloat * 100);

            if ($amountMinor <= 0 || $amountFloat <= 0) {
                throw new \InvalidArgumentException('PosOwnerPaymentTransfer amount must be strictly positive.');
            }

            if ((int) round($amountFloat * 100) !== $amountMinor) {
                throw new \InvalidArgumentException('PosOwnerPaymentTransfer amount and amount_minor must agree in minor units.');
            }

            $transfer->amount = $amountFloat;
            $transfer->amount_minor = $amountMinor;

            // Locking read: sees the latest committed row (not the transaction snapshot) and serializes concurrent allocations of one payment.
            $originalPayment = SalePayment::query()->lockForUpdate()->find($originalPaymentId);
            if (! $originalPayment) {
                throw new \InvalidArgumentException("Original SalePayment #{$originalPaymentId} does not exist.");
            }

            if ($originalPayment->isInvalidated()) {
                throw new \InvalidArgumentException("Original SalePayment #{$originalPaymentId} is invalidated and cannot back an owner payment transfer.");
            }

            $originalPaymentMinor = (int) round(((float) $originalPayment->amount) * 100);

            if ($predecessorId === null) {
                if ((int) $originalPayment->sale_id !== $sourceSaleId) {
                    throw new \InvalidArgumentException("Original SalePayment #{$originalPaymentId} does not belong to source Sale #{$sourceSaleId}.");
                }

                $existingTransferredMinor = (int) self::query()
                    ->where('original_sale_payment_id', $originalPaymentId)
                    ->whereNull('predecessor_transfer_id')
                    ->when($transfer->exists, fn ($q) => $q->where('id', '!=', $transfer->id))
                    ->lockForUpdate()
                    ->get(['amount_minor'])
                    ->sum('amount_minor');

                if ($existingTransferredMinor + $amountMinor > $originalPaymentMinor) {
                    throw new \InvalidArgumentException("Owner payment transfer exceeds available amount on original SalePayment #{$originalPaymentId}.");
                }
            } else {
                $predecessor = self::query()->lockForUpdate()->find($predecessorId);
                if (! $predecessor) {
                    throw new \InvalidArgumentException("Predecessor PosOwnerPaymentTransfer #{$predecessorId} does not exist.");
                }

                if ((int) $predecessor->replacement_sale_id !== $sourceSaleId) {
                    throw new \InvalidArgumentException("Predecessor transfer #{$predecessorId} replacement_sale_id does not match current source_sale_id #{$sourceSaleId}.");
                }

                if ((int) $predecessor->original_sale_payment_id !== $originalPaymentId) {
                    throw new \InvalidArgumentException("Predecessor transfer #{$predecessorId} original_sale_payment_id does not match #{$originalPaymentId}.");
                }

                $existingFromPredecessorMinor = (int) self::query()
                    ->where('predecessor_transfer_id', $predecessorId)
                    ->when($transfer->exists, fn ($q) => $q->where('id', '!=', $transfer->id))
                    ->lockForUpdate()
                    ->get(['amount_minor'])
                    ->sum('amount_minor');

                if ($existingFromPredecessorMinor + $amountMinor > (int) $predecessor->amount_minor) {
                    throw new \InvalidArgumentException("Owner payment transfer exceeds available amount on predecessor transfer #{$predecessorId}.");
                }
            }
        });
    }

    public function posTransaction(): BelongsTo
    {
        return $this->belongsTo(PosTransaction::class, 'pos_transaction_id');
    }

    public function posCheckout(): BelongsTo
    {
        return $this->belongsTo(PosCheckout::class, 'pos_checkout_id');
    }

    public function posReturn(): BelongsTo
    {
        return $this->belongsTo(PosReturn::class, 'pos_return_id');
    }

    public function posReturnLine(): BelongsTo
    {
        return $this->belongsTo(PosReturnLine::class, 'pos_return_line_id');
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class, 'sale_return_id');
    }

    public function saleReturnDetail(): BelongsTo
    {
        return $this->belongsTo(SaleReturnDetail::class, 'sale_return_detail_id');
    }

    public function sourceSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'source_sale_id')->withArchived();
    }

    public function replacementSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'replacement_sale_id')->withArchived();
    }

    public function originalSalePayment(): BelongsTo
    {
        return $this->belongsTo(SalePayment::class, 'original_sale_payment_id');
    }

    public function predecessorTransfer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'predecessor_transfer_id');
    }

    public function successorTransfers(): HasMany
    {
        return $this->hasMany(self::class, 'predecessor_transfer_id');
    }

    public function invalidatedSyntheticPayment(): BelongsTo
    {
        return $this->belongsTo(SalePayment::class, 'invalidated_synthetic_payment_id');
    }

    public function paymentMethodRecord(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
