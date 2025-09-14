<?php

namespace Modules\Purchase\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceivedNote extends BaseModel
{
    // Define fillable fields for mass assignment
    protected $fillable = [
        'po_id',
        'external_delivery_number',
        'internal_invoice_number',
        'date',
    ];

    /**
     * Relationship with Purchase
     * A ReceivedNote belongs to a Purchase.
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'po_id');
    }

    public function receivedNoteDetails(): HasMany
    {
        return $this->hasMany(ReceivedNoteDetail::class);
    }

    public function scopeByPurchase($query) {
        return $query->where('po_id', request()->route('purchase_id'));
    }
}
