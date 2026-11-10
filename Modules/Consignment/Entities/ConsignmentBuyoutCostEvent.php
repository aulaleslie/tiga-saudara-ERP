<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsignmentBuyoutCostEvent extends BaseModel
{
    protected $table = 'consignment_buyout_cost_events';

    protected $guarded = ['id'];

    protected $casts = [
        'effective_at' => 'datetime',
    ];

}
