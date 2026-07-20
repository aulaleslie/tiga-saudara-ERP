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

    public function getCanonicalNameAttribute(): string
    {
        $customerName = filled($this->customer_name) ? trim($this->customer_name) : null;
        if ($customerName) {
            return $customerName;
        }

        $contactName = filled($this->contact_name) ? trim($this->contact_name) : null;
        if ($contactName) {
            return $contactName;
        }

        return '-';
    }

    public function getDisplayNameAttribute(): string
    {
        $companyName = filled($this->company_name) ? trim($this->company_name) : null;
        $customerName = filled($this->customer_name) ? trim($this->customer_name) : null;
        $contactName = filled($this->contact_name) ? trim($this->contact_name) : null;

        $company = $companyName ?: $customerName;

        if ($contactName && $company && strcasecmp($contactName, $company) !== 0) {
            return "{$contactName} - {$company}";
        }

        if ($contactName) {
            return $contactName;
        }

        if ($company) {
            return $company;
        }

        return '-';
    }
}
