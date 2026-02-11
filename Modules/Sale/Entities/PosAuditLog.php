<?php

namespace Modules\Sale\Entities;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Setting;

class PosAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'setting_id',
        'pos_draft_id',
        'user_id',
        'pos_code',
        'action',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(PosDraft::class, 'pos_draft_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
