<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsignmentBuyoutMovement extends BaseModel
{
    protected $table = 'consignment_buyout_movements';

    protected $guarded = ['id'];

    protected $casts = [
        'base_quantity' => 'decimal:3',
        'posted_at' => 'datetime',
    ];

}
