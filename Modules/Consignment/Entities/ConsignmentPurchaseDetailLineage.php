<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;

class ConsignmentPurchaseDetailLineage extends BaseModel
{
    protected $table = 'consignment_purchase_detail_lineages';

    protected $fillable = [
        'setting_id',
        'purchase_id',
        'purchase_detail_id',
        'consignment_billing_confirmation_id',
        'consignment_billing_confirmation_line_id',
        'consignment_receipt_allocation_id',
        'consignment_serialized_allocation_id',
        'product_id',
        'consignment_receiving_detail_id',
        'billed_base_quantity',
        'unit_cost',
        'unit_dpp',
        'tax_id',
        'tax_rate',
        'tax_amount',
        'commercial_snapshot',
    ];

    protected $casts = [
        'billed_base_quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'unit_dpp' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'tax_amount' => 'decimal:2',
        'commercial_snapshot' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::updating(function (ConsignmentPurchaseDetailLineage $lineage) {
            throw new \DomainException("Consignment purchase detail lineage records are immutable and cannot be updated.");
        });

        static::deleting(function (ConsignmentPurchaseDetailLineage $lineage) {
            throw new \DomainException("Consignment purchase detail lineage records are immutable and cannot be deleted.");
        });
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function purchaseDetail(): BelongsTo
    {
        return $this->belongsTo(PurchaseDetail::class);
    }

    public function confirmation(): BelongsTo
    {
        return $this->belongsTo(ConsignmentBillingConfirmation::class, 'consignment_billing_confirmation_id');
    }

    public function confirmationLine(): BelongsTo
    {
        return $this->belongsTo(ConsignmentBillingConfirmationLine::class, 'consignment_billing_confirmation_line_id');
    }

    public function receiptAllocation(): BelongsTo
    {
        return $this->belongsTo(ConsignmentReceiptAllocation::class, 'consignment_receipt_allocation_id');
    }

    public function serializedAllocation(): BelongsTo
    {
        return $this->belongsTo(ConsignmentSerializedAllocation::class, 'consignment_serialized_allocation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
