<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Setting\Entities\Setting;

class PosTerminal extends BaseModel
{
    protected $table = 'pos_terminals';

    protected $fillable = [
        'setting_id',
        'code',
        'name',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    public function policy(): HasOne
    {
        return $this->hasOne(PosTerminalPolicy::class, 'terminal_id');
    }

    public function activeSessions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PosSession::class, 'terminal_id')
            ->whereIn('status', [PosSession::STATUS_OPEN, PosSession::STATUS_CLOSING]);
    }
}
