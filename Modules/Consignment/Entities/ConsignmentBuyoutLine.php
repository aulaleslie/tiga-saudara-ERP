<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsignmentBuyoutLine extends BaseModel
{
    protected $table = 'consignment_buyout_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'base_quantity' => 'decimal:3',
        'original_snapshot' => 'array',
        'accepted_snapshot' => 'array',
    ];

    public const TYPE_SOLD_UNBILLED = 'SOLD_UNBILLED';
    public const TYPE_UNSOLD_BUYOUT = 'UNSOLD_BUYOUT';

    /** Inventory owner after acquisition; legacy rows (NULL) are owned by the header billing business. */
    public function inventoryOwnerSettingId(): int
    {
        return (int) ($this->inventory_owner_setting_id
            ?? ConsignmentBuyout::whereKey($this->consignment_buyout_id)->value('setting_id'));
    }

    protected static function booted(): void
    {
        // Approved/final evidence is immutable; only the accepted pricing snapshot may be written while approved.
        static::updating(function (self $line) {
            $status = ConsignmentBuyout::whereKey($line->consignment_buyout_id)->value('status');
            if (in_array($status, [ConsignmentBuyout::STATUS_DRAFT, ConsignmentBuyout::STATUS_REJECTED], true)) {
                return;
            }
            $allowed = $status === ConsignmentBuyout::STATUS_APPROVED ? ['accepted_snapshot', 'updated_at'] : ['updated_at'];
            if ($illegal = array_diff(array_keys($line->getDirty()), $allowed)) {
                throw new \DomainException('Lines of a submitted, approved or final buyout are immutable (attempted change: ' . implode(', ', $illegal) . ').');
            }
        });
        static::deleting(function (self $line) {
            $status = ConsignmentBuyout::whereKey($line->consignment_buyout_id)->value('status');
            if (! in_array($status, [ConsignmentBuyout::STATUS_DRAFT, ConsignmentBuyout::STATUS_REJECTED], true)) {
                throw new \DomainException('Lines of a submitted, approved or final buyout cannot be deleted.');
            }
        });

        // Supplier/business identity: the receipt must belong to the buyout's supplier and setting.
        static::saving(function (self $line) {
            $buyout = ConsignmentBuyout::find($line->consignment_buyout_id);
            $detail = ConsignmentReceivingDetail::with('receiving.receival')->find($line->consignment_receiving_detail_id);
            if (! $buyout || ! $detail || ! $detail->receiving) {
                throw new \DomainException('Buyout line requires an existing buyout and receipt detail.');
            }
            if ((int) $detail->receiving->setting_id !== (int) $buyout->setting_id
                || (int) optional($detail->receiving->receival)->supplier_id !== (int) $buyout->supplier_id) {
                throw new \DomainException('Receipt does not belong to the buyout supplier and business.');
            }
            if ((int) $detail->product_id !== (int) $line->product_id) {
                throw new \DomainException('Line product does not match the receipt product.');
            }
            if (! in_array($line->line_type, [self::TYPE_SOLD_UNBILLED, self::TYPE_UNSOLD_BUYOUT], true)) {
                throw new \DomainException('Invalid buyout line type.');
            }
            if ($line->line_type === self::TYPE_SOLD_UNBILLED && ! $line->consignment_sold_source_id) {
                throw new \DomainException('Sold buyout lines require a sold source.');
            }
            if (bccomp((string) $line->base_quantity, '0', 3) <= 0) {
                throw new \DomainException('Buyout line quantity must be positive.');
            }
        });
    }

    public function buyout(): BelongsTo
    {
        return $this->belongsTo(ConsignmentBuyout::class, 'consignment_buyout_id');
    }

    public function receivingDetail(): BelongsTo
    {
        return $this->belongsTo(ConsignmentReceivingDetail::class, 'consignment_receiving_detail_id');
    }

    public function soldSource(): BelongsTo
    {
        return $this->belongsTo(ConsignmentSoldSource::class, 'consignment_sold_source_id');
    }

    public function serials(): HasMany
    {
        return $this->hasMany(ConsignmentBuyoutSerial::class, 'consignment_buyout_line_id');
    }

}
