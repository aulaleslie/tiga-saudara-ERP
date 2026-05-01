<?php

namespace Modules\Pos\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;

class PosReturnLine extends Model
{
    use HasFactory;

    public const STOCK_BEHAVIOR_MANAGED = 'stock_managed';
    public const STOCK_BEHAVIOR_STOCKLESS = 'stockless';

    protected $fillable = [
        'pos_return_id',
        'pos_checkout_sale_id',
        'sale_return_id',
        'sale_return_detail_id',
        'sale_id',
        'sale_detail_id',
        'dispatch_detail_id',
        'source_setting_id',
        'source_location_id',
        'tax_id',
        'product_id',
        'product_name',
        'product_code',
        'quantity',
        'unit_price',
        'line_total',
        'serial_number_ids',
        'bundle_group_key',
        'bundle_parent_sale_detail_id',
        'bundle_quantity',
        'component_quantity_per_bundle',
        'stock_behavior',
        'replacement_product_id',
        'replacement_quantity',
    ];

    protected $casts = [
        'serial_number_ids' => 'array',
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'bundle_quantity' => 'decimal:4',
        'component_quantity_per_bundle' => 'decimal:4',
        'replacement_quantity' => 'decimal:4',
    ];

    public function posReturn()
    {
        return $this->belongsTo(PosReturn::class);
    }

    public function saleReturn()
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleReturnDetail()
    {
        return $this->belongsTo(SaleReturnDetail::class);
    }

    public function sale()
    {
        return $this->belongsTo(\Modules\Sale\Entities\Sale::class);
    }

    public function saleDetail()
    {
        return $this->belongsTo(\Modules\Sale\Entities\SaleDetail::class);
    }

    public function product()
    {
        return $this->belongsTo(\Modules\Product\Entities\Product::class);
    }
}
