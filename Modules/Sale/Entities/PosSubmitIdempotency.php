<?php

namespace Modules\Sale\Entities;

use App\Models\PosReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Setting;

class PosSubmitIdempotency extends Model
{
    protected $fillable = [
        'setting_id',
        'pos_draft_id',
        'idempotency_key',
        'pos_receipt_id',
        'created_by',
        'response_payload',
    ];

    protected $casts = [
        'response_payload' => 'array',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(PosDraft::class, 'pos_draft_id');
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PosReceipt::class, 'pos_receipt_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
