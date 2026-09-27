<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Location;

/**
 * Pivot model representing one selected location within a multi-location
 * Stock Opname document.
 *
 * The (adjustment_id, location_id) pair is unique-constrained at the DB
 * level. Position records the stable selection order within the document.
 */
class AdjustmentLocation extends BaseModel
{
    protected $table = 'adjustment_locations';

    protected $guarded = [];

    protected $casts = [
        'position' => 'integer',
    ];

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(Adjustment::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
