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
        'purchase_unit_id',
        'product_unit_conversion_id',
        'quantity',
        'entered_quantity',
        'tax_id',
        'unit_price',
        'entered_unit_price',
        'entered_product_discount_amount',
        'conversion_factor',
        'unit_name',
        'base_unit_name',
        'product_discount_type',
        'sub_total',
        'product_discount_amount',
        'product_name',
        'product_code',
        'price',
        'product_tax_amount',
        'pricing_source',
    ];

    protected $casts = [
        // Quantity is decimal to support fractional, weight-based units (e.g. 23.7 KG).
        'quantity' => 'decimal:3',
        'entered_quantity' => 'decimal:3',
        'unit_price' => 'decimal:6',
        'price' => 'decimal:6',
        'entered_unit_price' => 'decimal:2',
        'entered_product_discount_amount' => 'decimal:2',
        'conversion_factor' => 'decimal:6',
        'pricing_source' => 'string',
    ];

    /**
     * BaseModel uppercases every string attribute on write. pricing_source is a
     * lowercase sentinel compared case-sensitively by the pricing/rounding code,
     * so it must be stored verbatim.
     */
    public function setPricingSourceAttribute($value): void
    {
        $this->attributes['pricing_source'] = $value !== null ? strtolower((string) $value) : null;
    }

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

    public function purchaseUnit(): BelongsTo
    {
        return $this->belongsTo(\Modules\Setting\Entities\Unit::class, 'purchase_unit_id');
    }

    public function productUnitConversion(): BelongsTo
    {
        return $this->belongsTo(\Modules\Product\Entities\ProductUnitConversion::class, 'product_unit_conversion_id');
    }

    public function getEffectiveEnteredQuantityAttribute(): string|float
    {
        return $this->entered_quantity !== null ? $this->entered_quantity : $this->quantity;
    }

    public function getEffectiveEnteredUnitPriceAttribute(): string|float
    {
        return $this->entered_unit_price !== null ? $this->entered_unit_price : $this->unit_price;
    }

    public function getEffectiveEnteredProductDiscountAmountAttribute(): string|float
    {
        return $this->entered_product_discount_amount !== null ? $this->entered_product_discount_amount : $this->product_discount_amount;
    }

    public function getEffectiveConversionFactorAttribute(): string|float
    {
        return $this->conversion_factor !== null ? $this->conversion_factor : '1.000000';
    }

    public function getEffectiveUnitNameAttribute(): string
    {
        if ($this->unit_name) {
            return $this->unit_name;
        }

        if ($this->purchase_unit_id) {
            $unit = $this->relationLoaded('purchaseUnit')
                ? $this->purchaseUnit
                : \Modules\Setting\Entities\Unit::find($this->purchase_unit_id);
            if ($unit) {
                return $unit->name;
            }
        }

        return $this->effective_base_unit_name;
    }

    public function getEffectiveBaseUnitNameAttribute(): string
    {
        if ($this->base_unit_name) {
            return $this->base_unit_name;
        }

        if ($this->product) {
            if ($this->product->relationLoaded('baseUnit') && $this->product->baseUnit) {
                return $this->product->baseUnit->name;
            }
            if ($this->product->relationLoaded('unit') && $this->product->unit) {
                return $this->product->unit->name;
            }
            if ($this->product->base_unit_id) {
                $unit = \Modules\Setting\Entities\Unit::find($this->product->base_unit_id);
                if ($unit) {
                    return $unit->name;
                }
            }
            if ($this->product->unit_id) {
                $unit = \Modules\Setting\Entities\Unit::find($this->product->unit_id);
                if ($unit) {
                    return $unit->name;
                }
            }
        }

        return 'UNIT';
    }

    public function consignmentLineages(): HasMany
    {
        return $this->hasMany(\Modules\Consignment\Entities\ConsignmentPurchaseDetailLineage::class, 'purchase_detail_id');
    }
}
