<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosActionApprovalToken extends BaseModel
{
    protected $table = 'pos_action_approval_tokens';

    protected $fillable = [
        'approval_request_id',
        'token_hash',
        'expires_at',
        'consumed_at',
        'consumed_by',
        'consumed_context',
    ];

    protected array $uppercaseExcept = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'consumed_context' => 'array',
    ];

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(PosActionApprovalRequest::class, 'approval_request_id');
    }

    public function isValid(): bool
    {
        if ($this->consumed_at !== null) {
            return false;
        }

        if ($this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
