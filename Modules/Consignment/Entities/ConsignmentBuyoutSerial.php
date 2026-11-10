<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsignmentBuyoutSerial extends BaseModel
{
    protected $table = 'consignment_buyout_serials';

    protected $guarded = ['id'];

    protected $casts = [

    ];

    public function buyout(): BelongsTo
    {
        return $this->belongsTo(ConsignmentBuyout::class, 'consignment_buyout_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ConsignmentBuyoutLine::class, 'consignment_buyout_line_id');
    }

    public function serialNumber(): BelongsTo
    {
        return $this->belongsTo(\Modules\Product\Entities\ProductSerialNumber::class, 'product_serial_number_id');
    }

}
