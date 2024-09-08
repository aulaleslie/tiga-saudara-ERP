<?php

namespace Modules\Adjustment\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Setting\Entities\Location;

class Transfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'origin_location_id',
        'destination_location_id',
        'created_by',
        'approved_by',
        'rejected_by',
        'dispatched_by',
        'status',
        'created_at',
        'approved_at',
        'rejected_at',
        'dispatched_at',
        'transfer_date',
    ];

    /**
     * Relationships
     */

    // Creator of the transfer
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Approval of the transfer
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Rejected By of the transfer
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    // Dispatcher of the transfer
    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    // Origin Location of the transfer
    public function originLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'origin_location_id');
    }

    // Destination Location of the transfer
    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    // Products in this transfer
    public function products(): HasMany
    {
        return $this->hasMany(TransferProduct::class);
    }
}
