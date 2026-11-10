<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;

class ConsignmentBuyoutReturnApportionment extends BaseModel
{
    protected $table = 'consignment_buyout_return_apportionments';

    protected $guarded = ['id'];

    public const STATUS_DECLARED = 'DECLARED';
    public const STATUS_APPLIED = 'APPLIED';

    protected $casts = [
        'returned_quantity' => 'decimal:3',
        'acquired_quantity' => 'decimal:3',
        'unacquired_quantity' => 'decimal:3',
        'acquired_serial_ids' => 'array',
        'allocations' => 'array',
    ];
}
