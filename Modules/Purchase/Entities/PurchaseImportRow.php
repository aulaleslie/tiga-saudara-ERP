<?php

namespace Modules\Purchase\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseImportRow extends BaseModel
{
    protected $fillable = [
        'batch_id',
        'row_number',
        'raw_json',
        'status',
        'error_message',
        'purchase_id',
    ];

    protected $casts = [
        'raw_json' => 'array',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_VALID = 'valid';
    const STATUS_INVALID = 'invalid';
    const STATUS_PROCESSED = 'processed';
    const STATUS_SKIPPED = 'skipped';

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PurchaseImportBatch::class, 'batch_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }
}
