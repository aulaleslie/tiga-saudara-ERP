<?php

namespace Modules\SalesReturn\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Tax;
use Modules\Pos\Entities\PosReturnLine;

class SaleReturnDetail extends BaseModel
{
    protected $fillable = [
        'sale_return_id',
        'pos_return_line_id',
        'sale_detail_id',
        'dispatch_detail_id',
        'product_id',
        'product_name',
        'product_code',
        'quantity',
        'price',
        'unit_price',
        'sub_total',
        'product_discount_amount',
        'product_discount_type',
        'product_tax_amount',
        'location_id',
        'tax_id',
        'serial_number_ids',
    ];

    protected $with = ['product'];

    protected $casts = [
        'quantity'                => 'integer',
        'price'                   => 'decimal:2',
        'unit_price'              => 'decimal:2',
        'sub_total'               => 'decimal:2',
        'product_discount_amount' => 'decimal:2',
        'product_tax_amount'      => 'decimal:2',
        'serial_number_ids'       => 'array',
    ];

    public function posReturnLine(): BelongsTo
    {
        return $this->belongsTo(PosReturnLine::class, 'pos_return_line_id');
    }

    const METHOD_PRODUCT_REPAIR = 'REPAIR';
    const METHOD_UNPROCESSED = 'UNPROCESSED';
    const METHOD_MODIFY_SALE = 'MODIFY_SALE';
    const METHOD_CUSTOMER_CREDIT = 'CUSTOMER_CREDIT';
    const METHOD_CASH_REFUND = 'CASH_REFUND';

    public static function settlementMethods(): array
    {
        return [
            self::METHOD_PRODUCT_REPAIR => 'Perbaikan Produk',
            self::METHOD_UNPROCESSED    => 'Belum Diproses',
            self::METHOD_MODIFY_SALE    => 'Ubah Nota Penjualan',
            self::METHOD_CUSTOMER_CREDIT => 'Simpan Sebagai Kredit',
            self::METHOD_CASH_REFUND    => 'Pengembalian Tunai',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class, 'sale_return_id', 'id');
    }

    public function saleDetail(): BelongsTo
    {
        return $this->belongsTo(SaleDetails::class, 'sale_detail_id', 'id');
    }

    public function dispatchDetail(): BelongsTo
    {
        return $this->belongsTo(DispatchDetail::class, 'dispatch_detail_id', 'id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'tax_id', 'id');
    }

    /**
     * Get the serial numbers associated with the return detail.
     *
     * Returns a collection of serial number strings.
     */
    public function getSerialNumbers()
    {
        if (empty($this->serial_number_ids)) {
            return collect();
        }

        return \Modules\Product\Entities\ProductSerialNumber::whereIn('id', $this->serial_number_ids)
            ->pluck('serial_number');
    }
}
