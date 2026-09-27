<?php

namespace Modules\Purchase\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;

class PaymentTerm extends BaseModel
{

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
        'name',
        'longevity',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeEligible($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'payment_term_id', 'id');
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class, 'payment_term_id', 'id');
    }

    public static function defaultCodTerm(): ?self
    {
        $directMatch = static::query()
            ->where(function ($query) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%cod%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%cash%on%delivery%']);
            })
            ->orderBy('id')
            ->first();

        if ($directMatch) {
            return $directMatch;
        }

        return static::query()
            ->where('longevity', 0)
            ->orderBy('id')
            ->first();
    }

    public static function defaultCodTermId(): ?int
    {
        return static::defaultCodTerm()?->id;
    }

    public static function customTerm(): ?self
    {
        return static::query()
            ->whereRaw('LOWER(name) LIKE ?', ['%custom%'])
            ->orderBy('id')
            ->first();
    }

    public static function customTermId(): ?int
    {
        return static::customTerm()?->id;
    }
}
