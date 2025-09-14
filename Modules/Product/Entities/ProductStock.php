<?php

namespace Modules\Product\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Tax;

class ProductStock extends BaseModel
{
    protected $fillable = [
        'product_id',
        'location_id',
        'quantity',
        'quantity_non_tax',
        'quantity_tax',
        'broken_quantity_non_tax',
        'broken_quantity_tax',
        'broken_quantity',
        'tax_id', // Nullable tax field
        'sale_price',
    ];

    // Define the table if it's different from the default plural form
    protected $table = 'product_stocks';

    // Define relationships
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
