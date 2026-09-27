<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

class ConsignmentSoldSource extends BaseModel
{
    use HasFactory;

    protected $table = 'consignment_sold_sources';

    protected $fillable = [
        'setting_id',
        'dispatch_detail_id',
        'sale_id',
        'pos_checkout_id',
        'product_id',
        'location_id',
        'original_base_quantity',
        'dispatched_at',
        'tax_context',
        'serial_identities',
        'source_hash',
        'source_snapshot',
        'reconstruction_notes',
        'has_reconstruction_blocker',
        'blocker_reason',
    ];

    protected $casts = [
        'original_base_quantity' => 'decimal:3',
        'dispatched_at' => 'datetime',
        'tax_context' => 'array',
        'serial_identities' => 'array',
        'source_snapshot' => 'array',
        'has_reconstruction_blocker' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::updating(function ($source) {
            throw new \DomainException("ConsignmentSoldSource records are immutable and cannot be modified once created.");
        });

        static::deleting(function ($source) {
            throw new \DomainException("ConsignmentSoldSource records are immutable and cannot be deleted.");
        });
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    public function dispatchDetail(): BelongsTo
    {
        return $this->belongsTo(DispatchDetail::class, 'dispatch_detail_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function serials(): HasMany
    {
        return $this->hasMany(ConsignmentSoldSourceSerial::class, 'consignment_sold_source_id');
    }

    public function confirmationLines(): HasMany
    {
        return $this->hasMany(ConsignmentBillingConfirmationLine::class, 'consignment_sold_source_id');
    }

    public function serializedAllocations(): HasMany
    {
        return $this->hasMany(ConsignmentSerializedAllocation::class, 'consignment_sold_source_id');
    }

    public function scopeForSetting(Builder $query, int $settingId): Builder
    {
        return $query->where('setting_id', $settingId);
    }

    /**
     * Free-text search across product, location, sale reference and serial number.
     */
    public function scopeSearchTerm(Builder $query, string $term): Builder
    {
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $sub) use ($term) {
            $sub->whereHas('product', function (Builder $p) use ($term) {
                $p->where('product_name', 'like', "%{$term}%")
                    ->orWhere('product_code', 'like', "%{$term}%");
            })
                ->orWhereHas('location', fn (Builder $l) => $l->where('name', 'like', "%{$term}%"))
                ->orWhereHas('sale', fn (Builder $s) => $s->where('reference', 'like', "%{$term}%"))
                ->orWhereHas('serials.serialNumber', fn (Builder $sn) => $sn->where('serial_number', 'like', "%{$term}%"));
        });
    }
}
