<?php

namespace Modules\Product\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Tax;

class ProductSerialNumber extends BaseModel
{
    protected $table = 'product_serial_numbers';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'product_id',
        'location_id',
        'serial_number',
        'tax_id',
        'received_note_detail_id',
    ];

    /**
     * Get the product associated with the serial number.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the location associated with the serial number.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Get the tax associated with the serial number.
     */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
