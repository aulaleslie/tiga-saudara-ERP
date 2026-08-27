<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;

class ConsignmentBillingConfirmationLine extends BaseModel
{
    use HasFactory;

    protected $table = 'consignment_billing_confirmation_lines';

    protected $fillable = [
        'consignment_billing_confirmation_id',
        'consignment_sold_source_id',
        'product_id',
        'location_id',
        'allocated_base_quantity',
        'sold_source_snapshot',
    ];

    protected $casts = [
        'allocated_base_quantity' => 'decimal:3',
        'sold_source_snapshot' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::updating(function ($line) {
            if ($line->confirmation && $line->confirmation->isApproved()) {
                throw new \DomainException("Cannot modify lines of an approved confirmation.");
            }
        });

        static::deleting(function ($line) {
            if ($line->confirmation && ($line->confirmation->isApproved() || $line->confirmation->isWaitingApproval())) {
                throw new \DomainException("Cannot delete lines of a submitted or approved confirmation.");
            }
        });
    }

    public function confirmation(): BelongsTo
    {
        return $this->belongsTo(ConsignmentBillingConfirmation::class, 'consignment_billing_confirmation_id');
    }

    public function soldSource(): BelongsTo
    {
        return $this->belongsTo(ConsignmentSoldSource::class, 'consignment_sold_source_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function receiptAllocations(): HasMany
    {
        return $this->hasMany(ConsignmentReceiptAllocation::class, 'consignment_billing_confirmation_line_id');
    }

    public function serializedAllocations(): HasMany
    {
        return $this->hasMany(ConsignmentSerializedAllocation::class, 'consignment_billing_confirmation_line_id');
    }
}
