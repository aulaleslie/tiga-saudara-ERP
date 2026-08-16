<?php

namespace Modules\Sale\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;

class SaleBundleItem extends BaseModel
{
    protected $guarded = [];
    
    protected array $uppercaseExcept = ['line_group_key'];

    protected static function boot()
    {
        parent::boot();

        static::saving(function (SaleBundleItem $item) {
            if (is_null($item->sale_detail_id) && is_null($item->line_group_key)) {
                // If it's not linked to a parent line, it MUST have a line_group_key 
                // to support deterministic dispatch aggregation and rendering.
                $item->line_group_key = 'standalone-' . ($item->bundle_id ?? '0') . '-' . ($item->product_id ?? '0');
            }
        });
    }

    protected $casts = [
        'price' => 'decimal:2',
        'informational_item_price' => 'decimal:2',
        'sub_total' => 'decimal:2',
        'tax_amount' => 'decimal:2',
    ];

    /**
     * Relationship to the sale detail this bundle item belongs to.
     */
    public function saleDetail(): BelongsTo
    {
        return $this->belongsTo(SaleDetails::class, 'sale_detail_id', 'id');
    }

    /**
     * Relationship to the parent sale.
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id', 'id');
    }

    /**
     * Relationship to the bundled product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    /**
     * Relationship to the tax.
     */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(\Modules\Setting\Entities\Tax::class, 'tax_id', 'id');
    }

    /**
     * Get the resolved tax_id: use parent sale detail inheritance first,
     * then fallback to the bundle item's own tax_id.
     */
    public function getInheritedTaxIdAttribute(): ?int
    {
        if ($this->sale_detail_id) {
            return $this->saleDetail?->tax_id;
        }

        return (int) $this->tax_id ?: null;
    }

    /**
     * Get the resolved tax_amount: use parent sale detail inheritance first,
     * then fallback to the bundle item's own tax_amount.
     */
    public function getResolvedTaxAmountAttribute(): float
    {
        if ($this->sale_detail_id) {
            // Inherit from parent: in standard Sales, bundle items are usually considered
            // part of the parent's tax calculation, so they might not have their own tax amount
            // record. But for standalone rows, they MUST have it.
            // Currently, saleDetail->product_tax_amount is the TOTAL tax for the whole parent line.
            // For now, if linked, we return 0 (or we could try to apportion it, but that's complex).
            // STANDALONE rows MUST use their own tax_amount.
            return 0;
        }

        return (float) $this->tax_amount;
    }
}
