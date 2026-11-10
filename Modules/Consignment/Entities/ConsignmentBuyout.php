<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsignmentBuyout extends BaseModel
{
    protected $table = 'consignment_buyouts';

    protected $guarded = ['id'];

    protected $casts = [
        'revision' => 'integer',
        'date' => 'date',
        'invoice_metadata' => 'array',
        'pricing_intent' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'finalized_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_WAITING_APPROVAL = 'WAITING_APPROVAL';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_FINALIZED = 'FINALIZED';
    public const STATUS_REVERSED = 'REVERSED';

    public function scopeForSetting($query, int $settingId)
    {
        return $query->where('setting_id', $settingId);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ConsignmentBuyoutLine::class, 'consignment_buyout_id');
    }

    public function serials(): HasMany
    {
        return $this->hasMany(ConsignmentBuyoutSerial::class, 'consignment_buyout_id');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(ConsignmentBuyoutAuditEvent::class, 'consignment_buyout_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(ConsignmentBuyoutReservation::class, 'consignment_buyout_id');
    }

    public function costEvents(): HasMany
    {
        return $this->hasMany(ConsignmentBuyoutCostEvent::class, 'consignment_buyout_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\Modules\People\Entities\Supplier::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(\Modules\Purchase\Entities\Purchase::class);
    }

}
