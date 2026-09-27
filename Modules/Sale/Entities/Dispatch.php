<?php

namespace Modules\Sale\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dispatch extends BaseModel
{
    protected $guarded = [];

    const STATUS_PENDING = 'PENDING';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_REJECTED = 'REJECTED';

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(DispatchDetail::class);
    }

    public function replacementDetails(): HasMany
    {
        return $this->hasMany(DispatchDetail::class)->whereNotNull('pos_return_line_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'approved_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function hasReplacementLineage(): bool
    {
        if ($this->relationLoaded('details')) {
            return $this->details->contains(fn (DispatchDetail $detail) => $detail->isPosReturnReplacement());
        }

        return $this->replacementDetails()->exists();
    }
}
