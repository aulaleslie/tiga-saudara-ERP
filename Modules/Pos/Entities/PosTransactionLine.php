<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Tax;

class PosTransactionLine extends BaseModel
{
    protected bool $uppercaseAllText = false;

    protected $table = 'pos_transaction_lines';

    protected $fillable = [
        'pos_transaction_id',
        'line_no',
        'product_id',
        'product_name_snapshot',
        'product_code_snapshot',
        'conversion_id',
        'qty',
        'unit_price',
        'tax_id',
        'tax_name_snapshot',
        'tax_rate_snapshot',
        'line_discount_type',
        'line_discount_value',
        'line_meta',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'line_discount_value' => 'decimal:2',
        'tax_rate_snapshot' => 'decimal:4',
        'line_meta' => 'array',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PosTransaction::class, 'pos_transaction_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'tax_id');
    }

    public function conversion(): BelongsTo
    {
        return $this->belongsTo(ProductUnitConversion::class, 'conversion_id');
    }

    public function serials(): HasMany
    {
        return $this->hasMany(PosTransactionLineSerial::class, 'pos_transaction_line_id');
    }
}
