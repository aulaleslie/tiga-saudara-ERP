<?php

namespace Modules\Purchase\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Tax;

class ReceivedNoteCancellationDetail extends BaseModel
{
    protected $table = 'received_note_cancellation_details';

    protected $fillable = [
        'cancellation_id',
        'received_note_detail_id',
        'product_id',
        'location_id',
        'tax_id',
        'quantity',
        'quantity_tax',
        'quantity_non_tax',
        'original_transaction_id',
        'reversal_transaction_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'quantity_tax' => 'decimal:3',
        'quantity_non_tax' => 'decimal:3',
    ];

    public function cancellation(): BelongsTo
    {
        return $this->belongsTo(ReceivedNoteCancellation::class, 'cancellation_id');
    }

    public function receivedNoteDetail(): BelongsTo
    {
        return $this->belongsTo(ReceivedNoteDetail::class, 'received_note_detail_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'tax_id');
    }

    public function originalTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'original_transaction_id');
    }

    public function reversalTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'reversal_transaction_id');
    }
}
