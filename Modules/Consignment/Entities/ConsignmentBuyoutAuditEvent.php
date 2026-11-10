<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsignmentBuyoutAuditEvent extends BaseModel
{
    protected $table = 'consignment_buyout_audit_events';

    protected $guarded = ['id'];

    protected $casts = [
        'snapshot' => 'array',
    ];

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new \DomainException('Buyout audit events are append-only.'));
        static::deleting(fn () => throw new \DomainException('Buyout audit events are append-only.'));
    }

}
