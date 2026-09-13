<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use RuntimeException;

class TransferRoutePolicy extends BaseModel
{
    public const CLASSIFICATION_PRESERVE = 'PRESERVE';
    public const CLASSIFICATION_TAX      = 'TAX';
    public const CLASSIFICATION_NON_TAX  = 'NON_TAX';

    public const CLASSIFICATIONS = [
        self::CLASSIFICATION_PRESERVE,
        self::CLASSIFICATION_TAX,
        self::CLASSIFICATION_NON_TAX,
    ];

    public const PROVENANCE_DEFAULT  = 'DEFAULT';
    public const PROVENANCE_FALLBACK = 'FALLBACK';

    protected $fillable = [
        'transfer_id',
        'transfer_revision',
        'origin_location_id',
        'destination_location_id',
        'origin_setting_id',
        'destination_setting_id',
        'origin_is_pkp',
        'destination_is_pkp',
        'same_business',
        'stock_condition',
        'destination_classification',
        'mandatory_return',
        'resolved_tax_id',
        'resolved_tax_name',
        'resolved_tax_rate',
        'tax_resolver_provenance',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'transfer_revision'  => 'integer',
        'origin_is_pkp'      => 'boolean',
        'destination_is_pkp' => 'boolean',
        'same_business'      => 'boolean',
        'mandatory_return'   => 'boolean',
        'resolved_tax_rate'  => 'decimal:4',
        'approved_at'        => 'datetime',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function originLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'origin_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function originSetting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'origin_setting_id');
    }

    public function destinationSetting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'destination_setting_id');
    }

    public function resolvedTax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'resolved_tax_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    protected static function booted(): void
    {
        static::updating(function (self $policy): void {
            throw new RuntimeException('Route-policy snapshots are immutable and cannot be updated once created.');
        });

        static::deleting(function (self $policy): void {
            throw new RuntimeException('Route-policy snapshots are immutable and cannot be deleted.');
        });
    }
}
