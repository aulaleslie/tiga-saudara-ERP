<?php

namespace Modules\People\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\People\Database\factories\CustomerFactory;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Setting\Entities\Setting;

class Customer extends BaseModel
{

    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
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
