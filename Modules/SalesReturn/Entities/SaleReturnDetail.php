<?php

namespace Modules\SalesReturn\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Tax;

class SaleReturnDetail extends BaseModel
{
    protected $guarded = [];

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

    public static function selectableSettlementMethods(): array
    {
        return [
            self::METHOD_PRODUCT_REPAIR => 'Perbaikan/Pergantian Produk',
            self::METHOD_CASH_REFUND    => 'Pengembalian Tunai',
            self::METHOD_UNPROCESSED    => 'Tidak dapat diproses',
        ];
    }

    public function settlementItems(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SaleReturnItemSettlement::class, 'sale_return_detail_id');
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
}
