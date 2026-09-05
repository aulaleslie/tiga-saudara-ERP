<?php

namespace Modules\Product\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Tax;

class ProductSerialNumber extends BaseModel
{
    protected $table = 'product_serial_numbers';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'product_id',
        'location_id',
        'serial_number',
        'tax_id',
        'received_note_detail_id',
        'consignment_receiving_detail_id',
        'dispatch_detail_id',
        'status',
        'is_broken',
        'is_in_return_process',
        'purchase_return_id',
    ];

    // Status Constants
    const STATUS_ACTIVE = 'ACTIVE';
    const STATUS_SOLD = 'SOLD';
    const STATUS_RETURN_IN_PROCESS = 'RETURN_IN_PROCESS';
    const STATUS_RETURNED = 'RETURNED';
    const STATUS_BROKEN = 'BROKEN';

    /**
     * Normalize serial number to canonical UPPERCASE trimmed UTF-8.
     */
    public static function normalize(string $serialNumber): string
    {
        return mb_strtoupper(trim($serialNumber), 'UTF-8');
    }

    /**
     * Mutator to ensure serial numbers are always normalized on write.
     */
    public function setSerialNumberAttribute($value): void
    {
        $this->attributes['serial_number'] = is_null($value) ? null : self::normalize((string) $value);
    }

    /**
     * Normalize status read to UPPERCASE.
     */
    public function getStatusAttribute($value)
    {
        return strtoupper($value ?? self::STATUS_ACTIVE);
    }

    protected $casts = [
        'is_broken' => 'boolean',
        'is_in_return_process' => 'boolean',
    ];

    /**
     * Get the product associated with the serial number.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the purchase return associated with the serial number (if in return process).
     */
    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(\Modules\PurchasesReturn\Entities\PurchaseReturn::class);
    }

    /**
     * Get the location associated with the serial number.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Get the tax associated with the serial number.
     */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    /**
     * Get the received note detail associated with the serial number.
     */
    public function receivedNoteDetail(): BelongsTo
    {
        return $this->belongsTo(\Modules\Purchase\Entities\ReceivedNoteDetail::class, 'received_note_detail_id');
    }

    /**
     * Get the consignment receiving detail associated with the serial number.
     */
    public function consignmentReceivingDetail(): BelongsTo
    {
        return $this->belongsTo(\Modules\Consignment\Entities\ConsignmentReceivingDetail::class, 'consignment_receiving_detail_id');
    }

    /**
     * Get the received note details (Many-to-Many).
     */
    public function receivedNoteDetails(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\Modules\Purchase\Entities\ReceivedNoteDetail::class, 'received_note_detail_serial_numbers', 'product_serial_number_id', 'received_note_detail_id')
            ->withPivot(['id', 'source_history_id', 'linked_at'])
            ->withTimestamps();
    }

    /**
     * Get the consignment receiving details (Many-to-Many).
     */
    public function consignmentReceivingDetails(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\Modules\Consignment\Entities\ConsignmentReceivingDetail::class, 'consignment_receiving_detail_serial_numbers', 'product_serial_number_id', 'consignment_receiving_detail_id')
            ->withPivot(['id', 'source_history_id', 'reversal_history_id', 'linked_at'])
            ->withTimestamps();
    }

    /**
     * Get the history of the serial number.
     */
    public function histories(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SerialNumberHistory::class, 'product_serial_number_id');
    }

    /**
     * Resolve the current source purchase ID for this serial number.
     * Strategy:
     * 1. Check EVENT_RECEIVED history mapped to ReceivedNoteDetail.
     * 2. Fallback to latest linked ReceivedNoteDetail pivot.
     * 3. Fallback to legacy received_note_detail_id.
     */
    public function resolveCurrentPurchaseId(): ?int
    {
        $latestReceive = $this->histories
            ->where('event_type', SerialNumberHistory::EVENT_RECEIVED)
            ->where('reference_type', \Modules\Purchase\Entities\ReceivedNoteDetail::class)
            ->sortByDesc('id')
            ->first();

        if ($latestReceive) {
            // Check if already loaded to avoid N+1 if we preloaded them
            if ($latestReceive->relationLoaded('reference') && $latestReceive->reference && $latestReceive->reference->relationLoaded('purchaseDetail')) {
                return $latestReceive->reference->purchaseDetail->purchase_id;
            }
            
            $rnH = \Modules\Purchase\Entities\ReceivedNoteDetail::with('purchaseDetail')->find($latestReceive->reference_id);
            if ($rnH && $rnH->purchaseDetail) {
                return $rnH->purchaseDetail->purchase_id;
            }
        }

        $latestPivot = $this->receivedNoteDetails
            ->sortByDesc('pivot.linked_at')
            ->first();
            
        if ($latestPivot && $latestPivot->purchaseDetail) {
            return $latestPivot->purchaseDetail->purchase_id;
        }

        if ($this->received_note_detail_id) {
            if ($this->relationLoaded('receivedNoteDetail') && $this->receivedNoteDetail && $this->receivedNoteDetail->relationLoaded('purchaseDetail')) {
                 return $this->receivedNoteDetail->purchaseDetail->purchase_id;
            }
            $legacy = \Modules\Purchase\Entities\ReceivedNoteDetail::with('purchaseDetail')->find($this->received_note_detail_id);
            if ($legacy && $legacy->purchaseDetail) {
                return $legacy->purchaseDetail->purchase_id;
            }
        }

        return null;
    }

    /**
     * Resolve the current source consignment receival ID for this serial number.
     */
    public function resolveCurrentConsignmentReceivalId(): ?int
    {
        $latestReceive = $this->histories
            ->where('event_type', SerialNumberHistory::EVENT_RECEIVED)
            ->where('reference_type', \Modules\Consignment\Entities\ConsignmentReceivingDetail::class)
            ->sortByDesc('id')
            ->first();

        if ($latestReceive) {
            if ($latestReceive->relationLoaded('reference') && $latestReceive->reference && $latestReceive->reference->relationLoaded('consignmentReceiving')) {
                return $latestReceive->reference->consignmentReceiving->consignment_receival_id;
            }

            $crd = \Modules\Consignment\Entities\ConsignmentReceivingDetail::with('consignmentReceiving')->find($latestReceive->reference_id);
            if ($crd && $crd->consignmentReceiving) {
                return $crd->consignmentReceiving->consignment_receival_id;
            }
        }

        if ($this->consignment_receiving_detail_id) {
            $crd = \Modules\Consignment\Entities\ConsignmentReceivingDetail::with('consignmentReceiving')->find($this->consignment_receiving_detail_id);
            if ($crd && $crd->consignmentReceiving) {
                return $crd->consignmentReceiving->consignment_receival_id;
            }
        }

        return null;
    }

    public function consignmentActiveClaim(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\Modules\Consignment\Entities\ConsignmentActiveSerialClaim::class, 'product_serial_number_id');
    }

    public function consignmentSerializedAllocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\Modules\Consignment\Entities\ConsignmentSerializedAllocation::class, 'product_serial_number_id');
    }
}
