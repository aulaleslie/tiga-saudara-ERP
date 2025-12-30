<?php

namespace Modules\Sale\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesImportRow extends BaseModel
{
    protected $fillable = [
        'batch_id',
        'row_number',
        'raw_json',
        'status',
        'error_message',
        'sale_id',
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
        return $this->belongsTo(SalesImportBatch::class, 'batch_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
