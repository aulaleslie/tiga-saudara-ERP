<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\Entities\Product;

class TransferMovementReturnObligation extends BaseModel
{
    public const STATUS_OUTSTANDING = 'OUTSTANDING';
    public const STATUS_FULFILLED   = 'FULFILLED';

    public const STATUSES = [
        self::STATUS_OUTSTANDING,
        self::STATUS_FULFILLED,
    ];

    public const CONDITION_GOOD     = 'GOOD';
    public const CONDITION_BREAKAGE = 'BREAKAGE';

    protected $fillable = [
        'transfer_id',
        'transfer_route_policy_id',
        'receipt_movement_id',
        'product_id',
        'stock_condition',
        'required_quantity',
        'returned_quantity',
        'status',
    ];

    protected $casts = [
        'required_quantity' => 'decimal:4',
        'returned_quantity' => 'decimal:4',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function routePolicy(): BelongsTo
    {
        return $this->belongsTo(TransferRoutePolicy::class, 'transfer_route_policy_id');
    }

    public function receiptMovement(): BelongsTo
    {
        return $this->belongsTo(TransferMovement::class, 'receipt_movement_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(TransferReturnObligationReservation::class, 'transfer_movement_return_obligation_id');
    }

    public function activeReservations(): HasMany
    {
        return $this->reservations()->where('status', TransferReturnObligationReservation::STATUS_ACTIVE);
    }

    /**
     * Sum of quantity across all ACTIVE reservations (approved return-dispatch batches still in transit).
     * Caller MUST have already locked this obligation and its reservation rows for update.
     *
     * Folds via bcadd() from '0.0000' rather than Collection::sum(), which coerces each value to a
     * PHP float before adding — silently reintroducing floating-point rounding error into an
     * otherwise exact-decimal reservation ledger.
     */
    public function activeInTransitQuantity(): string
    {
        $total = '0.0000';
        foreach ($this->activeReservations as $reservation) {
            $total = bcadd($total, (string) $reservation->quantity, 4);
        }

        return $total;
    }

    /**
     * Remaining capacity available for new approvals: required - returned - active in transit.
     * Never negative.
     */
    public function availableCapacity(): string
    {
        $committed = bcadd((string) $this->returned_quantity, $this->activeInTransitQuantity(), 4);
        $remaining = bcsub((string) $this->required_quantity, $committed, 4);

        return bccomp($remaining, '0', 4) > 0 ? $remaining : '0.0000';
    }

    public function outstandingQuantity(): string
    {
        $remaining = bcsub((string) $this->required_quantity, (string) $this->returned_quantity, 4);

        return bccomp($remaining, '0', 4) > 0 ? $remaining : '0.0000';
    }
}
