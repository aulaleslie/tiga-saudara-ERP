<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;

class ConsignmentReceivalLine extends BaseModel
{
    use HasFactory;

    protected $table = 'consignment_receival_lines';

    protected $fillable = [
        'consignment_receival_id',
        'product_id',
        'product_name',
        'product_code',
        'unit_id',
        'unit_code',
        'tax_id',
        'tax_name',
        'tax_rate',
        'quantity',
        'unit_cost',
        'unit_dpp',
        'subtotal_cost',
        'tax_amount',
        'total_cost',
        'is_serialized',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'unit_dpp' => 'decimal:2',
        'subtotal_cost' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'tax_amount' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'is_serialized' => 'boolean',
    ];

    protected static function newFactory()
    {
        return \Database\Factories\ConsignmentReceivalLineFactory::new();
    }

    public function receival(): BelongsTo
    {
        return $this->belongsTo(ConsignmentReceival::class, 'consignment_receival_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function receivingDetails(): HasMany
    {
        return $this->hasMany(ConsignmentReceivingDetail::class, 'consignment_receival_line_id');
    }
}
