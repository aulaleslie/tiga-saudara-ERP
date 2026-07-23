<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Setting;

class PosTemporaryPaymentImage extends BaseModel
{
    protected $table = 'pos_temporary_payment_images';

    protected $fillable = [
        'token',
        'setting_id',
        'pos_session_id',
        'cashier_id',
        'cart_token',
        'path',
        'original_name',
        'mime_type',
        'size',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')
                     ->where('expires_at', '>', now());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')
                     ->where('expires_at', '<=', now());
    }

    public function scopeConsumed(Builder $query): Builder
    {
        return $query->whereNotNull('consumed_at');
    }
}
