<?php

namespace Modules\Product\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Tax;

class ProductStock extends Model
{
    use HasFactory;

    // Define the table if it's different from the default plural form
    protected $table = 'product_stocks';

    // Allow mass assignment for these columns
    protected $fillable = [
        'product_id',
        'location_id',
        'quantity',
        'broken_quantity',
        'tax_id'
    ];

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
