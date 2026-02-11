<?php

namespace Modules\Sale\Entities;

use App\Models\PosReceipt;
use App\Models\PosSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Setting\Entities\Setting;

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
                    $allocator = app(\Modules\Sale\Services\PosCodeAllocator::class);
                    $model->document_number = $allocator->allocate($setting);
                }
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
        'payload',
        'document_number',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'locked_at' => 'datetime',
        'payload' => 'array',
    ];

    const STATUS_OPEN = 'Open';
    const STATUS_LOCKED = 'Locked';
    const STATUS_VOID = 'Void';
    const STATUS_EXPIRED = 'Expired';
    const STATUS_COMPLETED = 'Completed';

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

    public function scopeActive(Builder $query)
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_LOCKED])
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeExpired(Builder $query)
    {
        return $query->where('status', self::STATUS_EXPIRED)
            ->orWhere(function ($q) {
                $q->where('status', self::STATUS_OPEN)
                  ->where('expires_at', '<=', now());
            });
    }

    public function isExpired(): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }
        
        return $this->expires_at && $this->expires_at->isPast();
    }
}
