<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

class PosCheckoutSale extends BaseModel
{
    protected bool $uppercaseAllText = false;

    protected $table = 'pos_checkout_sales';

    protected $fillable = [
        'pos_checkout_id',
        'split_key',
        'source_setting_id',
        'source_location_id',
        'tax_bucket',
        'sale_id',
        'sale_payment_id',
        'dispatch_ids',
        'subtotal',
        'tax_total',
        'grand_total',
        'paid_total',
    ];

    protected $casts = [
        'dispatch_ids' => 'array',
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'paid_total' => 'decimal:2',
    ];

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(PosCheckout::class, 'pos_checkout_id');
    }

    public function sourceSetting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'source_setting_id');
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function salePayment(): BelongsTo
    {
        return $this->belongsTo(SalePayment::class, 'sale_payment_id');
    }
}
