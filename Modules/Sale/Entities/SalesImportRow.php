<?php

namespace Modules\Sale\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesImportRow extends BaseModel
{
    protected $fillable = [
        'batch_id',
        'row_number',
        'invoice_number',
        'raw_json',
        'status',
        'error_message',
        'sale_id',
    ];

    protected $casts = [
        'raw_json' => 'array',
    ];

    protected array $uppercaseExcept = [
        'status',
        'error_message',
        'raw_json' // Although cast to array, good to be safe if treated as string
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

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->invoice_number) && !empty($model->raw_json['no_faktur'])) {
                $model->invoice_number = (string) $model->raw_json['no_faktur'];
            }
        });
    }
}
