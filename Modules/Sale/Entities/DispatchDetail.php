<?php

namespace Modules\Sale\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Setting\Entities\Location;

class DispatchDetail extends BaseModel
{
    protected $fillable = [
        'dispatch_id',
        'sale_id',
        'sale_detail_id',
        'product_id',
        'dispatched_quantity',
        'location_id',
        'serial_numbers',
        'tax_id',
        'bundle_id',
        'is_inventory_managed',
        'pos_return_line_id',
        'replacement_of_dispatch_detail_id',
        'replacement_returned_serial_id',
        'replacement_cost_unit_snapshot',
        'replacement_cost_total_snapshot',
        'replacement_cost_snapshot_source',
        'replacement_cost_snapshot_setting_id',
        'replacement_cost_snapshot_at',
    ];

    protected $casts = [
        'is_inventory_managed' => 'boolean',
        'replacement_cost_unit_snapshot' => 'decimal:6',
        'replacement_cost_total_snapshot' => 'decimal:2',
        'replacement_cost_snapshot_at' => 'datetime',
    ];

    protected array $uppercaseExcept = [
        'serial_numbers',
    ];

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id', 'id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function posReturnLine(): BelongsTo
    {
        return $this->belongsTo(PosReturnLine::class, 'pos_return_line_id');
    }

    public function replacementOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_of_dispatch_detail_id');
    }

    public function replacementReturnedSerial(): BelongsTo
    {
        return $this->belongsTo(ProductSerialNumber::class, 'replacement_returned_serial_id');
    }

    public function isPosReturnReplacement(): bool
    {
        return ! is_null($this->pos_return_line_id);
    }

    public function consignmentSoldSource(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\Modules\Consignment\Entities\ConsignmentSoldSource::class, 'dispatch_detail_id');
    }
}
