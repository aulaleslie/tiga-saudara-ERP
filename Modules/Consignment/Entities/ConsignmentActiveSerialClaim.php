<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\ProductSerialNumber;

class ConsignmentActiveSerialClaim extends BaseModel
{
    use HasFactory;

    protected $table = 'consignment_active_serial_claims';

    protected $fillable = [
        'product_serial_number_id',
        'consignment_billing_confirmation_id',
        'consignment_serialized_allocation_id',
        'owner_type',
        'consignment_buyout_id',
        'consignment_buyout_serial_id',
    ];

    public const OWNER_LEGACY = 'LEGACY';
    public const OWNER_BUYOUT = 'BUYOUT';

    protected static function booted(): void
    {
        // Exactly one complete, consistent owner (the database also enforces the shape).
        static::saving(function (self $claim) {
            $owner = $claim->owner_type ?: self::OWNER_LEGACY;
            $legacy = [$claim->consignment_billing_confirmation_id, $claim->consignment_serialized_allocation_id];
            $buyout = [$claim->consignment_buyout_id, $claim->consignment_buyout_serial_id];

            if ($owner === self::OWNER_LEGACY) {
                if (in_array(null, $legacy, true) || array_filter($buyout, fn ($v) => $v !== null)) {
                    throw new \DomainException('A legacy serial claim needs a confirmation and allocation, and no buyout owner.');
                }
                $alloc = ConsignmentSerializedAllocation::find($claim->consignment_serialized_allocation_id);
                if (! $alloc
                    || (int) $alloc->consignment_billing_confirmation_id !== (int) $claim->consignment_billing_confirmation_id
                    || (int) $alloc->product_serial_number_id !== (int) $claim->product_serial_number_id) {
                    throw new \DomainException('Legacy claim allocation does not match its confirmation and serial.');
                }
            } elseif ($owner === self::OWNER_BUYOUT) {
                if (in_array(null, $buyout, true) || array_filter($legacy, fn ($v) => $v !== null)) {
                    throw new \DomainException('A buyout serial claim needs a buyout and buyout serial, and no legacy owner.');
                }
                $serial = ConsignmentBuyoutSerial::find($claim->consignment_buyout_serial_id);
                if (! $serial
                    || (int) $serial->consignment_buyout_id !== (int) $claim->consignment_buyout_id
                    || (int) $serial->product_serial_number_id !== (int) $claim->product_serial_number_id) {
                    throw new \DomainException('Buyout claim serial does not match its buyout and serial.');
                }
            } else {
                throw new \DomainException('Unknown serial claim owner type.');
            }
            $claim->owner_type = $owner;
        });
    }

    public function serialNumber(): BelongsTo
    {
        return $this->belongsTo(ProductSerialNumber::class, 'product_serial_number_id');
    }

    public function confirmation(): BelongsTo
    {
        return $this->belongsTo(ConsignmentBillingConfirmation::class, 'consignment_billing_confirmation_id');
    }

    public function serializedAllocation(): BelongsTo
    {
        return $this->belongsTo(ConsignmentSerializedAllocation::class, 'consignment_serialized_allocation_id');
    }
}
