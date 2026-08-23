<?php

namespace Modules\Setting\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends BaseModel
{
    protected $fillable = ['name', 'coa_id', 'is_cash', 'requires_reference', 'is_active'];

    protected $casts = [
        'is_cash' => 'boolean',
        'requires_reference' => 'boolean',
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

    protected static function booted(): void
    {
        static::created(function (PaymentMethod $paymentMethod) {
            if (app()->runningUnitTests()) {
                return;
            }
            
            $settings = \Modules\Setting\Entities\Setting::pluck('id');
            $payload = $settings->map(function ($settingId) use ($paymentMethod) {
                return [
                    'setting_id' => $settingId,
                    'payment_method_id' => $paymentMethod->id,
                    'is_enabled' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })->toArray();

            if (!empty($payload)) {
                \Modules\Setting\Entities\SettingPosPaymentMethod::insertOrIgnore($payload);
            }
        });
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'coa_id');
    }

    public function posSettings(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Setting::class, 'setting_pos_payment_methods')
            ->using(SettingPosPaymentMethod::class)
            ->withTimestamps()
            ->withPivot('is_enabled');
    }
}
