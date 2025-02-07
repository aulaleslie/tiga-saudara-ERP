<?php

namespace Modules\Purchase\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;

class PaymentTerm extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'payment_terms';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
        'setting_id',
        'name',
        'longevity',
    ];

    /**
     * Get the setting associated with the payment term.
     */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id', 'id');
    }
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'payment_term_id', 'id');
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class, 'payment_term_id', 'id');
    }
}
