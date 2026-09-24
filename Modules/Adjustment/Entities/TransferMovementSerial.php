<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Tax;

class TransferMovementSerial extends BaseModel
{
    public const CUSTODY_INACTIVE   = 'INACTIVE';
    public const CUSTODY_IN_TRANSIT = 'IN_TRANSIT';
    public const CUSTODY_CLOSED     = 'CLOSED';
    // Version 3 dispatch cancellation closes custody without delivery.
    public const CUSTODY_CANCELLED  = 'CANCELLED';

    public const CUSTODY_STATUSES = [
        self::CUSTODY_INACTIVE,
        self::CUSTODY_IN_TRANSIT,
        self::CUSTODY_CLOSED,
        self::CUSTODY_CANCELLED,
    ];

    public const CONDITION_GOOD     = 'GOOD';
    public const CONDITION_BREAKAGE = 'BREAKAGE';

    public const CONDITIONS = [
        self::CONDITION_GOOD,
        self::CONDITION_BREAKAGE,
    ];

    protected $fillable = [
        'transfer_movement_id',
        'transfer_movement_line_id',
        'product_id',
        'product_serial_number_id',
        'serial_number',
        'stock_condition',
        'tax_id',
        'tax_name',
        'tax_rate',
        'previous_tax_id',
        'previous_tax_name',
        'previous_tax_rate',
        'transit_custody_status',
        'custody_started_at',
        'custody_closed_at',
        'origin_location_id',
        'destination_location_id',
        'transfer_movement_allocation_id',
    ];

    protected $casts = [
        'serial_number'      => 'string',
        'tax_rate'           => 'decimal:4',
        'previous_tax_rate'  => 'decimal:4',
        'custody_started_at' => 'datetime',
        'custody_closed_at'  => 'datetime',
    ];

    public static function normalize(string $serialNumber): string
    {
        return mb_strtoupper(trim($serialNumber), 'UTF-8');
    }

    public function setSerialNumberAttribute($value): void
    {
        $this->attributes['serial_number'] = is_null($value) ? null : self::normalize((string) $value);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(TransferMovement::class, 'transfer_movement_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(TransferMovementLine::class, 'transfer_movement_line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function liveSerialNumber(): BelongsTo
    {
        return $this->belongsTo(ProductSerialNumber::class, 'product_serial_number_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function previousTax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'previous_tax_id');
    }

    public function originLocation(): BelongsTo
    {
        return $this->belongsTo(\Modules\Setting\Entities\Location::class, 'origin_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(\Modules\Setting\Entities\Location::class, 'destination_location_id');
    }

    public function activeClaim(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TransferActiveSerialClaim::class, 'transfer_movement_serial_id');
    }
}
