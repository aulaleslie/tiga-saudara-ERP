<?php

namespace Modules\Expense\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseImportRow extends BaseModel
{
    protected $fillable = [
        'batch_id',
        'row_number',
        'raw_json',
        'status',
        'error_message',
        'expense_id',
    ];

    protected bool $uppercaseAllText = false;

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
        return $this->belongsTo(ExpenseImportBatch::class, 'batch_id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
