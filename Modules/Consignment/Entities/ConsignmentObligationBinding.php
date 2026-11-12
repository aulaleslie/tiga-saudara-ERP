<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;

class ConsignmentObligationBinding extends BaseModel
{
    protected $table = 'consignment_obligation_bindings';

    /** Machine identifiers, keys and hashes: stored verbatim. */
    protected bool $uppercaseAllText = false;

    protected $guarded = ['id'];

    protected $casts = ['base_quantity' => 'decimal:3', 'serial_identities' => 'array', 'custody_path' => 'array', 'confirmed_at' => 'datetime'];
}
