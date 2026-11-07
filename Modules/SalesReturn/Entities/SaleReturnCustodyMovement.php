<?php

namespace Modules\SalesReturn\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only ledger of custody events. Rows are never updated or deleted. */
class SaleReturnCustodyMovement extends BaseModel
{
    public const TYPE_RECEIVED = 'RECEIVED';
    public const TYPE_RELEASED = 'RELEASED';
    public const TYPE_DISPATCHED = 'DISPATCHED';

    protected $table = 'sale_return_custody_movements';
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:3',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Custody movements are append-only.'));
        static::deleting(fn () => throw new LogicException('Custody movements are append-only.'));
    }

    public function custody(): BelongsTo
    {
        return $this->belongsTo(SaleReturnCustody::class, 'sale_return_custody_id');
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(SaleReturnInspection::class, 'inspection_id');
    }
}
