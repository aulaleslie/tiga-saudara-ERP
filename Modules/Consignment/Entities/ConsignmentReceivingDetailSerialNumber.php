<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;

class ConsignmentReceivingDetailSerialNumber extends BaseModel
{
    protected $table = 'consignment_receiving_detail_serial_numbers';

    protected $fillable = [
        'consignment_receiving_detail_id',
        'product_serial_number_id',
        'source_history_id',
        'reversal_history_id',
        'linked_at',
    ];

    protected $casts = [
        'linked_at' => 'datetime',
    ];

    public function detail(): BelongsTo
    {
        return $this->belongsTo(ConsignmentReceivingDetail::class, 'consignment_receiving_detail_id');
    }

    public function serialNumber(): BelongsTo
    {
        return $this->belongsTo(ProductSerialNumber::class, 'product_serial_number_id');
    }

    public function sourceHistory(): BelongsTo
    {
        return $this->belongsTo(SerialNumberHistory::class, 'source_history_id');
    }

    public function reversalHistory(): BelongsTo
    {
        return $this->belongsTo(SerialNumberHistory::class, 'reversal_history_id');
    }
}
