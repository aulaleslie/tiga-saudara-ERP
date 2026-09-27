<?php

namespace Modules\Purchase\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Setting;

class PurchaseStatusTransitionAudit extends BaseModel
{
    protected $table = 'purchase_status_transition_audits';

    protected bool $uppercaseAllText = false;

    protected $fillable = [
        'setting_id',
        'purchase_id',
        'received_note_id',
        'old_status',
        'new_status',
        'action_or_source',
        'actor_user_id',
        'reason',
        'transitioned_at',
    ];

    protected $casts = [
        'transitioned_at' => 'datetime',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }

    public function receivedNote(): BelongsTo
    {
        return $this->belongsTo(ReceivedNote::class, 'received_note_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
