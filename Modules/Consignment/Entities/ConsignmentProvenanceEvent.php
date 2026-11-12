<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;

class ConsignmentProvenanceEvent extends BaseModel
{
    public const SOURCE_TRANSFER_ALLOCATION = 'TRANSFER_MOVEMENT_ALLOCATION';

    public const STATE_SELLABLE = 'SELLABLE';
    public const STATE_HELD = 'HELD';
    public const STATE_TRANSIT = 'TRANSIT';
    public const STATE_SOLD = 'SOLD';
    public const STATE_ACQUIRED = 'ACQUIRED';
    public const STATE_EXTERNAL = 'EXTERNAL';

    protected $table = 'consignment_provenance_events';

    /** Machine identifiers, keys and hashes: stored verbatim. */
    protected bool $uppercaseAllText = false;

    protected $guarded = ['id'];

    protected $casts = ['base_quantity' => 'decimal:3', 'effective_at' => 'datetime'];
}
