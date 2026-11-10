<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsignmentSoldReceiptAttribution extends BaseModel
{
    protected $table = 'consignment_sold_receipt_attributions';

    protected $guarded = ['id'];

    protected $casts = [
        'attributed_base_quantity' => 'decimal:3',
        'returned_base_quantity' => 'decimal:3',
        'returned_acquired_quantity' => 'decimal:3',
    ];

}
