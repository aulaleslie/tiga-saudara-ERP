<?php

namespace Modules\Setting\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends BaseModel
{
    protected $fillable = ['name', 'coa_id'];

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'coa_id');
    }
}
