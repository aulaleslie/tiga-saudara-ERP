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

    public const TYPE_PRODUCT = 'product';
    public const TYPE_STOCK_SNAPSHOT = 'stock_snapshot';
    public const TYPE_SALES_HPP_SNAPSHOT = 'sales_hpp_snapshot';
    public const TYPE_SALES_PRICE_SNAPSHOT = 'sales_price_snapshot';
    public const TYPE_DUAL_COMPANY_TIER_PRICE = 'dual_company_tier_price';

    protected $fillable = [
        'user_id',
        'location_id',
        'source_csv_path',
        'result_csv_path',
        'file_sha256',
        'status',
        'import_type',
        'total_rows',
        'processed_rows',
        'success_rows',
        'error_rows',
        'undo_token',
        'undo_available_until',
        'undone_at',
        'completed_at',
        'error_message',
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

    /**
     * Null for import types that perform no stock operation, such as
     * {@see self::TYPE_DUAL_COMPANY_TIER_PRICE}.
     */
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
        // Dual-company tier price imports have no safe generic reversal; correction
        // is a subsequent import with deliberate values.
        if ($this->import_type === self::TYPE_DUAL_COMPANY_TIER_PRICE) {
            return false;
        }

        return $this->undo_available_until && now()->lte($this->undo_available_until) && is_null($this->undone_at);
    }
}
