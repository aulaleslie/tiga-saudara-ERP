<?php

namespace Modules\Expense\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Tax;

class ExpenseDetail extends Model
{
    use HasFactory;

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
