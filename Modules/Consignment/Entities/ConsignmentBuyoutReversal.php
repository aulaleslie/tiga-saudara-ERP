<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsignmentBuyoutReversal extends BaseModel
{
    protected $table = 'consignment_buyout_reversals';

    protected $guarded = ['id'];

    protected $casts = [
        'eligibility_snapshot' => 'array',
        'reversed_at' => 'datetime',
    ];

}
