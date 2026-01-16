<?php

namespace Modules\PurchasesReturn\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Purchase\Entities\Purchase;

class PurchaseReturnItemSettlement extends BaseModel
{
    protected $table = 'purchase_return_item_settlements';
    protected $guarded = [];

    protected $casts = [
        'nominal' => 'decimal:2',
    ];

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function detail(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturnDetail::class, 'purchase_return_detail_id');
    }

    public function serialNumber(): BelongsTo
    {
        return $this->belongsTo(ProductSerialNumber::class, 'product_serial_number_id');
    }

    public function targetPurchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'target_purchase_id');
    }
}
