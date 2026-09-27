<?php

namespace Modules\Purchase\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Setting;

class PurchaseCorrection extends BaseModel
{
    protected $table = 'purchase_corrections';

    protected array $uppercaseExcept = [
        'reason',
    ];

    protected $fillable = [
        'setting_id',
        'purchase_id',
        'actor_user_id',
        'reason',
        'field_corrections',
        'payment_before_after',
        'recalculation_result',
    ];

    protected $casts = [
        'field_corrections' => 'array',
        'payment_before_after' => 'array',
        'recalculation_result' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
