<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;

class ConsignmentTransferLotSelection extends BaseModel
{
    protected $table = 'consignment_transfer_lot_selections';

    /** Machine identifiers, keys and hashes: stored verbatim. */
    protected bool $uppercaseAllText = false;

    protected $guarded = ['id'];

    protected $casts = ['base_quantity' => 'decimal:3', 'confirmed_at' => 'datetime'];
}
