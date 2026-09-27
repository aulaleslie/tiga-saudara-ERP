<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\ProductSerialNumber;

class TransferApprovalAllocationSerial extends BaseModel
{
    protected $fillable = [
        'transfer_approval_allocation_id',
        'transfer_id',
        'request_revision_id',
        'configuration_revision',
        'product_serial_number_id',
    ];

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(TransferApprovalAllocation::class, 'transfer_approval_allocation_id');
    }

    public function serialNumber(): BelongsTo
    {
        return $this->belongsTo(ProductSerialNumber::class, 'product_serial_number_id');
    }
}
