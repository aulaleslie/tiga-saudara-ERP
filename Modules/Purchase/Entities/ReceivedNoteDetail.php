<?php

namespace Modules\Purchase\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;

class ReceivedNoteDetail extends Model
{
    use HasFactory;

    // Define fillable fields for mass assignment
    protected $fillable = [
        'received_note_id',
        'po_detail_id',
        'quantity_received',
    ];

    /**
     * Relationship with ReceivedNote
     * A ReceivedNoteDetail belongs to a ReceivedNote.
     */
    public function receivedNote(): BelongsTo
    {
        return $this->belongsTo(ReceivedNote::class);
    }

    /**
     * Relationship with PurchaseDetail
     * A ReceivedNoteDetail belongs to a PurchaseDetail.
     */
    public function purchaseDetail(): BelongsTo
    {
        return $this->belongsTo(PurchaseDetail::class, 'po_detail_id');
    }

    /**
     * Relationship with Product
     * A ReceivedNoteDetail is related to a Product through the purchase detail.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Relationship with ProductSerialNumber
     * A ReceivedNoteDetail can have multiple ProductSerialNumbers.
     */
    public function productSerialNumbers(): HasMany
    {
        return $this->hasMany(ProductSerialNumber::class, 'received_note_detail_id');
    }
}
