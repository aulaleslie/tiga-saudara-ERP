<?php

namespace Modules\Setting\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Currency\Entities\Currency;
use Modules\Purchase\Entities\PaymentTerm;

class Setting extends BaseModel
{
    protected $guarded = [];

    protected $with = ['currency'];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'default_currency_id', 'id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_setting', 'setting_id', 'user_id')
            ->withPivot('role_id');
    }

    public function paymentTerms(): HasMany
    {
        return $this->hasMany(PaymentTerm::class, 'setting_id', 'id');
    }
}
