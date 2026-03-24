<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Setting\Entities\Setting;

class PosSession extends BaseModel
{
    public const STATUS_OPEN = 'OPEN';

    public const STATUS_CLOSING = 'CLOSING';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUS_FINALIZED = 'FINALIZED';

    protected $table = 'pos_sessions';

    protected $fillable = [
        'setting_id',
        'terminal_id',
        'cashier_user_id',
        'status',
        'opened_at',
        'closed_at',
        'finalized_at',
        'opened_by',
        'closed_by',
        'opening_float_total',
        'expected_cash_total',
        'counted_cash_total',
        'variance_total',
        'close_notes',
        'close_approved_by',
        'close_approved_at',
        'metadata',
        'active_marker',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'finalized_at' => 'datetime',
        'close_approved_at' => 'datetime',
        'opening_float_total' => 'decimal:2',
        'expected_cash_total' => 'decimal:2',
        'counted_cash_total' => 'decimal:2',
        'variance_total' => 'decimal:2',
        'metadata' => 'array',
        'active_marker' => 'integer',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function closeApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'close_approved_by');
    }

    public function cashEvents(): HasMany
    {
        return $this->hasMany(PosSessionCashEvent::class, 'pos_session_id');
    }

    public function checkouts(): HasMany
    {
        return $this->hasMany(PosCheckout::class, 'pos_session_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('active_marker');
    }

    public function scopeForCashier(Builder $query, int $settingId, int $cashierUserId): Builder
    {
        return $query
            ->where('setting_id', $settingId)
            ->where('cashier_user_id', $cashierUserId);
    }

    public static function activeMarkerForStatus(string $status): ?int
    {
        return in_array($status, [self::STATUS_OPEN, self::STATUS_CLOSING], true) ? 1 : null;
    }

    public function getDurationAttribute(): ?string
    {
        if (! $this->opened_at) {
            return null;
        }

        $end = $this->closed_at ?: now();
        $diff = $this->opened_at->diff($end);

        $hours = $diff->h + ($diff->days * 24);
        $minutes = $diff->i;

        if ($hours > 0) {
            return sprintf('%d jam %d menit', $hours, $minutes);
        }

        return sprintf('%d menit', $minutes);
    }
}
