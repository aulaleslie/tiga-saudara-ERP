<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsignmentBuyoutOwnershipEvent extends BaseModel
{
    protected $table = 'consignment_buyout_ownership_events';

    protected $guarded = ['id'];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

}
