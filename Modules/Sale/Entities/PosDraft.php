<?php

namespace Modules\Sale\Entities;

use App\Models\PosReceipt;
use App\Models\PosSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Setting\Entities\Setting;
use Modules\Sale\Services\PosCodeAllocator;

class PosDraft extends Model
{
    use HasFactory;

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->document_number)) {
                $setting = $model->setting;
                
                if (!$setting && $model->setting_id) {
                    $setting = \Modules\Setting\Entities\Setting::find($model->setting_id);
                }

                if ($setting) {
                    $allocator = app(PosCodeAllocator::class);
                    $model->document_number = $allocator->allocate($setting);
                }
            }

            if (empty($model->status)) {
                $model->status = self::STATUS_AJUKAN_PEMBAYARAN;
            }

            if (empty($model->last_touched_at)) {
                $model->last_touched_at = now();
            }
        });
    }

    protected static function newFactory()
    {
        return \Database\Factories\PosDraftFactory::new();
    }

    protected $fillable = [
        'pos_session_id',
        'setting_id',
        'user_id',
        'pos_receipt_id',
        'status',
        'expires_at',
        'locked_by_user_id',
        'locked_at',
        'locked_until',
        'submitted_at',
        'last_touched_at',
        'payload',
        'document_number',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'locked_at' => 'datetime',
        'locked_until' => 'datetime',
        'submitted_at' => 'datetime',
        'last_touched_at' => 'datetime',
        'payload' => 'array',
    ];

    public const STATUS_AJUKAN_PEMBAYARAN = 'AJUKAN_PEMBAYARAN';
    public const STATUS_TERBAYAR = 'TERBAYAR';
    public const STATUS_DIBATALKAN = 'DIBATALKAN';
    public const STATUS_KEDALUWARSA = 'KEDALUWARSA';

    // Backward-compatible aliases.
    public const STATUS_OPEN = self::STATUS_AJUKAN_PEMBAYARAN;
    public const STATUS_LOCKED = self::STATUS_AJUKAN_PEMBAYARAN;
    public const STATUS_VOID = self::STATUS_DIBATALKAN;
    public const STATUS_EXPIRED = self::STATUS_KEDALUWARSA;
    public const STATUS_COMPLETED = self::STATUS_TERBAYAR;

    public function posSession()
    {
        return $this->belongsTo(PosSession::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function setting()
    {
        return $this->belongsTo(Setting::class);
    }

    public function posReceipt()
    {
        return $this->belongsTo(PosReceipt::class);
    }

    public function lockedByUser()
    {
        return $this->belongsTo(User::class, 'locked_by_user_id');
    }

    public function items()
    {
        return $this->hasMany(PosDraftItem::class, 'pos_draft_id');
    }

    public function submitIdempotencies()
    {
        return $this->hasMany(PosSubmitIdempotency::class, 'pos_draft_id');
    }

    public function auditLogs()
    {
        return $this->hasMany(PosAuditLog::class, 'pos_draft_id');
    }

    public function scopeActive(Builder $query)
    {
        return $query->where('status', self::STATUS_AJUKAN_PEMBAYARAN)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeExpired(Builder $query)
    {
        return $query->where('status', self::STATUS_KEDALUWARSA)
            ->orWhere(function ($q) {
                $q->where('status', self::STATUS_AJUKAN_PEMBAYARAN)
                  ->where('expires_at', '<=', now());
            });
    }

    public function isExpired(): bool
    {
        if ($this->status === self::STATUS_KEDALUWARSA) {
            return true;
        }
        
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isFinalized(): bool
    {
        return in_array($this->status, [
            self::STATUS_TERBAYAR,
            self::STATUS_DIBATALKAN,
            self::STATUS_KEDALUWARSA,
        ], true);
    }

    public function hasActiveLock(): bool
    {
        if (! $this->locked_by_user_id || ! $this->locked_until) {
            return false;
        }

        return $this->locked_until->isFuture();
    }
}
