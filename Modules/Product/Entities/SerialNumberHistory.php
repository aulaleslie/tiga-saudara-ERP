<?php

namespace Modules\Product\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Setting\Entities\Location;

class SerialNumberHistory extends BaseModel
{
    protected $table = 'serial_number_histories';

    public $timestamps = false; // Only created_at is used via default migration
    
    protected array $uppercaseExcept = [
        'reference_type',
        'event_type',
        'note',
    ];

    protected $fillable = [
        'product_serial_number_id',
        'event_type',
        'location_id',
        'reference_type',
        'reference_id',
        'user_id',
        'note',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // Event type constants
    public const EVENT_RECEIVED = 'RECEIVED';
    public const EVENT_SOLD = 'SOLD';
    public const EVENT_SALE_RETURNED = 'SALE_RETURNED';
    public const EVENT_PURCHASE_RETURNED = 'PURCHASE_RETURNED';
    public const EVENT_REPAIR_RECEIVED = 'REPAIR_RECEIVED';
    public const EVENT_LOCATION_TRANSFER = 'LOCATION_TRANSFER';
    public const EVENT_MARKED_BROKEN = 'MARKED_BROKEN';
    public const EVENT_STATUS_CHANGED = 'STATUS_CHANGED';

    /**
     * Get the serial number associated with the history.
     */
    public function serialNumber(): BelongsTo
    {
        return $this->belongsTo(ProductSerialNumber::class, 'product_serial_number_id');
    }

    /**
     * Get the location associated with the history.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Get the user who recorded the history.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the polymorphic reference associated with the history.
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
