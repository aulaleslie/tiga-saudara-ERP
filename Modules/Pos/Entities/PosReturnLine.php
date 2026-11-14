<?php

namespace Modules\Pos\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;

class PosReturnLine extends Model
{
    use HasFactory;

    public const STOCK_BEHAVIOR_MANAGED = 'stock_managed';
    public const STOCK_BEHAVIOR_STOCKLESS = 'stockless';

    public const RESOLUTION_NONE = 'none';
    public const RESOLUTION_PRODUCT_REPLACEMENT = 'product_replacement';
    public const RESOLUTION_CASH_RETURN = 'cash_return';

    public const BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE = 'whole_bundle';
    public const BUNDLE_RETURN_SCOPE_PARENT_ONLY = 'parent_only';

    protected $fillable = [
        'pos_return_id',
        'pos_checkout_sale_id',
        'pos_transaction_line_id',
        'resolution',
        'sale_return_id',
        'sale_return_detail_id',
        'sale_id',
        'sale_detail_id',
        'dispatch_detail_id',
        'source_setting_id',
        'source_location_id',
        'tax_id',
        'product_id',
        'product_name',
        'product_code',
        'quantity',
        'unit_price',
        'line_total',
        'expected_cash_amount',
        'serial_number_ids',
        'returned_serial_id',
        'replacement_serial_id',
        'bundle_group_key',
        'bundle_parent_sale_detail_id',
        'bundle_return_scope',
        'bundle_quantity',
        'component_quantity_per_bundle',
        'stock_behavior',
        'replacement_product_id',
        'replacement_quantity',
        'line_meta',
    ];

    protected $casts = [
        'serial_number_ids' => 'array',
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'expected_cash_amount' => 'decimal:2',
        'bundle_quantity' => 'decimal:4',
        'component_quantity_per_bundle' => 'decimal:4',
        'replacement_quantity' => 'decimal:4',
        'line_meta' => 'array',
    ];

    public function posReturn()
    {
        return $this->belongsTo(PosReturn::class);
    }

    public function saleReturn()
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleReturnDetail()
    {
        return $this->belongsTo(SaleReturnDetail::class);
    }

    public function sale()
    {
        return $this->belongsTo(\Modules\Sale\Entities\Sale::class);
    }

    public function saleDetail()
    {
        return $this->belongsTo(\Modules\Sale\Entities\SaleDetails::class, 'sale_detail_id');
    }

    public function product()
    {
        return $this->belongsTo(\Modules\Product\Entities\Product::class);
    }

    public function returnedSerial()
    {
        return $this->belongsTo(\Modules\Product\Entities\ProductSerialNumber::class, 'returned_serial_id');
    }

    public function replacementSerial()
    {
        return $this->belongsTo(\Modules\Product\Entities\ProductSerialNumber::class, 'replacement_serial_id');
    }

    public function posTransactionLine()
    {
        return $this->belongsTo(\Modules\Pos\Entities\PosTransactionLine::class, 'pos_transaction_line_id');
    }

    public function bundleParentSaleDetail()
    {
        return $this->belongsTo(\Modules\Sale\Entities\SaleDetails::class, 'bundle_parent_sale_detail_id');
    }

    /**
     * Determine whether a POS return line (or draft line payload array) represents
     * a bundle component rather than a bundle parent or standalone product.
     */
    public static function isComponentLine(self|array $line, ?SaleDetails $carrierDetail = null): bool
    {
        if (is_array($line)) {
            if (!empty($line['__synthesized_component'])) {
                return true;
            }

            $compQtyPerBundle = $line['component_quantity_per_bundle'] ?? $line['__component_quantity_per_bundle'] ?? null;
            if ($compQtyPerBundle !== null && (float) $compQtyPerBundle > 0) {
                return true;
            }

            $parentSaleDetailId = (int) ($line['bundle_parent_sale_detail_id'] ?? $line['__bundle_parent_sale_detail_id'] ?? 0);
            if ($parentSaleDetailId <= 0) {
                return false;
            }

            $saleDetailId = (int) ($line['sale_detail_id'] ?? 0);
            if ($saleDetailId > 0 && $parentSaleDetailId !== $saleDetailId) {
                return true;
            }

            $productId = (int) ($line['__component_product_id'] ?? $line['product_id'] ?? 0);
            if ($productId > 0) {
                $carrierProductId = $carrierDetail
                    ? (int) $carrierDetail->product_id
                    : ($saleDetailId > 0 ? (int) (SaleDetails::whereKey($saleDetailId)->value('product_id') ?? 0) : 0);

                if ($carrierProductId > 0 && $productId !== $carrierProductId) {
                    return true;
                }
            }

            return false;
        }

        if ($line->component_quantity_per_bundle !== null && (float) $line->component_quantity_per_bundle > 0) {
            return true;
        }

        $parentSaleDetailId = (int) ($line->bundle_parent_sale_detail_id ?? 0);
        if ($parentSaleDetailId <= 0) {
            return false;
        }

        $saleDetailId = (int) ($line->sale_detail_id ?? 0);
        if ($saleDetailId > 0 && $parentSaleDetailId !== $saleDetailId) {
            return true;
        }

        $productId = (int) ($line->product_id ?? 0);
        if ($productId > 0) {
            $carrier = $carrierDetail ?? ($line->relationLoaded('saleDetail') ? $line->saleDetail : null);
            $carrierProductId = $carrier
                ? (int) $carrier->product_id
                : ($saleDetailId > 0 ? (int) (SaleDetails::whereKey($saleDetailId)->value('product_id') ?? 0) : 0);

            if ($carrierProductId > 0 && $productId !== $carrierProductId) {
                return true;
            }
        }

        return false;
    }
}
