<?php

namespace Modules\Product\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Location;

class ProductImportBatch extends Model
{
    protected $table = 'product_import_batches';

    protected $fillable = [
        'user_id', 'location_id',
        'source_csv_path', 'result_csv_path', 'file_sha256',
        'status', 'total_rows', 'processed_rows', 'success_rows', 'error_rows',
        'completed_at', 'undo_available_until', 'undone_at', 'undo_token',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'undo_available_until' => 'datetime',
        'undone_at' => 'datetime',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(ProductImportRow::class, 'batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function getProgressAttribute(): float
    {
        $total = (float) $this->total_rows;
        $done  = (float) $this->processed_rows;
        return $total > 0 ? ($done / $total) * 100.0 : 0.0;
    }

    public function canUndo(): bool
    {
        return $this->undo_available_until && now()->lte($this->undo_available_until) && is_null($this->undone_at);
    }
}
