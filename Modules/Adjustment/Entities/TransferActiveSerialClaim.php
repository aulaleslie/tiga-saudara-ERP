<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\ProductSerialNumber;

class TransferActiveSerialClaim extends BaseModel
{
    protected $table = 'transfer_active_serial_claims';

    protected $fillable = [
        'product_serial_number_id',
        'transfer_movement_id',
        'transfer_movement_serial_id',
    ];

    public function serialNumber(): BelongsTo
    {
        return $this->belongsTo(ProductSerialNumber::class, 'product_serial_number_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(TransferMovement::class, 'transfer_movement_id');
    }

    public function movementSerial(): BelongsTo
    {
        return $this->belongsTo(TransferMovementSerial::class, 'transfer_movement_serial_id');
    }
}
