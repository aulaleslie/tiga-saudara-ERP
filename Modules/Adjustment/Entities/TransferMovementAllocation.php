<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;

/**
 * Immutable applied route evidence for a version 3 movement. DISPATCH rows
 * freeze source/destination identities, cross-business marker, applied
 * source buckets and destination tax classification; RECEIPT and
 * CANCELLATION rows reference the exact DISPATCH row they settle.
 */
class TransferMovementAllocation extends BaseModel
{
    public const KIND_DISPATCH     = 'DISPATCH';
    public const KIND_RECEIPT      = 'RECEIPT';
    public const KIND_CANCELLATION = 'CANCELLATION';

    protected $fillable = [
        'transfer_id',
        'transfer_movement_id',
        'transfer_movement_line_id',
        'dispatch_allocation_id',
        'approval_allocation_id',
        'kind',
        'product_id',
        'source_location_id',
        'destination_location_id',
        'source_setting_id',
        'destination_setting_id',
        'cross_business',
        'stock_condition',
        'quantity',
        'applied_quantity_non_tax',
        'applied_quantity_tax',
        'applied_quantity_broken_non_tax',
        'applied_quantity_broken_tax',
        'destination_classification',
        'tax_id',
        'tax_name',
        'tax_rate',
        'tax_resolver_provenance',
        'stock_snapshot_before',
        'stock_snapshot_after',
        'inventory_transaction_id',
        'actor_id',
    ];

    protected $casts = [
        'cross_business'                  => 'boolean',
        'quantity'                        => 'integer',
        'applied_quantity_non_tax'        => 'integer',
        'applied_quantity_tax'            => 'integer',
        'applied_quantity_broken_non_tax' => 'integer',
        'applied_quantity_broken_tax'     => 'integer',
        'tax_rate'                        => 'decimal:4',
        'stock_snapshot_before'           => 'array',
        'stock_snapshot_after'            => 'array',
    ];

    public function movement(): BelongsTo
    {
        return $this->belongsTo(TransferMovement::class, 'transfer_movement_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(TransferMovementLine::class, 'transfer_movement_line_id');
    }

    public function dispatchAllocation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'dispatch_allocation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function serials(): HasMany
    {
        return $this->hasMany(TransferMovementSerial::class, 'transfer_movement_allocation_id');
    }

    /**
     * @return array{non_tax: int, tax: int, broken_non_tax: int, broken_tax: int}
     */
    public function appliedBuckets(): array
    {
        return [
            'non_tax'        => (int) $this->applied_quantity_non_tax,
            'tax'            => (int) $this->applied_quantity_tax,
            'broken_non_tax' => (int) $this->applied_quantity_broken_non_tax,
            'broken_tax'     => (int) $this->applied_quantity_broken_tax,
        ];
    }
}
