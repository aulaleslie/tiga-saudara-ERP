<?php

namespace Modules\Purchase\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Setting;

class PurchaseReceivingCompletion extends BaseModel
{
    protected $table = 'purchase_receiving_completions';

    protected array $uppercaseExcept = [
        'reason',
    ];

    protected $fillable = [
        'setting_id',
        'purchase_id',
        'actor_user_id',
        'reason',
        'source_snapshot',
        'final_snapshot',
        'financial_before_after',
    ];

    protected $casts = [
        'source_snapshot' => 'array',
        'final_snapshot' => 'array',
        'financial_before_after' => 'array',
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
