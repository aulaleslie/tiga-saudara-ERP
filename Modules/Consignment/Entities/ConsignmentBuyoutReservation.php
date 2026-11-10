<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsignmentBuyoutReservation extends BaseModel
{
    protected $table = 'consignment_buyout_reservations';

    protected $guarded = ['id'];

    protected $casts = [
        'base_quantity' => 'decimal:3',
        'released_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_CONSUMED = 'CONSUMED';
    public const STATUS_RELEASED = 'RELEASED';

    public function buyout(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ConsignmentBuyout::class, 'consignment_buyout_id');
    }

    public function line(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ConsignmentBuyoutLine::class, 'consignment_buyout_line_id');
    }

}
