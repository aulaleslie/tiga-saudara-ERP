<?php

namespace Modules\People\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\People\Database\factories\SupplierFactory;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Setting\Entities\Setting;

class Supplier extends BaseModel
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): SupplierFactory
    {
        return SupplierFactory::new();
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }
    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_term_id', 'id');
    }
}
