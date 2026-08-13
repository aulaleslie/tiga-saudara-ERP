<?php

namespace Modules\Purchase\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Transaction;

class UomNormalizationLine extends BaseModel
{
    protected $table = 'uom_normalization_lines';

    protected $fillable = [
        'batch_id',
        'purchase_detail_id',
        'received_note_detail_id',
        'transaction_id',
        'source_quantity',
        'source_sub_total',
        'source_unit_price',
        'source_tax_amount',
        'normalized_quantity',
        'normalized_unit_cost',
        'transaction_snapshot_before',
        'transaction_snapshot_after',
    ];

    protected $casts = [
        'source_quantity' => 'decimal:3',
        'source_sub_total' => 'decimal:2',
        'source_unit_price' => 'decimal:2',
        'source_tax_amount' => 'decimal:2',
        'normalized_quantity' => 'decimal:3',
        'normalized_unit_cost' => 'decimal:6',
        'transaction_snapshot_before' => 'array',
        'transaction_snapshot_after' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(UomNormalizationBatch::class, 'batch_id');
    }

    public function purchaseDetail(): BelongsTo
    {
        return $this->belongsTo(PurchaseDetail::class);
    }

    public function receivedNoteDetail(): BelongsTo
    {
        return $this->belongsTo(ReceivedNoteDetail::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
