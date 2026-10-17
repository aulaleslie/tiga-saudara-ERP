<?php

namespace Modules\Setting\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\People\Entities\Customer;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Currency\Entities\Currency;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class Setting extends BaseModel
{
    use HasFactory;

    protected static function newFactory()
    {
        return \Database\Factories\SettingFactory::new();
    }
    
    protected static function booted(): void
    {
        static::created(function (Setting $setting) {
            if (app()->runningUnitTests()) {
                return;
            }
            $locations = Location::query()
                ->where('setting_id', $setting->id)
                ->orderBy('name')
                ->orderBy('id')
                ->pluck('id');

            $now = now();
            $globalIndex = 1;

            $chunks = $locations->chunk(500);
            foreach ($chunks as $chunk) {
                $payload = $chunk->map(fn ($locationId) => [
                    'setting_id'  => $setting->id,
                    'location_id' => $locationId,
                    'is_enabled'  => true,
                    'position'    => $globalIndex++,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ])->all();
                SettingSaleLocation::insertOrIgnore($payload);
                \App\Support\SalesLocationResolver::forget($setting->id);
            }

            $paymentMethodIds = \Modules\Setting\Entities\PaymentMethod::pluck('id');
            $chunksPm = $paymentMethodIds->chunk(500);
            foreach ($chunksPm as $chunk) {
                $payloadPm = $chunk->map(fn ($pmId) => [
                    'setting_id'        => $setting->id,
                    'payment_method_id' => $pmId,
                    'is_enabled'        => false,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ])->all();
                \Modules\Setting\Entities\SettingPosPaymentMethod::insertOrIgnore($payloadPm);
            }
        });
    }

    protected $guarded = [];

    protected $attributes = [
        'pos_receipt_show_note' => false,
        'pos_receipt_show_return_history' => false,
        'pos_receipt_show_price_adjustments' => false,
        'pos_receipt_show_ppn' => false,
        'pos_receipt_show_signature_space' => true,
    ];

    protected $casts = [
        'is_pkp' => 'boolean',
        'pos_enabled' => 'boolean',
        'pos_transactions_enabled' => 'boolean',
        'pos_walk_in_customer_id' => 'integer',
        'row_total_rounding_increment' => 'float',
        'pos_receipt_show_note' => 'boolean',
        'pos_receipt_show_return_history' => 'boolean',
        'pos_receipt_show_price_adjustments' => 'boolean',
        'pos_receipt_show_ppn' => 'boolean',
        'pos_receipt_show_signature_space' => 'boolean',
    ];

    public function getPosReceiptShowNoteAttribute($value): bool
    {
        return (bool) $value;
    }

    public function getPosReceiptShowReturnHistoryAttribute($value): bool
    {
        return (bool) $value;
    }

    public function getPosReceiptShowPriceAdjustmentsAttribute($value): bool
    {
        return (bool) $value;
    }

    public function getPosReceiptShowPpnAttribute($value): bool
    {
        return (bool) $value;
    }

    public function getPosReceiptShowSignatureSpaceAttribute($value): bool
    {
        return $value === null ? true : (bool) $value;
    }

    public function getReceiptVisibility(): array
    {
        return [
            'show_note' => (bool) ($this->pos_receipt_show_note ?? false),
            'show_return_history' => (bool) ($this->pos_receipt_show_return_history ?? false),
            'show_price_adjustments' => (bool) ($this->pos_receipt_show_price_adjustments ?? false),
            'show_ppn' => (bool) ($this->pos_receipt_show_ppn ?? false),
            'show_signature_space' => $this->pos_receipt_show_signature_space ?? true,
            'pos_receipt_show_note' => (bool) ($this->pos_receipt_show_note ?? false),
            'pos_receipt_show_return_history' => (bool) ($this->pos_receipt_show_return_history ?? false),
            'pos_receipt_show_price_adjustments' => (bool) ($this->pos_receipt_show_price_adjustments ?? false),
            'pos_receipt_show_ppn' => (bool) ($this->pos_receipt_show_ppn ?? false),
            'pos_receipt_show_signature_space' => $this->pos_receipt_show_signature_space ?? true,
        ];
    }

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

    public function saleLocations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'setting_sale_locations')
            ->withTimestamps()
            ->withPivot('is_enabled')
            ->wherePivot('is_enabled', true);
    }

    public function saleLocationAssignments(): HasMany
    {
        return $this->hasMany(SettingSaleLocation::class);
    }

    public function posWalkInCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'pos_walk_in_customer_id');
    }

    public function posPaymentMethods(): BelongsToMany
    {
        return $this->belongsToMany(PaymentMethod::class, 'setting_pos_payment_methods')
            ->using(SettingPosPaymentMethod::class)
            ->withTimestamps()
            ->withPivot('is_enabled');
    }

}
