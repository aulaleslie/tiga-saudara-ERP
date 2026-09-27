<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\ProductSerialNumber;

class ConsignmentSerializedAllocation extends BaseModel
{
    use HasFactory;
    use \Modules\Consignment\Entities\Concerns\ResolvesGuardConfirmationStatus;

    protected $table = 'consignment_serialized_allocations';

    public const STATUS_RESERVED = 'RESERVED';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_RELEASED = 'RELEASED';

    protected $fillable = [
        'consignment_billing_confirmation_id',
        'consignment_billing_confirmation_line_id',
        'consignment_sold_source_id',
        'product_serial_number_id',
        'consignment_receiving_detail_id',
        'status',
    ];

    protected static function boot()
    {
        parent::boot();

        static::updating(function ($sa) {
            $status = $sa->guardConfirmationStatus('confirmation', 'consignment_billing_confirmation_id');
            if ($sa->guardStatusIsApproved($status) && $sa->getOriginal('status') === self::STATUS_APPROVED) {
                throw new \DomainException("Cannot modify serialized allocations of an approved confirmation.");
            }
        });

        static::deleting(function ($sa) {
            $status = $sa->guardConfirmationStatus('confirmation', 'consignment_billing_confirmation_id');
            if ($sa->guardStatusIsApproved($status) || $sa->guardStatusIsWaitingApproval($status)) {
                throw new \DomainException("Cannot delete serialized allocations of a submitted or approved confirmation.");
            }
        });
    }

    public function confirmation(): BelongsTo
    {
        return $this->belongsTo(ConsignmentBillingConfirmation::class, 'consignment_billing_confirmation_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ConsignmentBillingConfirmationLine::class, 'consignment_billing_confirmation_line_id');
    }

    public function soldSource(): BelongsTo
    {
        return $this->belongsTo(ConsignmentSoldSource::class, 'consignment_sold_source_id');
    }

    public function serialNumber(): BelongsTo
    {
        return $this->belongsTo(ProductSerialNumber::class, 'product_serial_number_id');
    }

    public function productSerialNumber(): BelongsTo
    {
        return $this->serialNumber();
    }

    public function receivingDetail(): BelongsTo
    {
        return $this->belongsTo(ConsignmentReceivingDetail::class, 'consignment_receiving_detail_id');
    }
}
