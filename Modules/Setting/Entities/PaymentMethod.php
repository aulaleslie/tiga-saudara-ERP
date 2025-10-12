<?php

namespace Modules\Setting\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends BaseModel
{
    protected $fillable = ['name', 'coa_id', 'is_cash', 'is_available_in_pos'];

    protected $casts = [
        'is_cash' => 'boolean',
        'is_available_in_pos' => 'boolean',
    ];

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'coa_id');
    }
}
