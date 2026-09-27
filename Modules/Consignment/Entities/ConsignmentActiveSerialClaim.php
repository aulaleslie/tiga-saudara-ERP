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
    ];

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
