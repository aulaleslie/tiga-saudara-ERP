<?php

namespace Modules\PurchasesReturn\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;

class PurchaseReturnDetail extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'price'                   => 'decimal:2',
        'unit_price'              => 'decimal:2',
        'sub_total'               => 'decimal:2',
        'product_discount_amount' => 'decimal:2',
        'product_tax_amount'      => 'decimal:2',
        'serial_number_ids'       => 'array',
        'location_id'             => 'integer',
        'settlement_nominal'      => 'decimal:2',
        'settlement_target_purchase_id' => 'integer',
    ];

    const METHOD_PRODUCT_REPAIR = 'PRODUCT_REPAIR';
    const METHOD_BROKEN_STOCK = 'BROKEN_STOCK';
    const METHOD_MODIFY_PURCHASE = 'MODIFY_PURCHASE';
    const METHOD_CREDIT = 'CREDIT';
    const METHOD_CASH = 'CASH';

    public static function settlementMethods(): array
    {
        return [
            self::METHOD_PRODUCT_REPAIR => 'Perbaikan Produk',
            self::METHOD_BROKEN_STOCK   => 'Kembali Barang Rusak',
            self::METHOD_MODIFY_PURCHASE => 'Ubah Nota Pembelian',
            self::METHOD_CREDIT         => 'Simpan Sebagai Kredit',
            self::METHOD_CASH           => 'Pengembalian Tunai',
        ];
    }

    public function settlementItems()
    {
        return $this->hasMany(PurchaseReturnItemSettlement::class, 'purchase_return_detail_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\Modules\Setting\Entities\Location::class, 'location_id', 'id');
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id', 'id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'po_id', 'id');
    }

    public function targetPurchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'settlement_target_purchase_id', 'id');
    }

    /**
     * Get the serial numbers associated with the return detail.
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
