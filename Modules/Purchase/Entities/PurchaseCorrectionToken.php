<?php

namespace Modules\Purchase\Entities;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseCorrectionToken extends Model
{
    protected $table = 'purchase_correction_tokens';

    protected $fillable = [
        'token',
        'purchase_id',
        'setting_id',
        'user_id',
        'session_id',
        'selected_payment_id',
        'correction_payload_hash',
        'source_document_total',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function selectedPayment(): BelongsTo
    {
        return $this->belongsTo(PurchasePayment::class, 'selected_payment_id');
    }

    public function isValid(string $sessionId, int $userId): bool
    {
        if ($this->consumed_at !== null) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->session_id !== $sessionId) {
            return false;
        }

        if ($this->user_id !== $userId) {
            return false;
        }

        return true;
    }

    public function consume(): void
    {
        $this->consumed_at = Carbon::now();
        $this->save();
    }
}
