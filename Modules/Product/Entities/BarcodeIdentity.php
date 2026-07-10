<?php

namespace Modules\Product\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BarcodeIdentity extends Model
{
    use HasFactory;

    protected $fillable = [
        'canonical_key',
        'value',
        'product_id',
        'product_unit_conversion_id',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function conversion()
    {
        return $this->belongsTo(ProductUnitConversion::class, 'product_unit_conversion_id');
    }
}
