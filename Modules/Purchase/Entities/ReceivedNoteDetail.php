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
        'product_id',
        'product_code',
        'product_name',
        'purchase_unit_id',
        'unit_name',
        'base_unit_name',
        'conversion_factor',
        'entered_quantity',
        'tax_id',
        'location_id',
        'quantity_received',
        'pending_serial_numbers',
        'note',
    ];

    protected $casts = [
        'quantity_received' => 'decimal:3',
        'entered_quantity' => 'decimal:3',
        'conversion_factor' => 'decimal:6',
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
     * A ReceivedNoteDetail has a direct snapshot product_id, falling back to purchase detail product_id.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function purchaseUnit(): BelongsTo
    {
        return $this->belongsTo(\Modules\Setting\Entities\Unit::class, 'purchase_unit_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(\Modules\Setting\Entities\Tax::class, 'tax_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\Modules\Setting\Entities\Location::class, 'location_id');
    }

    public function cancellationDetail(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ReceivedNoteCancellationDetail::class, 'received_note_detail_id');
    }

    /**
     * Accessor for display product name (prefers live product, then snapshot, then purchase detail).
     */
    public function getDisplayProductNameAttribute(): ?string
    {
        if ($this->relationLoaded('product') && $this->product?->product_name) {
            return $this->product->product_name;
        }

        if (filled($this->product_name)) {
            return $this->product_name;
        }

        if ($this->relationLoaded('purchaseDetail') && $this->purchaseDetail?->display_product_name) {
            return $this->purchaseDetail->display_product_name;
        }

        return $this->product?->product_name ?? $this->purchaseDetail?->product?->product_name;
    }

    /**
     * Accessor for display product code.
     */
    public function getDisplayProductCodeAttribute(): ?string
    {
        if ($this->relationLoaded('product') && $this->product?->product_code) {
            return $this->product->product_code;
        }

        if (filled($this->product_code)) {
            return $this->product_code;
        }

        return $this->product?->product_code ?? $this->purchaseDetail?->product_code ?? $this->purchaseDetail?->product?->product_code;
    }

    /**
     * Accessor for display unit name (prefers snapshot, then purchase detail).
     */
    public function getDisplayUnitNameAttribute(): ?string
    {
        if (filled($this->unit_name)) {
            return $this->unit_name;
        }

        return $this->purchaseDetail?->unit_name;
    }

    /**
     * Accessor for display base unit name.
     */
    public function getDisplayBaseUnitNameAttribute(): ?string
    {
        if (filled($this->base_unit_name)) {
            return $this->base_unit_name;
        }

        return $this->purchaseDetail?->base_unit_name;
    }

    /**
     * Accessor for display conversion factor.
     */
    public function getDisplayConversionFactorAttribute(): ?string
    {
        if ($this->conversion_factor !== null) {
            return (string) $this->conversion_factor;
        }

        return $this->purchaseDetail?->conversion_factor !== null
            ? (string) $this->purchaseDetail->conversion_factor
            : '1.000000';
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
