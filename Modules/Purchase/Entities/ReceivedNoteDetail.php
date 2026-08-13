<?php

namespace Modules\Purchase\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\Transaction;

class ReceivedNoteDetail extends BaseModel
{
    // Define fillable fields for mass assignment
    protected $fillable = [
        'received_note_id',
        'po_detail_id',
        'quantity_received',
        'pending_serial_numbers',
        'note',
    ];

    protected $casts = [
        'pending_serial_numbers' => 'array',
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
     * The BUY inventory transaction created when this receiving detail was approved.
     * Populated for new approvals; NULL for legacy receipts before provenance tracking.
     */
    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class, 'received_note_detail_id');
    }

    /**
     * UOM normalization lines that reference this receiving detail.
     */
    public function uomNormalizationLines(): HasMany
    {
        return $this->hasMany(UomNormalizationLine::class, 'received_note_detail_id');
    }

    /**
     * Relationship with ProductSerialNumber
     * A ReceivedNoteDetail can have multiple ProductSerialNumbers.
     */
    public function productSerialNumbers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(ProductSerialNumber::class, 'received_note_detail_serial_numbers', 'received_note_detail_id', 'product_serial_number_id')
            ->withPivot(['id', 'source_history_id', 'linked_at'])
            ->withTimestamps();
    }

    /**
     * Legacy Relationship (One-to-Many).
     * @deprecated Use productSerialNumbers() instead.
     */
    public function legacyProductSerialNumbers(): HasMany
    {
        return $this->hasMany(ProductSerialNumber::class, 'received_note_detail_id');
    }
}
