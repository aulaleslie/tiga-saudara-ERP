<?php

namespace Modules\Product\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImportRow extends Model
{
    protected $table = 'product_import_rows';

    protected $fillable = [
        'batch_id', 'row_number', 'raw_json',
        'status', 'error_message',
        'product_id', 'created_txn_id', 'created_stock_id',
        'result_metadata',
    ];

    protected $casts = [
        'raw_json' => 'array',
        'result_metadata' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductImportBatch::class, 'batch_id');
    }
}

