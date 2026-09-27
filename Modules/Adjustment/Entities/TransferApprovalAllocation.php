<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;

/**
 * One approver-configured route for a version 3 request revision. Rows are
 * written once per saved configuration revision; the current plan is the set
 * matching the transfer's current request revision and configuration revision.
 */
class TransferApprovalAllocation extends BaseModel
{
    protected $fillable = [
        'transfer_id',
        'request_revision_id',
        'configuration_revision',
        'product_id',
        'source_location_id',
        'destination_location_id',
        'quantity',
        'sort_order',
        'saved_by',
    ];

    protected $casts = [
        'configuration_revision' => 'integer',
        'quantity'               => 'integer',
        'sort_order'             => 'integer',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function serials(): HasMany
    {
        return $this->hasMany(TransferApprovalAllocationSerial::class);
    }
}
