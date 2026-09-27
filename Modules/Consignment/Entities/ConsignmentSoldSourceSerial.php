<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\ProductSerialNumber;

class ConsignmentSoldSourceSerial extends BaseModel
{
    use HasFactory;

    protected $table = 'consignment_sold_source_serials';

    protected $fillable = [
        'consignment_sold_source_id',
        'product_serial_number_id',
    ];

    public function soldSource(): BelongsTo
    {
        return $this->belongsTo(ConsignmentSoldSource::class, 'consignment_sold_source_id');
    }

    public function serialNumber(): BelongsTo
    {
        return $this->belongsTo(ProductSerialNumber::class, 'product_serial_number_id');
    }
}
