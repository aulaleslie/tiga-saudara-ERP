<?php

namespace Modules\Purchase\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Tax;

class PurchaseDetail extends BaseModel
{
    protected $fillable = [
        'purchase_id',
        'product_id',
        'quantity',
        'tax_id',
        'unit_price',
        'product_discount_type',
        'sub_total',
        'product_discount_amount',
        'product_name',
        'product_code',
        'price',
        'product_tax_amount',
    ];

    protected $casts = [
        // Quantity is decimal to support fractional, weight-based units (e.g. 23.7 KG).
        'quantity' => 'decimal:3',
    ];

    protected $with = ['product', 'tax'];

    protected static function boot()
    {
        parent::boot();

        // Defence in depth behind the controller/service guards: commercial detail rows of a
        // consignment-billing Purchase are derived from approved consignment allocations and
        // must not be mutated by any unguarded service or direct Eloquent write. Creation is
        // permitted so the conversion service can build the detail rows in the first place.
        static::updating(function (PurchaseDetail $detail) {
            $detail->assertMutableSource('updated');
        });

        static::deleting(function (PurchaseDetail $detail) {
            $detail->assertMutableSource('deleted');
        });
    }

    /**
     * @throws \Modules\Purchase\Exceptions\PurchaseSourceOperationNotAllowedException
     */
    protected function assertMutableSource(string $operation): void
    {
        if (!$this->purchase_id) {
            return;
        }

        // Resolve via a direct query rather than $this->purchase: the relation is not
        // guaranteed to be loaded here, and lazy-loading it would trip strict-mode
        // lazy-loading violations in callers that never needed the relation.
        $purchase = $this->relationLoaded('purchase')
            ? $this->getRelation('purchase')
            : Purchase::withArchived()->select(['id', 'reference', 'source_type'])->find($this->purchase_id);

        if ($purchase && $purchase->isConsignmentBilling()) {
            throw new \Modules\Purchase\Exceptions\PurchaseSourceOperationNotAllowedException(
                "Purchase detail #{$this->id} cannot be {$operation}: it belongs to consignment-billing Purchase #{$purchase->id} ({$purchase->reference}), whose commercial evidence is immutable."
            );
        }
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id', 'id');
    }

    public function getPriceAttribute($value): float|int
    {
        return $value;
    }

    public function getUnitPriceAttribute($value) {
        return $value;
    }

    public function getSubTotalAttribute($value) {
        return $value;
    }

    public function getProductDiscountAmountAttribute($value) {
        return $value;
    }

    public function getProductTaxAmountAttribute($value) {
        return $value;
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'tax_id', 'id');
    }

    /**
     * Relationship with ReceivedNoteDetail
     * A PurchaseDetail can have multiple ReceivedNoteDetails.
     */
    public function receivedNoteDetails(): HasMany
    {
        return $this->hasMany(ReceivedNoteDetail::class, 'po_detail_id');
    }

    /**
     * UOM normalization lines that reference this purchase detail.
     */
    public function uomNormalizationLines(): HasMany
    {
        return $this->hasMany(UomNormalizationLine::class, 'purchase_detail_id');
    }

    public function consignmentLineages(): HasMany
    {
        return $this->hasMany(\Modules\Consignment\Entities\ConsignmentPurchaseDetailLineage::class, 'purchase_detail_id');
    }
}
