<?php

namespace Modules\Expense\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Tax;

class ExpenseDetail extends BaseModel
{
    protected $fillable = [
        'expense_id',
        'name',
        'tax_id',
        'amount',
    ];

    /**
     * Parent expense relationship
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /**
     * Tax relationship
     */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
