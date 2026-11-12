<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;

class ConsignmentHistoricalMapping extends BaseModel
{
    protected $table = 'consignment_historical_mappings';

    /** Machine identifiers, keys and hashes: stored verbatim. */
    protected bool $uppercaseAllText = false;

    protected $guarded = ['id'];

    protected $casts = ['mapping' => 'array', 'source_references' => 'array', 'unresolved_blockers' => 'array', 'confirmed_at' => 'datetime'];
}
