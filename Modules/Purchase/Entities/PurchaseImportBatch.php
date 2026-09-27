<?php

namespace Modules\Purchase\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseImportBatch extends BaseModel
{
    protected $fillable = [
        'user_id',
        'source_csv_path',
        'file_sha256',
        'status',
        'total_rows',
        'processed_rows',
        'success_count',
        'error_count',
    ];

    const STATUS_QUEUED = 'queued';
    const STATUS_VALIDATING = 'validating';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(PurchaseImportRow::class, 'batch_id');
    }

    public function pendingRows(): HasMany
    {
        return $this->rows()->where(function ($q) {
            $q->whereNull('status')
              ->orWhere('status', 'pending');
        });
    }

    public function validRows(): HasMany
    {
        return $this->rows()->where('status', 'valid');
    }

    public function invalidRows(): HasMany
    {
        return $this->rows()->where('status', 'invalid');
    }

    public function processedRows(): HasMany
    {
        return $this->rows()->where('status', 'processed');
    }
}
