<?php

namespace Modules\Purchase\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceivedNote extends Model
{
    use HasFactory;

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

    /**
     * Relationship with ReceivedNoteDetail
     * A ReceivedNote has many ReceivedNoteDetails.
     */
    public function details(): HasMany
    {
        return $this->hasMany(ReceivedNoteDetail::class);
    }
}
