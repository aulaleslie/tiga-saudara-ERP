<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Tax;

class ConsignmentReceiptAllocation extends BaseModel
{
    use HasFactory;
    use \Modules\Consignment\Entities\Concerns\ResolvesGuardConfirmationStatus;

    protected $table = 'consignment_receipt_allocations';

    /** Legacy allocations: tax_amount holds the full-lot receiving-detail tax. */
    public const TAX_SNAPSHOT_VERSION_LEGACY = 1;

    /** Current allocations: tax_amount holds proportional tax for the allocated quantity. */
    public const TAX_SNAPSHOT_VERSION_PROPORTIONAL = 2;

    public const SUPPORTED_TAX_SNAPSHOT_VERSIONS = [
        self::TAX_SNAPSHOT_VERSION_LEGACY,
        self::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
    ];

    protected $fillable = [
        'consignment_billing_confirmation_line_id',
        'consignment_receiving_detail_id',
        'allocated_base_quantity',
        'unit_cost',
        'unit_dpp',
        'tax_id',
        'tax_rate',
        'tax_amount',
        'tax_snapshot_version',
        'receival_reference',
        'receiving_reference',
        'receiving_detail_snapshot',
    ];

    protected $casts = [
        'allocated_base_quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'unit_dpp' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'tax_amount' => 'decimal:2',
        'tax_snapshot_version' => 'integer',
        'receiving_detail_snapshot' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::updating(function ($ra) {
            $status = $ra->guardReceiptAllocationConfirmationStatus();
            if ($ra->guardStatusIsApproved($status)) {
                throw new \DomainException("Cannot modify receipt allocations of an approved confirmation.");
            }
        });

        static::deleting(function ($ra) {
            $status = $ra->guardReceiptAllocationConfirmationStatus();
            if ($ra->guardStatusIsApproved($status) || $ra->guardStatusIsWaitingApproval($status)) {
                throw new \DomainException("Cannot delete receipt allocations of a submitted or approved confirmation.");
            }
        });
    }

    /**
     * Resolve the owning confirmation's status through the parent line, without ever
     * lazy loading either relation.
     */
    protected function guardReceiptAllocationConfirmationStatus(): ?string
    {
        return $this->guardConfirmationStatusVia(
            'line',
            'consignment_billing_confirmation_line_id',
            ConsignmentBillingConfirmationLine::class,
            'consignment_billing_confirmation_id'
        );
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ConsignmentBillingConfirmationLine::class, 'consignment_billing_confirmation_line_id');
    }

    public function receivingDetail(): BelongsTo
    {
        return $this->belongsTo(ConsignmentReceivingDetail::class, 'consignment_receiving_detail_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
