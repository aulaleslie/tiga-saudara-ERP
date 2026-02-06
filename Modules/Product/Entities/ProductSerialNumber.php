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
        'dispatch_detail_id',
        'status',
        'is_broken',
        'is_in_return_process',
        'purchase_return_id',
    ];

    protected $casts = [
        'is_broken' => 'boolean',
        'is_in_return_process' => 'boolean',
    ];

    /**
     * Get the product associated with the serial number.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the purchase return associated with the serial number (if in return process).
     */
    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(\Modules\PurchasesReturn\Entities\PurchaseReturn::class);
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

    /**
     * Get the received note detail associated with the serial number.
     */
    public function receivedNoteDetail(): BelongsTo
    {
        return $this->belongsTo(\Modules\Purchase\Entities\ReceivedNoteDetail::class, 'received_note_detail_id');
    }

    /**
     * Get the history of the serial number.
     */
    public function histories(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SerialNumberHistory::class, 'product_serial_number_id');
    }
}
