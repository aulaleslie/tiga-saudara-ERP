<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsignmentBuyoutPurchaseLineage extends BaseModel
{
    protected $table = 'consignment_buyout_purchase_lineages';

    protected $guarded = ['id'];

    protected $casts = [
        'base_quantity' => 'decimal:3',
        'allocation_snapshot' => 'array',
    ];

}
