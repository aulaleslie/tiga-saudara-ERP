<?php

namespace Modules\Purchase\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Setting\Entities\Setting;

class ReceivedNoteCancellation extends BaseModel
{
    protected $table = 'received_note_cancellations';

    const ORIGIN_MANUAL_APPROVED = 'MANUAL_APPROVED';
    const ORIGIN_MANUAL_PENDING = 'MANUAL_PENDING';
    const ORIGIN_AUTO_PURCHASE_REOPEN = 'AUTO_PURCHASE_REOPEN';

    protected array $uppercaseExcept = [
        'reason',
    ];

    protected $fillable = [
        'received_note_id',
        'purchase_id',
        'setting_id',
        'previous_status',
        'cancellation_origin',
        'cancelled_by',
        'reason',
        'cancelled_at',
    ];

    protected $casts = [
        'cancelled_at' => 'datetime',
    ];

    public function receivedNote(): BelongsTo
    {
        return $this->belongsTo(ReceivedNote::class, 'received_note_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function cancellationDetails(): HasMany
    {
        return $this->hasMany(ReceivedNoteCancellationDetail::class, 'cancellation_id');
    }
}
