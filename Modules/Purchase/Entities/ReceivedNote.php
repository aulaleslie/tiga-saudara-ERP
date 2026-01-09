<?php

namespace Modules\Purchase\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Setting\Entities\Location;

class ReceivedNote extends BaseModel
{
    const STATUS_PENDING = 'PENDING';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_REJECTED = 'REJECTED';

    // Define fillable fields for mass assignment
    protected $fillable = [
        'po_id',
        'external_delivery_number',
        'internal_invoice_number',
        'date',
        'location_id',
        'status',
        'approved_at',
        'approved_by',
        'rejection_reason',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    /**
     * Relationship with Purchase
     * A ReceivedNote belongs to a Purchase.
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'po_id');
    }

    /**
     * Relationship with Location
     * A ReceivedNote belongs to a Location.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Relationship with User who approved/rejected this receiving.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function receivedNoteDetails(): HasMany
    {
        return $this->hasMany(ReceivedNoteDetail::class);
    }

    public function scopeByPurchase($query) {
        return $query->where('po_id', request()->route('purchase_id'));
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }
}
